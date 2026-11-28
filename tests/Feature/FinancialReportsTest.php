<?php

namespace Tests\Feature;

use App\Actions\Accounting\CreateJournal;
use App\Actions\Accounting\GenerateFinancialReports;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_general_journal_is_scoped_to_tenant_branch_and_period(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $otherBranch = Branch::factory()->for($tenant)->create();

        $this->postJournal($tenant->id, $branch->id, '2026-01-10 09:00:00', 'Jurnal dalam periode', [
            ['account_code' => '1000', 'debit' => '100000', 'credit' => '0'],
            ['account_code' => '3000', 'debit' => '0', 'credit' => '100000'],
        ]);
        $this->postJournal($tenant->id, $otherBranch->id, '2026-01-10 10:00:00', 'Jurnal cabang lain', [
            ['account_code' => '1000', 'debit' => '20000', 'credit' => '0'],
            ['account_code' => '3000', 'debit' => '0', 'credit' => '20000'],
        ]);
        $this->postJournal($otherTenant->id, null, '2026-01-10 11:00:00', 'Jurnal tenant lain', [
            ['account_code' => '1000', 'debit' => '30000', 'credit' => '0'],
            ['account_code' => '3000', 'debit' => '0', 'credit' => '30000'],
        ]);
        $this->postJournal($tenant->id, $branch->id, '2026-02-01 09:00:00', 'Jurnal di luar periode', [
            ['account_code' => '1000', 'debit' => '40000', 'credit' => '0'],
            ['account_code' => '3000', 'debit' => '0', 'credit' => '40000'],
        ]);

        $this->actingAs(User::factory()->for($tenant)->withRole('tenant_owner')->create());

        $journals = app(GenerateFinancialReports::class)->generalJournal(
            $tenant->id,
            CarbonImmutable::parse('2026-01-01 00:00:00'),
            CarbonImmutable::parse('2026-01-31 23:59:59'),
            $branch->id,
        );

        $this->assertCount(1, $journals);
        $this->assertSame('Jurnal dalam periode', $journals->first()->description);
        $this->assertCount(2, $journals->first()->entries);
        $this->assertNotNull($journals->first()->entries->first()->account);
    }

    public function test_general_ledger_calculates_opening_running_and_closing_balances(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $cash = ChartOfAccount::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('code', '1000')->firstOrFail();

        $this->postJournal($tenant->id, $branch->id, '2026-01-01 09:00:00', 'Saldo awal kas', [
            ['account_code' => '1000', 'debit' => '1000', 'credit' => '0'],
            ['account_code' => '3000', 'debit' => '0', 'credit' => '1000'],
        ]);
        $this->postJournal($tenant->id, $branch->id, '2026-01-10 09:00:00', 'Kas masuk', [
            ['account_code' => '1000', 'debit' => '200', 'credit' => '0'],
            ['account_code' => '4000', 'debit' => '0', 'credit' => '200'],
        ]);
        $this->postJournal($tenant->id, $branch->id, '2026-01-11 09:00:00', 'Kas keluar', [
            ['account_code' => '5400', 'debit' => '50', 'credit' => '0'],
            ['account_code' => '1000', 'debit' => '0', 'credit' => '50'],
        ]);

        $this->actingAs(User::factory()->for($tenant)->withRole('tenant_owner')->create());

        $ledger = app(GenerateFinancialReports::class)->generalLedger(
            $tenant->id,
            $cash->id,
            CarbonImmutable::parse('2026-01-10 00:00:00'),
            CarbonImmutable::parse('2026-01-31 23:59:59'),
            $branch->id,
        );

        $this->assertSame('1000.00', $ledger['opening_balance']);
        $this->assertSame('200.00', $ledger['total_debit']);
        $this->assertSame('50.00', $ledger['total_credit']);
        $this->assertSame('1150.00', $ledger['closing_balance']);
        $this->assertSame('1200.00', $ledger['entries'][0]->running_balance);
        $this->assertSame('1150.00', $ledger['entries'][1]->running_balance);
    }

    public function test_income_statement_groups_revenue_and_expenses_by_account(): void
    {
        $tenant = Tenant::factory()->create();

        $this->postJournal($tenant->id, null, '2026-01-10 09:00:00', 'Pendapatan laundry', [
            ['account_code' => '1000', 'debit' => '200000', 'credit' => '0'],
            ['account_code' => '4000', 'debit' => '0', 'credit' => '200000'],
        ]);
        $this->postJournal($tenant->id, null, '2026-01-11 09:00:00', 'Biaya operasional', [
            ['account_code' => '5400', 'debit' => '50000', 'credit' => '0'],
            ['account_code' => '1000', 'debit' => '0', 'credit' => '50000'],
        ]);

        $this->actingAs(User::factory()->for($tenant)->withRole('tenant_owner')->create());

        $report = app(GenerateFinancialReports::class)->incomeStatement(
            $tenant->id,
            CarbonImmutable::parse('2026-01-01 00:00:00'),
            CarbonImmutable::parse('2026-01-31 23:59:59'),
        );

        $this->assertSame('200000.00', $report['total_revenue']);
        $this->assertSame('50000.00', $report['total_expenses']);
        $this->assertSame('150000.00', $report['net_income']);
        $this->assertSame('200000.00', $report['revenue_accounts']->firstWhere('account.code', '4000')['amount']);
        $this->assertSame('50000.00', $report['expense_accounts']->firstWhere('account.code', '5400')['amount']);
    }

    public function test_balance_sheet_includes_current_year_earnings_and_reconciles(): void
    {
        $tenant = Tenant::factory()->create();

        $this->postJournal($tenant->id, null, '2026-01-01 09:00:00', 'Setoran modal', [
            ['account_code' => '1000', 'debit' => '1000000', 'credit' => '0'],
            ['account_code' => '3000', 'debit' => '0', 'credit' => '1000000'],
        ]);
        $this->postJournal($tenant->id, null, '2026-01-10 09:00:00', 'Pendapatan laundry', [
            ['account_code' => '1000', 'debit' => '200000', 'credit' => '0'],
            ['account_code' => '4000', 'debit' => '0', 'credit' => '200000'],
        ]);
        $this->postJournal($tenant->id, null, '2026-01-11 09:00:00', 'Biaya operasional', [
            ['account_code' => '5400', 'debit' => '50000', 'credit' => '0'],
            ['account_code' => '1000', 'debit' => '0', 'credit' => '50000'],
        ]);

        $this->actingAs(User::factory()->for($tenant)->withRole('tenant_owner')->create());

        $report = app(GenerateFinancialReports::class)->balanceSheet(
            $tenant->id,
            CarbonImmutable::parse('2026-01-31 23:59:59'),
        );

        $this->assertSame('1150000.00', $report['total_assets']);
        $this->assertSame('1150000.00', $report['total_equity']);
        $this->assertSame('150000.00', $report['current_year_earnings']);
        $this->assertSame('0.00', $report['difference']);
        $this->assertTrue($report['is_balanced']);
    }

    public function test_report_rejects_an_inverted_date_range(): void
    {
        $tenant = Tenant::factory()->create();

        $this->expectException(DomainException::class);

        $this->actingAs(User::factory()->for($tenant)->withRole('tenant_owner')->create());

        app(GenerateFinancialReports::class)->incomeStatement(
            $tenant->id,
            CarbonImmutable::parse('2026-02-01'),
            CarbonImmutable::parse('2026-01-01'),
        );
    }

    public function test_report_service_rejects_unauthenticated_callers(): void
    {
        $tenant = Tenant::factory()->create();

        $this->expectException(DomainException::class);

        app(GenerateFinancialReports::class)->incomeStatement(
            $tenant->id,
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-01-31'),
        );
    }

    public function test_report_service_requires_reports_permission_for_tenant_staff(): void
    {
        $tenant = Tenant::factory()->create();
        $cashier = User::factory()->for($tenant)->withRole('cashier')->create();
        $this->actingAs($cashier);

        $this->expectException(DomainException::class);

        app(GenerateFinancialReports::class)->incomeStatement(
            $tenant->id,
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-01-31'),
        );
    }

    public function test_report_service_rejects_a_different_tenant_id(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $this->actingAs($owner);

        $this->expectException(DomainException::class);

        app(GenerateFinancialReports::class)->incomeStatement(
            $foreignTenant->id,
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-01-31'),
        );
    }


    public function test_report_service_rejects_a_stale_deactivated_authenticated_actor(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $this->actingAs($owner);

        // Keep Auth's in-memory owner active while persisting deactivation.
        User::query()->whereKey($owner->getKey())->update(['is_active' => false]);

        $this->expectException(DomainException::class);

        app(GenerateFinancialReports::class)->incomeStatement(
            $tenant->id,
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-01-31'),
        );
    }

    /**
     * @param  list<array{account_code: string, debit: string, credit: string}>  $entries
     */
    private function postJournal(int $tenantId, ?int $branchId, string $date, string $description, array $entries): void
    {
        app(CreateJournal::class)->handle(
            tenantId: $tenantId,
            branchId: $branchId,
            journalDate: CarbonImmutable::parse($date),
            referenceType: null,
            referenceId: null,
            description: $description,
            entries: $entries,
        );
    }
}
