<?php

namespace App\Actions\Accounting;

use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Journal;
use App\Models\JournalEntry;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class GenerateFinancialReports
{
    /**
     * @throws DomainException
     */
    public function generalJournal(
        int $tenantId,
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $branchId = null,
    ): Collection {
        $this->assertTenantAccess($tenantId);
        $this->assertBranchAccess($tenantId, $branchId);
        $this->assertDateRange($from, $to);

        return Journal::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereBetween('journal_date', [$from, $to])
            ->when($branchId !== null, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->with([
                'entries' => fn (Builder $query) => $query
                    ->withoutGlobalScope('tenant')
                    ->orderBy('id')
                    ->with(['account' => fn (Builder $accountQuery) => $accountQuery->withoutGlobalScope('tenant')]),
            ])
            ->orderBy('journal_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{
     *   account: ChartOfAccount,
     *   from: string,
     *   to: string,
     *   opening_balance: string,
     *   total_debit: string,
     *   total_credit: string,
     *   closing_balance: string,
     *   entries: Collection<int, JournalEntry>
     * }
     *
     * @throws DomainException
     */
    public function generalLedger(
        int $tenantId,
        int $accountId,
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $branchId = null,
    ): array {
        $this->assertTenantAccess($tenantId);
        $this->assertBranchAccess($tenantId, $branchId);
        $this->assertDateRange($from, $to);

        $account = ChartOfAccount::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->find($accountId);

        if ($account === null) {
            throw new DomainException('Akun tidak ditemukan untuk tenant ini.');
        }

        $baseQuery = $this->scopedEntries($tenantId, $branchId);

        $openingEntries = (clone $baseQuery)
            ->where('coa_id', $accountId)
            ->whereHas('journal', fn (Builder $query) => $query->where('journal_date', '<', $from))
            ->get(['debit', 'credit']);

        $openingBalance = $this->accountBalance($account, $openingEntries);
        $entries = (clone $baseQuery)
            ->where('coa_id', $accountId)
            ->whereHas('journal', fn (Builder $query) => $query->whereBetween('journal_date', [$from, $to]))
            ->with('journal')
            ->get()
            ->sortBy(fn (JournalEntry $entry): string => sprintf(
                '%s-%020d',
                $entry->journal?->journal_date?->format('Y-m-d H:i:s') ?? '',
                $entry->id,
            ))
            ->values();

        $runningBalance = $openingBalance;
        $totalDebit = BigDecimal::zero();
        $totalCredit = BigDecimal::zero();

        foreach ($entries as $entry) {
            $debit = BigDecimal::of((string) $entry->debit);
            $credit = BigDecimal::of((string) $entry->credit);
            $totalDebit = $totalDebit->plus($debit);
            $totalCredit = $totalCredit->plus($credit);
            $runningBalance = $this->applyMovement($account, $runningBalance, $debit, $credit);
            $entry->setAttribute('running_balance', $this->money($runningBalance));
        }

        return [
            'account' => $account,
            'from' => $from->toDateTimeString(),
            'to' => $to->toDateTimeString(),
            'opening_balance' => $this->money($openingBalance),
            'total_debit' => $this->money($totalDebit),
            'total_credit' => $this->money($totalCredit),
            'closing_balance' => $this->money($runningBalance),
            'entries' => $entries,
        ];
    }

    /**
     * @return array{
     *   from: string,
     *   to: string,
     *   revenue_accounts: Collection<int, array{account: ChartOfAccount, amount: string}>,
     *   expense_accounts: Collection<int, array{account: ChartOfAccount, amount: string}>,
     *   total_revenue: string,
     *   total_expenses: string,
     *   net_income: string
     * }
     *
     * @throws DomainException
     */
    public function incomeStatement(
        int $tenantId,
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $branchId = null,
    ): array {
        $this->assertTenantAccess($tenantId);
        $this->assertBranchAccess($tenantId, $branchId);
        $this->assertDateRange($from, $to);

        $totals = $this->totalsByAccount(
            $this->scopedEntries($tenantId, $branchId)
                ->whereHas('journal', fn (Builder $query) => $query->whereBetween('journal_date', [$from, $to])),
        );

        $accounts = ChartOfAccount::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereIn('account_type', ['revenue', 'expense'])
            ->orderBy('code')
            ->get();

        $revenueAccounts = collect();
        $expenseAccounts = collect();
        $totalRevenue = BigDecimal::zero();
        $totalExpenses = BigDecimal::zero();

        foreach ($accounts as $account) {
            $amounts = $totals->get($account->id, [
                'debit' => BigDecimal::zero(),
                'credit' => BigDecimal::zero(),
            ]);
            $balance = $this->accountBalanceFromTotals($account->account_type, $amounts['debit'], $amounts['credit']);

            if ($account->account_type === 'revenue') {
                $revenueAccounts->push(['account' => $account, 'amount' => $this->money($balance)]);
                $totalRevenue = $totalRevenue->plus($balance);
            } else {
                $expenseAccounts->push(['account' => $account, 'amount' => $this->money($balance)]);
                $totalExpenses = $totalExpenses->plus($balance);
            }
        }

        return [
            'from' => $from->toDateTimeString(),
            'to' => $to->toDateTimeString(),
            'revenue_accounts' => $revenueAccounts,
            'expense_accounts' => $expenseAccounts,
            'total_revenue' => $this->money($totalRevenue),
            'total_expenses' => $this->money($totalExpenses),
            'net_income' => $this->money($totalRevenue->minus($totalExpenses)),
        ];
    }

    /**
     * @return array{
     *   as_of: string,
     *   asset_accounts: Collection<int, array{account: ChartOfAccount, amount: string}>,
     *   liability_accounts: Collection<int, array{account: ChartOfAccount, amount: string}>,
     *   equity_accounts: Collection<int, array{account: ChartOfAccount, amount: string}>,
     *   current_year_earnings: string,
     *   total_assets: string,
     *   total_liabilities: string,
     *   total_equity: string,
     *   total_liabilities_and_equity: string,
     *   difference: string,
     *   is_balanced: bool
     * }
     *
     * @throws DomainException
     */
    public function balanceSheet(
        int $tenantId,
        CarbonInterface $asOf,
        ?int $branchId = null,
    ): array {
        $this->assertTenantAccess($tenantId);
        $this->assertBranchAccess($tenantId, $branchId);

        $totals = $this->totalsByAccount(
            $this->scopedEntries($tenantId, $branchId)
                ->whereHas('journal', fn (Builder $query) => $query->where('journal_date', '<=', $asOf)),
        );

        $accounts = ChartOfAccount::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereIn('account_type', ['asset', 'liability', 'equity'])
            ->orderBy('code')
            ->get();

        $assetAccounts = collect();
        $liabilityAccounts = collect();
        $equityAccounts = collect();
        $totalAssets = BigDecimal::zero();
        $totalLiabilities = BigDecimal::zero();
        $postedEquity = BigDecimal::zero();

        foreach ($accounts as $account) {
            $amounts = $totals->get($account->id, [
                'debit' => BigDecimal::zero(),
                'credit' => BigDecimal::zero(),
            ]);
            $balance = $this->accountBalanceFromTotals($account->account_type, $amounts['debit'], $amounts['credit']);
            $row = ['account' => $account, 'amount' => $this->money($balance)];

            match ($account->account_type) {
                'asset' => $assetAccounts->push($row),
                'liability' => $liabilityAccounts->push($row),
                'equity' => $equityAccounts->push($row),
            };

            if ($account->account_type === 'asset') {
                $totalAssets = $totalAssets->plus($balance);
            } elseif ($account->account_type === 'liability') {
                $totalLiabilities = $totalLiabilities->plus($balance);
            } else {
                $postedEquity = $postedEquity->plus($balance);
            }
        }

        // Revenue and expense accounts are temporary accounts. Include the current
        // fiscal year's result as an unposted "current earnings" equity component;
        // this does not create a journal or replace a formal year-end closing entry.
        $yearStart = Carbon::parse($asOf->format('Y-01-01 00:00:00'), $asOf->getTimezone());
        $currentYearResult = BigDecimal::of($this->incomeStatement(
            $tenantId,
            $yearStart,
            $asOf,
            $branchId,
        )['net_income']);

        $totalEquity = $postedEquity->plus($currentYearResult);
        $totalLiabilitiesAndEquity = $totalLiabilities->plus($totalEquity);
        $difference = $totalAssets->minus($totalLiabilitiesAndEquity);

        return [
            'as_of' => $asOf->toDateTimeString(),
            'asset_accounts' => $assetAccounts,
            'liability_accounts' => $liabilityAccounts,
            'equity_accounts' => $equityAccounts,
            'current_year_earnings' => $this->money($currentYearResult),
            'total_assets' => $this->money($totalAssets),
            'total_liabilities' => $this->money($totalLiabilities),
            'total_equity' => $this->money($totalEquity),
            'total_liabilities_and_equity' => $this->money($totalLiabilitiesAndEquity),
            'difference' => $this->money($difference),
            'is_balanced' => $difference->isZero(),
        ];
    }

    private function scopedEntries(int $tenantId, ?int $branchId): Builder
    {
        return JournalEntry::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereHas('journal', fn (Builder $query) => $query
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->when($branchId !== null, fn (Builder $journalQuery) => $journalQuery->where('branch_id', $branchId)));
    }

    /**
     * @return Collection<int, array{debit: BigDecimal, credit: BigDecimal}>
     */
    private function totalsByAccount(Builder $query): Collection
    {
        return $query
            ->selectRaw('coa_id, SUM(debit) as total_debit, SUM(credit) as total_credit')
            ->groupBy('coa_id')
            ->get()
            ->mapWithKeys(fn (JournalEntry $entry): array => [
                $entry->coa_id => [
                    'debit' => BigDecimal::of((string) $entry->total_debit),
                    'credit' => BigDecimal::of((string) $entry->total_credit),
                ],
            ]);
    }

    /**
     * @param  Collection<int, JournalEntry>  $entries
     */
    private function accountBalance(ChartOfAccount $account, Collection $entries): BigDecimal
    {
        $debit = BigDecimal::zero();
        $credit = BigDecimal::zero();

        foreach ($entries as $entry) {
            $debit = $debit->plus(BigDecimal::of((string) $entry->debit));
            $credit = $credit->plus(BigDecimal::of((string) $entry->credit));
        }

        return $this->accountBalanceFromTotals($account->account_type, $debit, $credit);
    }

    private function accountBalanceFromTotals(string $accountType, BigDecimal $debit, BigDecimal $credit): BigDecimal
    {
        return in_array($accountType, ['asset', 'expense'], true)
            ? $debit->minus($credit)
            : $credit->minus($debit);
    }

    private function applyMovement(
        ChartOfAccount $account,
        BigDecimal $balance,
        BigDecimal $debit,
        BigDecimal $credit,
    ): BigDecimal {
        return in_array($account->account_type, ['asset', 'expense'], true)
            ? $balance->plus($debit)->minus($credit)
            : $balance->plus($credit)->minus($debit);
    }

    private function money(BigDecimal $amount): string
    {
        return (string) $amount->toScale(2);
    }

    private function assertTenantAccess(int $tenantId): void
    {
        $authenticatedTenantId = Auth::user()?->tenant_id;

        if ($authenticatedTenantId !== null && (int) $authenticatedTenantId !== $tenantId) {
            throw new DomainException('Tenant laporan tidak sesuai dengan tenant pengguna.');
        }
    }

    private function assertBranchAccess(int $tenantId, ?int $branchId): void
    {
        $user = Auth::user();

        if ($branchId === null) {
            if ($user !== null && ! in_array($user->role, ['tenant_owner', 'super_admin'], true)) {
                throw new DomainException('Pengguna cabang hanya dapat melihat laporan untuk cabang yang ditugaskan.');
            }

            return;
        }

        $branchExists = Branch::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereKey($branchId)
            ->exists();

        if (! $branchExists) {
            throw new DomainException('Cabang laporan tidak ditemukan atau bukan milik tenant ini.');
        }

        if (
            $user !== null
            && ! in_array($user->role, ['tenant_owner', 'super_admin'], true)
            && ! $user->branches()
                ->whereKey($branchId)
                ->where('branches.tenant_id', $tenantId)
                ->where('branches.is_active', true)
                ->exists()
        ) {
            throw new DomainException('Pengguna tidak memiliki akses ke cabang laporan ini.');
        }
    }

    private function assertDateRange(CarbonInterface $from, CarbonInterface $to): void
    {
        if ($from->greaterThan($to)) {
            throw new DomainException('Tanggal awal laporan tidak boleh melewati tanggal akhir.');
        }
    }
}
