<?php

namespace App\Actions\Investors;

use App\Actions\Accounting\GenerateFinancialReports;
use App\Models\Branch;
use App\Models\Investor;
use App\Models\ProfitSharing;
use App\Models\Tenant;
use App\Services\EntitlementService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class GenerateProfitSharings
{
    /**
     * Generate immutable monthly profit snapshots for every active investor in a branch.
     *
     * @return list<ProfitSharing>
     */
    public function handle(int $tenantId, int $branchId, string $periodMonth): array
    {
        $user = Auth::user();

        if ($user === null || ! $user->is_active || $user->role !== 'tenant_owner'
            || (int) $user->tenant_id !== $tenantId || ! $user->hasPermissionTo('investors.manage')) {
            throw new DomainException('Hanya pemilik tenant yang dapat membuat perhitungan bagi hasil.');
        }

        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            throw new DomainException('Tenant tidak ditemukan.');
        }

        app(EntitlementService::class)->assertCanWrite($tenant);

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodMonth)) {
            throw new DomainException('Periode harus menggunakan format YYYY-MM.');
        }

        $period = CarbonImmutable::createFromFormat('!Y-m', $periodMonth);

        if ($period === false || $period->startOfMonth()->isAfter(now()->startOfMonth())) {
            throw new DomainException('Periode bagi hasil tidak boleh berada di masa depan.');
        }

        $branch = Branch::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->find($branchId);

        if ($branch === null) {
            throw new DomainException('Cabang tidak ditemukan atau tidak aktif.');
        }

        return DB::transaction(function () use ($tenantId, $branchId, $periodMonth, $period): array {
            $investors = Investor::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('branch_id', $branchId)
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($investors->isEmpty()) {
                throw new DomainException('Belum ada investor aktif pada cabang ini.');
            }

            $totalShare = $investors->reduce(
                fn (BigDecimal $total, Investor $investor): BigDecimal => $total->plus((string) $investor->share_percent),
                BigDecimal::zero(),
            );

            if ($totalShare->isGreaterThan('100')) {
                throw new DomainException('Total persentase investor aktif pada cabang tidak boleh melebihi 100%.');
            }

            if (ProfitSharing::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->whereIn('investor_id', $investors->modelKeys())
                ->where('period_month', $periodMonth)
                ->exists()) {
                throw new DomainException('Bagi hasil untuk satu atau lebih investor pada periode ini sudah pernah dibuat.');
            }

            $report = app(GenerateFinancialReports::class)->incomeStatement(
                $tenantId,
                $period->startOfMonth(),
                $period->endOfMonth(),
                $branchId,
            );

            $netProfit = BigDecimal::of($report['net_income'])->toScale(2);

            if (! $netProfit->isGreaterThan(0)) {
                throw new DomainException('Bagi hasil hanya dapat dibuat jika laba bersih periode bernilai positif.');
            }

            $created = [];

            foreach ($investors as $investor) {
                $payout = $netProfit
                    ->multipliedBy((string) $investor->share_percent)
                    ->dividedBy('100', 2, RoundingMode::HalfUp);

                $created[] = ProfitSharing::withoutGlobalScope('tenant')->create([
                    'tenant_id' => $tenantId,
                    'investor_id' => $investor->id,
                    'period_month' => $periodMonth,
                    'net_profit_base' => (string) $netProfit,
                    'share_percent_snapshot' => $investor->share_percent,
                    'payout_amount' => (string) $payout,
                    'status' => ProfitSharing::STATUS_DRAFT,
                ]);
            }

            return $created;
        });
    }
}
