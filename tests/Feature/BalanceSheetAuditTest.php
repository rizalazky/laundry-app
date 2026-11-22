<?php

namespace Tests\Feature;

use App\Actions\Accounting\CreateJournal;
use App\Actions\Accounting\GenerateFinancialReports;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BalanceSheetAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_balance_sheet_reconciles_assets_liabilities_equity_and_current_year_earnings(): void
    {
        $tenant = Tenant::factory()->create();

        $this->postJournal($tenant->id, '2026-01-01 09:00:00', 'Saldo awal', [
            ['account_code' => '1000', 'debit' => '1000', 'credit' => '0'],
            ['account_code' => '2000', 'debit' => '0', 'credit' => '300'],
            ['account_code' => '3000', 'debit' => '0', 'credit' => '700'],
        ]);
        $this->postJournal($tenant->id, '2026-01-10 09:00:00', 'Pendapatan laundry', [
            ['account_code' => '1000', 'debit' => '400', 'credit' => '0'],
            ['account_code' => '4000', 'debit' => '0', 'credit' => '400'],
        ]);
        $this->postJournal($tenant->id, '2026-01-11 09:00:00', 'Biaya operasional', [
            ['account_code' => '5400', 'debit' => '150', 'credit' => '0'],
            ['account_code' => '1000', 'debit' => '0', 'credit' => '150'],
        ]);

        $this->actingAs(User::factory()->for($tenant)->withRole('tenant_owner')->create());

        $report = app(GenerateFinancialReports::class)->balanceSheet(
            $tenant->id,
            CarbonImmutable::parse('2026-01-31 23:59:59'),
        );

        $this->assertSame('1250.00', $report['total_assets']);
        $this->assertSame('300.00', $report['total_liabilities']);
        $this->assertSame('950.00', $report['total_equity']);
        $this->assertSame('250.00', $report['current_year_earnings']);
        $this->assertSame('1250.00', $report['total_liabilities_and_equity']);
        $this->assertSame('0.00', $report['difference']);
        $this->assertTrue($report['is_balanced']);
    }

    public function test_balance_sheet_flags_a_ledger_that_no_longer_balances(): void
    {
        $tenant = Tenant::factory()->create();

        $this->postJournal($tenant->id, '2026-01-10 09:00:00', 'Setoran modal', [
            ['account_code' => '1000', 'debit' => '1000', 'credit' => '0'],
            ['account_code' => '3000', 'debit' => '0', 'credit' => '1000'],
        ]);

        $equity = ChartOfAccount::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('code', '3000')
            ->firstOrFail();

        JournalEntry::query()
            ->where('tenant_id', $tenant->id)
            ->where('coa_id', $equity->id)
            ->update(['credit' => '900.00']);

        $this->actingAs(User::factory()->for($tenant)->withRole('tenant_owner')->create());

        $report = app(GenerateFinancialReports::class)->balanceSheet(
            $tenant->id,
            CarbonImmutable::parse('2026-01-31 23:59:59'),
        );

        $this->assertSame('1000.00', $report['total_assets']);
        $this->assertSame('900.00', $report['total_equity']);
        $this->assertSame('100.00', $report['difference']);
        $this->assertFalse($report['is_balanced']);
    }

    /**
     * @param  list<array{account_code: string, debit: string, credit: string}>  $entries
     */
    private function postJournal(int $tenantId, string $date, string $description, array $entries): void
    {
        app(CreateJournal::class)->handle(
            tenantId: $tenantId,
            branchId: null,
            journalDate: CarbonImmutable::parse($date),
            referenceType: null,
            referenceId: null,
            description: $description,
            entries: $entries,
        );
    }
}
