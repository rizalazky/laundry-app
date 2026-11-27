<?php

namespace Tests\Feature;

use App\Actions\Accounting\CreateJournal;
use App\Actions\Accounting\GenerateFinancialReports;
use App\Actions\Investors\GenerateProfitSharings;
use App\Actions\Investors\MarkProfitSharingPaid;
use App\Models\Branch;
use App\Models\Investor;
use App\Models\Journal;
use App\Models\ProfitSharing;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvestorProfitSharingTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_profit_sharing_uses_branch_profit_and_immutable_investor_percentages(): void
    {
        [$tenant, $branch, $owner] = $this->setupOwner();

        $firstInvestor = $this->createInvestor($tenant->id, $branch->id, 'Investor A', '30.00');
        $secondInvestor = $this->createInvestor($tenant->id, $branch->id, 'Investor B', '20.00');

        $this->postJournal($tenant->id, $branch->id, '2026-10-10 09:00:00', 'Pendapatan laundry', [
            ['account_code' => '1000', 'debit' => '1000000.00', 'credit' => '0.00'],
            ['account_code' => '4000', 'debit' => '0.00', 'credit' => '1000000.00'],
        ]);
        $this->postJournal($tenant->id, $branch->id, '2026-10-11 09:00:00', 'Beban operasional', [
            ['account_code' => '5400', 'debit' => '200000.00', 'credit' => '0.00'],
            ['account_code' => '1000', 'debit' => '0.00', 'credit' => '200000.00'],
        ]);

        $this->actingAs($owner);

        $created = app(GenerateProfitSharings::class)->handle($tenant->id, $branch->id, '2026-10');

        $this->assertCount(2, $created);
        $this->assertSame('800000.00', $created[0]->net_profit_base);
        $this->assertSame('30.00', $created[0]->share_percent_snapshot);
        $this->assertSame('240000.00', $created[0]->payout_amount);
        $this->assertSame('20.00', $created[1]->share_percent_snapshot);
        $this->assertSame('160000.00', $created[1]->payout_amount);
        $this->assertSame(ProfitSharing::STATUS_DRAFT, $created[0]->status);

        $firstInvestor->update(['share_percent' => '10.00']);

        $this->assertSame('30.00', $created[0]->fresh()->share_percent_snapshot);
        $this->assertSame('240000.00', $created[0]->fresh()->payout_amount);
    }

    public function test_paying_profit_sharing_posts_distribution_to_equity_not_expenses(): void
    {
        [$tenant, $branch, $owner] = $this->setupOwner();
        $investor = $this->createInvestor($tenant->id, $branch->id, 'Investor A', '25.00');

        $this->postJournal($tenant->id, $branch->id, '2026-10-10 09:00:00', 'Pendapatan laundry', [
            ['account_code' => '1000', 'debit' => '1000000.00', 'credit' => '0.00'],
            ['account_code' => '4000', 'debit' => '0.00', 'credit' => '1000000.00'],
        ]);
        $this->postJournal($tenant->id, $branch->id, '2026-10-11 09:00:00', 'Beban operasional', [
            ['account_code' => '5400', 'debit' => '200000.00', 'credit' => '0.00'],
            ['account_code' => '1000', 'debit' => '0.00', 'credit' => '200000.00'],
        ]);

        $this->actingAs($owner);
        $sharing = app(GenerateProfitSharings::class)->handle($tenant->id, $branch->id, '2026-10')[0];

        $paid = app(MarkProfitSharingPaid::class)->handle($tenant->id, $sharing->id, '1000');

        $this->assertSame(ProfitSharing::STATUS_PAID, $paid->status);
        $this->assertNotNull($paid->paid_at);
        $this->assertNotNull($paid->journal_id);
        $this->assertDatabaseHas('journals', [
            'id' => $paid->journal_id,
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'reference_type' => 'investor_profit_sharing',
            'reference_id' => $sharing->id,
        ]);

        $journal = Journal::query()->with('entries.account')->findOrFail($paid->journal_id);
        $distribution = $journal->entries->firstWhere('account.code', '3300');
        $cash = $journal->entries->firstWhere('account.code', '1000');

        $this->assertSame('200000.00', $distribution->debit);
        $this->assertSame('200000.00', $cash->credit);

        $incomeStatement = app(GenerateFinancialReports::class)->incomeStatement(
            $tenant->id,
            CarbonImmutable::parse('2026-10-01')->startOfDay(),
            CarbonImmutable::parse('2026-10-31')->endOfDay(),
            $branch->id,
        );

        $this->assertSame('800000.00', $incomeStatement['net_income']);

        $retried = app(MarkProfitSharingPaid::class)->handle($tenant->id, $sharing->id, '1000');
        $this->assertSame($paid->journal_id, $retried->journal_id);
        $this->assertSame(3, Journal::query()->count());
    }

    public function test_generation_rejects_total_active_share_above_one_hundred_percent(): void
    {
        [$tenant, $branch, $owner] = $this->setupOwner();
        $this->createInvestor($tenant->id, $branch->id, 'Investor A', '60.00');
        $this->createInvestor($tenant->id, $branch->id, 'Investor B', '50.00');

        $this->postJournal($tenant->id, $branch->id, '2026-10-10 09:00:00', 'Pendapatan laundry', [
            ['account_code' => '1000', 'debit' => '100000.00', 'credit' => '0.00'],
            ['account_code' => '4000', 'debit' => '0.00', 'credit' => '100000.00'],
        ]);

        $this->actingAs($owner);

        try {
            app(GenerateProfitSharings::class)->handle($tenant->id, $branch->id, '2026-10');
            $this->fail('Expected an over-allocation exception.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('tidak boleh melebihi 100%', $exception->getMessage());
        }

        $this->assertDatabaseCount('profit_sharings', 0);
    }

    public function test_generation_rejects_duplicate_period_and_future_period(): void
    {
        [$tenant, $branch, $owner] = $this->setupOwner();
        $this->createInvestor($tenant->id, $branch->id, 'Investor A', '20.00');

        $this->postJournal($tenant->id, $branch->id, '2026-10-10 09:00:00', 'Pendapatan laundry', [
            ['account_code' => '1000', 'debit' => '100000.00', 'credit' => '0.00'],
            ['account_code' => '4000', 'debit' => '0.00', 'credit' => '100000.00'],
        ]);

        $this->actingAs($owner);
        app(GenerateProfitSharings::class)->handle($tenant->id, $branch->id, '2026-10');

        try {
            app(GenerateProfitSharings::class)->handle($tenant->id, $branch->id, '2026-10');
            $this->fail('Expected a duplicate period exception.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('sudah pernah dibuat', $exception->getMessage());
        }

        try {
            app(GenerateProfitSharings::class)->handle($tenant->id, $branch->id, '2099-01');
            $this->fail('Expected a future period exception.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('masa depan', $exception->getMessage());
        }

        $this->assertDatabaseCount('profit_sharings', 1);
    }

    public function test_investor_and_profit_sharing_actions_reject_cross_tenant_access(): void
    {
        [$tenant, $branch, $owner] = $this->setupOwner();
        [$otherTenant, $otherBranch] = $this->setupTenant();
        $otherInvestor = $this->createInvestor($otherTenant->id, $otherBranch->id, 'Foreign Investor', '20.00');

        $this->actingAs($owner);

        try {
            app(GenerateProfitSharings::class)->handle($tenant->id, $otherBranch->id, '2026-10');
            $this->fail('Expected a cross-tenant branch exception.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('Cabang tidak ditemukan', $exception->getMessage());
        }

        $foreignSharingId = DB::table('profit_sharings')->insertGetId([
            'tenant_id' => $otherTenant->id,
            'investor_id' => $otherInvestor->id,
            'period_month' => '2026-10',
            'net_profit_base' => '100000.00',
            'share_percent_snapshot' => '20.00',
            'payout_amount' => '20000.00',
            'status' => ProfitSharing::STATUS_DRAFT,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignSharing = ProfitSharing::withoutGlobalScope('tenant')->findOrFail($foreignSharingId);

        try {
            app(MarkProfitSharingPaid::class)->handle($tenant->id, $foreignSharing->id, '1000');
            $this->fail('Expected a missing tenant-scoped sharing exception.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('tidak ditemukan', $exception->getMessage());
        }

        $this->assertSame(ProfitSharing::STATUS_DRAFT, $foreignSharing->fresh()->status);
    }

    public function test_generation_rejects_read_only_tenant_before_creating_profit_sharing(): void
    {
        [$tenant, $branch, $owner] = $this->setupOwner();
        $tenant->forceFill(['subscription_ends_at' => now()->subDay()])->save();

        $this->actingAs($owner);

        try {
            app(GenerateProfitSharings::class)->handle($tenant->id, $branch->id, '2026-10');
            $this->fail('Expected read-only subscription authorization to reject generation.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('profit_sharings', 0);
        }
    }

    public function test_payment_rejects_read_only_tenant_without_changing_profit_sharing_or_journal(): void
    {
        [$tenant, $branch, $owner] = $this->setupOwner();
        $investor = $this->createInvestor($tenant->id, $branch->id, 'Investor A', '25.00');
        $sharing = ProfitSharing::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'investor_id' => $investor->id,
            'period_month' => '2026-10',
            'net_profit_base' => '800000.00',
            'share_percent_snapshot' => '25.00',
            'payout_amount' => '200000.00',
            'status' => ProfitSharing::STATUS_DRAFT,
        ]);
        $tenant->forceFill(['subscription_ends_at' => now()->subDay()])->save();

        $this->actingAs($owner);

        try {
            app(MarkProfitSharingPaid::class)->handle($tenant->id, $sharing->id, '1000');
            $this->fail('Expected read-only subscription authorization to reject payment.');
        } catch (AuthorizationException) {
            $this->assertSame(ProfitSharing::STATUS_DRAFT, $sharing->fresh()->status);
            $this->assertNull($sharing->fresh()->journal_id);
            $this->assertDatabaseMissing('journals', [
                'reference_type' => 'investor_profit_sharing',
                'reference_id' => $sharing->id,
            ]);
        }
    }

    private function setupOwner(): array
    {
        [$tenant, $branch] = $this->setupTenant();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        return [$tenant, $branch, $owner];
    }

    private function setupTenant(): array
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();

        return [$tenant, $branch];
    }

    private function createInvestor(int $tenantId, int $branchId, string $name, string $sharePercent): Investor
    {
        return Investor::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'name' => $name,
            'capital_amount' => '10000000.00',
            'share_percent' => $sharePercent,
            'is_active' => true,
        ]);
    }

    private function postJournal(int $tenantId, int $branchId, string $date, string $description, array $entries): void
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
