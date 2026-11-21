<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountingSchemaIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_transaction_can_only_be_journalized_once_per_tenant(): void
    {
        $tenantId = $this->createTenant('Accounting Tenant');
        $attributes = [
            'tenant_id' => $tenantId,
            'branch_id' => null,
            'journal_date' => now(),
            'reference_type' => 'payment',
            'reference_id' => 100,
            'description' => 'Payment journal',
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('journals')->insert($attributes);

        $this->expectException(QueryException::class);

        DB::table('journals')->insert($attributes);
    }

    public function test_same_source_reference_can_be_used_by_different_tenants(): void
    {
        $tenantA = $this->createTenant('Tenant A');
        $tenantB = $this->createTenant('Tenant B');

        foreach ([$tenantA, $tenantB] as $tenantId) {
            DB::table('journals')->insert([
                'tenant_id' => $tenantId,
                'branch_id' => null,
                'journal_date' => now(),
                'reference_type' => 'payment',
                'reference_id' => 100,
                'description' => 'Payment journal',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(2, DB::table('journals')->where('reference_type', 'payment')->where('reference_id', 100)->count());
    }

    public function test_manual_journals_without_source_reference_can_be_created_multiple_times(): void
    {
        $tenantId = $this->createTenant('Manual Journal Tenant');

        foreach (['Manual entry one', 'Manual entry two'] as $description) {
            DB::table('journals')->insert([
                'tenant_id' => $tenantId,
                'branch_id' => null,
                'journal_date' => now(),
                'reference_type' => null,
                'reference_id' => null,
                'description' => $description,
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(2, DB::table('journals')->where('tenant_id', $tenantId)->count());
    }

    public function test_journal_entry_cannot_use_an_account_from_another_tenant(): void
    {
        $tenantA = $this->createTenant('Journal Tenant A');
        $tenantB = $this->createTenant('Journal Tenant B');

        $journalId = DB::table('journals')->insertGetId([
            'tenant_id' => $tenantA,
            'branch_id' => null,
            'journal_date' => now(),
            'reference_type' => null,
            'reference_id' => null,
            'description' => 'Tenant A journal',
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $accountId = DB::table('chart_of_accounts')->insertGetId([
            'tenant_id' => $tenantB,
            'code' => '1000',
            'name' => 'Cash',
            'account_type' => 'asset',
            'is_system' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('journal_entries')->insert([
            'tenant_id' => $tenantA,
            'journal_id' => $journalId,
            'coa_id' => $accountId,
            'debit' => 100,
            'credit' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTenant(string $name): int
    {
        return DB::table('tenants')->insertGetId([
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'owner_email' => str($name)->slug().'@example.test',
            'plan' => 'starter',
            'max_branches' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
