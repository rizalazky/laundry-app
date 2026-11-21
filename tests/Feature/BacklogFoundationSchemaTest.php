<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BacklogFoundationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_crm_promotion_loyalty_and_stock_operation_tables_exist(): void
    {
        foreach ([
            'member_tiers',
            'customer_memberships',
            'promos',
            'vouchers',
            'point_logs',
            'point_rewards',
            'raffles',
            'raffle_tickets',
            'stock_movements',
            'stock_opnames',
            'stock_opname_items',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected {$table} table to exist.");
        }
    }

    public function test_accounting_settlement_investor_and_reset_audit_tables_exist(): void
    {
        foreach ([
            'chart_of_accounts',
            'bank_accounts',
            'edc_machines',
            'cash_settlements',
            'expenses',
            'capital_movements',
            'journals',
            'journal_entries',
            'investors',
            'profit_sharings',
            'tenant_reset_logs',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected {$table} table to exist.");
        }
    }
    public function test_edc_machine_cannot_reference_another_tenants_branch(): void
    {
        $tenantA = $this->createTenant('Tenant A');
        $tenantB = $this->createTenant('Tenant B');
        $branchB = DB::table('branches')->insertGetId([
            'tenant_id' => $tenantB, 'code' => 'B-01', 'name' => 'Branch B',
            'is_workshop' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $coaA = DB::table('chart_of_accounts')->insertGetId([
            'tenant_id' => $tenantA, 'code' => '1000', 'name' => 'Cash',
            'account_type' => 'asset', 'is_system' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $bankA = DB::table('bank_accounts')->insertGetId([
            'tenant_id' => $tenantA, 'coa_id' => $coaA, 'bank_name' => 'Bank A',
            'account_number' => '123456', 'holder_name' => 'Tenant B',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('edc_machines')->insert([
            'tenant_id' => $tenantA, 'branch_id' => $branchB, 'bank_account_id' => $bankA,
            'terminal_name' => 'EDC A', 'mdr_percent' => 1.5, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_customer_membership_cannot_reference_another_tenants_customer(): void
    {
        $tenantA = $this->createTenant('Tenant A');
        $tenantB = $this->createTenant('Tenant B');
        $customerB = DB::table('customers')->insertGetId([
            'tenant_id' => $tenantB, 'name' => 'Customer B',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $tierA = DB::table('member_tiers')->insertGetId([
            'tenant_id' => $tenantA, 'name' => 'Gold', 'price' => 100,
            'duration_days' => 30, 'discount_percent' => 5, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('customer_memberships')->insert([
            'tenant_id' => $tenantA, 'customer_id' => $customerB, 'member_tier_id' => $tierA,
            'started_at' => now(), 'expired_at' => now()->addDays(30), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_profit_sharing_cannot_reference_another_tenants_investor(): void
    {
        $tenantA = $this->createTenant('Tenant A');
        $tenantB = $this->createTenant('Tenant B');
        $branchB = DB::table('branches')->insertGetId([
            'tenant_id' => $tenantB, 'code' => 'B-01', 'name' => 'Branch B',
            'is_workshop' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $investorB = DB::table('investors')->insertGetId([
            'tenant_id' => $tenantB, 'branch_id' => $branchB, 'name' => 'Investor B',
            'capital_amount' => 1000000, 'share_percent' => 10, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('profit_sharings')->insert([
            'tenant_id' => $tenantA, 'investor_id' => $investorB, 'period_month' => '2026-11',
            'net_profit_base' => 100000, 'payout_amount' => 10000, 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }


    public function test_edc_machine_cannot_reference_another_tenants_bank_account(): void
    {
        $tenantA = $this->createTenant('Tenant A');
        $tenantB = $this->createTenant('Tenant B');
        $branchA = DB::table('branches')->insertGetId([
            'tenant_id' => $tenantA, 'code' => 'A-01', 'name' => 'Branch A',
            'is_workshop' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $coaB = DB::table('chart_of_accounts')->insertGetId([
            'tenant_id' => $tenantB, 'code' => '1000', 'name' => 'Cash',
            'account_type' => 'asset', 'is_system' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $bankB = DB::table('bank_accounts')->insertGetId([
            'tenant_id' => $tenantB, 'coa_id' => $coaB, 'bank_name' => 'Bank B',
            'account_number' => '123456', 'holder_name' => 'Tenant B',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('edc_machines')->insert([
            'tenant_id' => $tenantA, 'branch_id' => $branchA, 'bank_account_id' => $bankB,
            'terminal_name' => 'EDC A', 'mdr_percent' => 1.5, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_stock_movement_cannot_reference_another_tenants_inventory_item(): void
    {
        $tenantA = $this->createTenant('Tenant A');
        $tenantB = $this->createTenant('Tenant B');
        $itemB = DB::table('inventory_items')->insertGetId([
            'tenant_id' => $tenantB, 'sku' => 'ITEM-B', 'name' => 'Item B',
            'unit' => 'pcs', 'item_category' => 'raw_material',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('stock_movements')->insert([
            'tenant_id' => $tenantA, 'inventory_item_id' => $itemB, 'movement_type' => 'in',
            'quantity' => 1, 'unit_cost' => 1000,
            'created_at' => now(), 'updated_at' => now(),
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
