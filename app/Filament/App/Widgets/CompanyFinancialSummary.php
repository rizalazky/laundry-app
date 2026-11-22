<?php

namespace App\Filament\App\Widgets;

use App\Actions\Dashboard\GetDashboardFinancialSummary;
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
        $user = Auth::user();

        return $user !== null
            && $user->is_active
            && $user->tenant_id !== null
            && $user->hasPermissionTo('dashboard.view');
    }

    protected function getStats(): array
    {
        $user = Auth::user();

        if ($user === null) {
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
