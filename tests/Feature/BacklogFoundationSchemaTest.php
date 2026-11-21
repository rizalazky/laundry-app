<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
