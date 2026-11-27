<?php

namespace App\Actions\Investors;

use App\Actions\Accounting\CreateJournal;
use App\Models\ChartOfAccount;
use App\Models\Investor;
use App\Models\ProfitSharing;
use App\Models\Tenant;
use App\Services\EntitlementService;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MarkProfitSharingPaid
{
    public function handle(int $tenantId, int $profitSharingId, string $paymentAccountCode): ProfitSharing
    {
        $user = Auth::user();

        if ($user === null || ! $user->is_active || $user->role !== 'tenant_owner'
            || (int) $user->tenant_id !== $tenantId || ! $user->hasPermissionTo('investors.manage')) {
            throw new DomainException('Hanya pemilik tenant yang dapat membayar bagi hasil investor.');
        }

        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            throw new DomainException('Tenant tidak ditemukan.');
        }

        app(EntitlementService::class)->assertCanWrite($tenant);

        return DB::transaction(function () use ($tenantId, $profitSharingId, $paymentAccountCode, $user): ProfitSharing {
            $sharing = ProfitSharing::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->find($profitSharingId);

            if ($sharing === null) {
                throw new DomainException('Data bagi hasil tidak ditemukan.');
            }

            if ($sharing->status === ProfitSharing::STATUS_PAID) {
                if ($sharing->journal_id !== null) {
                    return $sharing->load('journal');
                }

                throw new DomainException('Data bagi hasil berstatus lunas tanpa jurnal pembayaran.');
            }

            if ($sharing->status !== ProfitSharing::STATUS_DRAFT) {
                throw new DomainException('Status bagi hasil tidak valid untuk pembayaran.');
            }

            $investor = Investor::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->find($sharing->investor_id);

            if ($investor === null) {
                throw new DomainException('Investor tidak ditemukan pada tenant ini.');
            }

            $paymentAccount = ChartOfAccount::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->whereIn('account_type', ['asset'])
                ->whereIn('code', ['1000', '1010'])
                ->where('code', $paymentAccountCode)
                ->first();

            if ($paymentAccount === null) {
                throw new DomainException('Pilih akun Kas atau Bank yang aktif.');
            }

            $journal = app(CreateJournal::class)->handle(
                tenantId: $tenantId,
                branchId: $investor->branch_id,
                journalDate: now(),
                referenceType: 'investor_profit_sharing',
                referenceId: $sharing->id,
                description: 'Pembayaran bagi hasil investor '.$investor->name.' periode '.$sharing->period_month,
                entries: [
                    ['account_code' => '3300', 'debit' => $sharing->payout_amount, 'credit' => '0.00'],
                    ['account_code' => $paymentAccount->code, 'debit' => '0.00', 'credit' => $sharing->payout_amount],
                ],
                createdBy: $user->id,
            );

            $sharing->forceFill([
                'status' => ProfitSharing::STATUS_PAID,
                'paid_at' => now(),
                'journal_id' => $journal->id,
            ])->save();

            return $sharing->load('journal');
        });
    }
}
