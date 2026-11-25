<?php

namespace App\Filament\App\Pages;

use App\Actions\Analytics\GetBusinessPerformanceAnalytics;
use App\Models\Branch;
use Carbon\CarbonImmutable;
use DomainException;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use BackedEnum;
use UnitEnum;

class BusinessPerformance extends Page
{
    protected string $view = 'filament.app.pages.business-performance';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?string $navigationLabel = 'Performa Usaha';

    protected static string|UnitEnum|null $navigationGroup = 'Keuangan & Laporan';

    protected static ?int $navigationSort = 11;

    protected static ?string $slug = 'performa-usaha';

    public string $fromDate = '';

    public string $toDate = '';

    public string $branchId = '';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user !== null
            && $user->tenant_id !== null
            && $user->hasPermissionTo('reports.view');
    }

    public function mount(): void
    {
        $today = CarbonImmutable::today();

        $this->fromDate = $today->startOfMonth()->toDateString();
        $this->toDate = $today->toDateString();
        $this->branchId = Auth::user()?->role === 'tenant_owner'
            ? 'all'
            : (string) (Filament::getTenant()?->getKey() ?? '');
    }

    /**
     * @return array<int|string, string>
     */
    public function getBranchOptions(): array
    {
        abort_unless(static::canAccess(), 403);

        return Branch::query()
            ->where('tenant_id', Auth::user()?->tenant_id)
            ->where('is_active', true)
            ->where('is_workshop', false)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function getAnalytics(): array
    {
        abort_unless(static::canAccess(), 403);

        try {
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $this->fromDate);
            $to = CarbonImmutable::createFromFormat('!Y-m-d', $this->toDate);

            if ($from === false || $to === false) {
                return ['error' => 'Pilih tanggal laporan yang valid.'];
            }

            $from = $from->startOfDay();
            $to = $to->endOfDay();

            if ($from->greaterThan($to)) {
                return ['error' => 'Tanggal awal tidak boleh melewati tanggal akhir.'];
            }

            $user = Auth::user();
            $branchId = $user?->role === 'tenant_owner'
                ? ($this->branchId === 'all' ? null : (int) $this->branchId)
                : (int) (Filament::getTenant()?->getKey() ?? 0);

            return app(GetBusinessPerformanceAnalytics::class)->handle(
                (int) $user->tenant_id,
                $from,
                $to,
                $branchId,
            );
        } catch (DomainException $exception) {
            return ['error' => $exception->getMessage()];
        } catch (\InvalidArgumentException) {
            return ['error' => 'Pilih tanggal laporan yang valid.'];
        }
    }

    public function formatMoney(string|int|float|null $amount): string
    {
        $value = (string) ($amount ?? '0');
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '00');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $whole) ?? $whole;

        return ($negative ? '-' : '').'Rp '.$whole.','.$fraction;
    }
}
