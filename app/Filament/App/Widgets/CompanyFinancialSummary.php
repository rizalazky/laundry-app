<?php

namespace App\Filament\App\Widgets;

use App\Actions\Dashboard\GetDashboardFinancialSummary;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class CompanyFinancialSummary extends StatsOverviewWidget
{
    protected ?string $heading = 'Ringkasan Keuangan';

    protected ?string $description = 'Ringkasan bulan berjalan dan sisa tagihan aktif.';

    protected static ?int $sort = 0;

    protected GetDashboardFinancialSummary $financialSummary;

    public function boot(GetDashboardFinancialSummary $financialSummary): void
    {
        $this->financialSummary = $financialSummary;
    }

    public static function canView(): bool
    {
        $user = static::authenticatedActor();

        return $user !== null
            && $user->is_active
            && $user->tenant_id !== null
            && $user->hasPermissionTo('dashboard.view');
    }

    public function hydrate(): void
    {
        abort_unless(static::canView(), 403);
    }

    private static function authenticatedActor(): ?User
    {
        $authenticatedUserId = Auth::id();

        return $authenticatedUserId === null
            ? null
            : User::query()->find($authenticatedUserId);
    }

    protected function getStats(): array
    {
        $user = static::authenticatedActor();

        if ($user === null || ! $user->hasPermissionTo('dashboard.view')) {
            return [];
        }

        $summary = $this->financialSummary->handle($user);

        return [
            Stat::make('Omzet Bulan Ini', $this->formatCurrency($summary['monthly_turnover']))
                ->description('Total nilai order non-batal berdasarkan tanggal order')
                ->descriptionIcon('heroicon-m-chart-bar'),

            Stat::make('Pembayaran Bersih Bulan Ini', $this->formatCurrency($summary['monthly_net_payments']))
                ->description('Pembayaran masuk dikurangi refund')
                ->descriptionIcon('heroicon-m-banknotes'),

            Stat::make('Sisa Tagihan', $this->formatCurrency($summary['outstanding_balance']))
                ->description('Total tagihan order aktif yang belum lunas')
                ->descriptionIcon('heroicon-m-receipt-percent'),
        ];
    }

    private function formatCurrency(string $amount): string
    {
        $value = (float) $amount;
        $prefix = $value < 0 ? '-Rp ' : 'Rp ';

        return $prefix.number_format(abs($value), 0, ',', '.');
    }
}
