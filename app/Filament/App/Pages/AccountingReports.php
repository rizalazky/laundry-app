<?php

namespace App\Filament\App\Pages;

use App\Actions\Accounting\GenerateFinancialReports;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use Carbon\CarbonImmutable;
use DomainException;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use UnitEnum;
use BackedEnum;

class AccountingReports extends Page
{
    protected string $view = 'filament.app.pages.accounting-reports';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationLabel = 'Laporan Akuntansi';

    protected static string|UnitEnum|null $navigationGroup = 'Keuangan & Laporan';
    protected static ?int $navigationSort = 10;


    protected static ?string $slug = 'laporan-akuntansi';

    public string $reportType = 'general-journal';

    public string $fromDate = '';

    public string $toDate = '';

    public string $accountId = '';

    /**
     * Owners may select "all" or one active branch. Other roles are always
     * restricted to the branch selected in the Filament tenant context.
     */
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
        $this->branchId = (string) (Filament::getTenant()?->getKey() ?? '');

        $this->accountId = (string) (ChartOfAccount::query()
            ->orderBy('code')
            ->value('id') ?? '');
    }

    /**
     * @return array<int|string, string>
     */
    public function getAccountOptions(): array
    {
        abort_unless(static::canAccess(), 403);

        return ChartOfAccount::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (ChartOfAccount $account): array => [
                $account->id => $account->code.' — '.$account->name,
            ])
            ->all();
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
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<string, mixed>|Illuminate\Support\Collection<int, \App\Models\Journal>
     */
    public function getReportData(): array|\Illuminate\Support\Collection
    {
        $allowedReports = ['general-journal', 'general-ledger', 'income-statement', 'balance-sheet', 'statement-of-equity'];

        if (! in_array($this->reportType, $allowedReports, true)) {
            return ['error' => 'Jenis laporan tidak valid.'];
        }

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

            $tenantId = (int) Auth::user()->tenant_id;
            $branchId = $this->effectiveBranchId();
            $reports = app(GenerateFinancialReports::class);

            return match ($this->reportType) {
                'general-journal' => $reports->generalJournal($tenantId, $from, $to, $branchId),
                'general-ledger' => $this->accountId !== ''
                    ? $reports->generalLedger($tenantId, (int) $this->accountId, $from, $to, $branchId)
                    : ['error' => 'Pilih akun untuk melihat buku besar.'],
                'income-statement' => $reports->incomeStatement($tenantId, $from, $to, $branchId),
                'balance-sheet' => $reports->balanceSheet($tenantId, $to, $branchId),
                'statement-of-equity' => $reports->statementOfChangesInEquity($tenantId, $from, $to, $branchId),
            };
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

    private function effectiveBranchId(): ?int
    {
        $user = Auth::user();

        if ($user?->role === 'tenant_owner') {
            return $this->branchId === 'all' ? null : (int) $this->branchId;
        }

        $currentBranchId = Filament::getTenant()?->getKey();

        return $currentBranchId === null ? null : (int) $currentBranchId;
    }
}
