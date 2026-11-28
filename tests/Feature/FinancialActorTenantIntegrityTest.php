<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinancialActorTenantIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_financial_records_cannot_reference_an_actor_from_another_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $foreignOwner = User::factory()->for($foreignTenant)->withRole('tenant_owner')->create();

        $expenseAccountId = $this->createAccount($tenant->id, '5400');
        $cashAccountId = $this->createAccount($tenant->id, '1000');
        $sourceBankId = $this->createBankAccount($tenant->id, $cashAccountId, 'SRC-'.$tenant->id);
        $destinationBankId = $this->createBankAccount($tenant->id, $this->createAccount($tenant->id, '1010'), 'DST-'.$tenant->id);

        $now = now();
        $records = [
            ['cash_settlements', 'created_by', [
                'tenant_id' => $tenant->id,
                'branch_id' => null,
                'source_type' => 'outlet_cash',
                'amount' => 10000,
                'target_bank_id' => $destinationBankId,
                'settled_at' => $now,
                'created_by' => $owner->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['expenses', 'created_by', [
                'tenant_id' => $tenant->id,
                'location_type' => 'company',
                'location_id' => null,
                'coa_id' => $expenseAccountId,
                'amount' => 10000,
                'description' => 'Test expense',
                'spent_at' => $now,
                'created_by' => $owner->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['capital_movements', 'created_by', [
                'tenant_id' => $tenant->id,
                'coa_id' => $cashAccountId,
                'type' => 'modal_in',
                'amount' => 10000,
                'notes' => 'Test capital movement',
                'transacted_at' => $now,
                'created_by' => $owner->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['journals', 'created_by', [
                'tenant_id' => $tenant->id,
                'branch_id' => null,
                'journal_date' => $now,
                'reference_type' => null,
                'reference_id' => null,
                'description' => 'Test journal',
                'created_by' => $owner->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['bank_transfers', 'created_by', [
                'tenant_id' => $tenant->id,
                'branch_id' => null,
                'source_bank_id' => $sourceBankId,
                'destination_bank_id' => $destinationBankId,
                'amount' => 10000,
                'reference_number' => 'TEST-TRANSFER-'.$tenant->id,
                'transferred_at' => $now,
                'created_by' => $owner->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['tenant_reset_logs', 'executed_by', [
                'tenant_id' => $tenant->id,
                'executed_by' => $owner->id,
                'reset_scope' => 'transactions_only',
                'records_purged_count' => 0,
                'executed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
        ];

        foreach ($records as [$table, $actorColumn, $attributes]) {
            $id = DB::table($table)->insertGetId($attributes);

            try {
                DB::table($table)->where('id', $id)->update([
                    $actorColumn => $foreignOwner->id,
                ]);

                $this->fail("{$table}.{$actorColumn} accepted a user from another tenant.");
            } catch (QueryException) {
                $this->assertDatabaseHas($table, [
                    'id' => $id,
                    $actorColumn => $owner->id,
                    'tenant_id' => $tenant->id,
                ]);
            }
        }
    }

    private function createAccount(int $tenantId, string $code): int
    {
        return DB::table('chart_of_accounts')->insertGetId([
            'tenant_id' => $tenantId,
            'code' => $code,
            'name' => 'Account '.$code,
            'account_type' => $code === '5400' ? 'expense' : 'asset',
            'is_system' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createBankAccount(int $tenantId, int $coaId, string $accountNumber): int
    {
        return DB::table('bank_accounts')->insertGetId([
            'tenant_id' => $tenantId,
            'coa_id' => $coaId,
            'bank_name' => 'Test Bank',
            'account_number' => $accountNumber,
            'holder_name' => 'Test Owner',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
