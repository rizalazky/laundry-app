<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantResetLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantResetLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_logs_are_scoped_to_the_authenticated_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $ownLog = TenantResetLog::query()->create([
            'tenant_id' => $tenant->id,
            'executed_by' => $owner->id,
            'reset_scope' => TenantResetLog::SCOPE_TRANSACTIONS_ONLY,
            'records_purged_count' => 12,
            'executed_at' => now(),
        ]);
        $foreignOwner = User::factory()->for($foreignTenant)->withRole('tenant_owner')->create();
        $foreignLog = TenantResetLog::query()->create([
            'tenant_id' => $foreignTenant->id,
            'executed_by' => $foreignOwner->id,
            'reset_scope' => TenantResetLog::SCOPE_FULL_FINANCIALS,
            'records_purged_count' => 42,
            'executed_at' => now(),
        ]);

        $this->actingAs($owner);

        $this->assertSame([$ownLog->id], TenantResetLog::query()->pluck('id')->all());
        $this->assertNotContains($foreignLog->id, TenantResetLog::query()->pluck('id')->all());
    }

    public function test_reset_log_persists_the_scope_count_executor_and_execution_timestamp(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $executedAt = now()->startOfSecond();

        $log = TenantResetLog::query()->create([
            'tenant_id' => $tenant->id,
            'executed_by' => $owner->id,
            'reset_scope' => TenantResetLog::SCOPE_FULL_FINANCIALS,
            'records_purged_count' => 125,
            'executed_at' => $executedAt,
        ]);

        $this->assertSame($tenant->id, $log->tenant_id);
        $this->assertSame($owner->id, $log->executed_by);
        $this->assertSame(TenantResetLog::SCOPE_FULL_FINANCIALS, $log->reset_scope);
        $this->assertSame(125, $log->records_purged_count);
        $this->assertTrue($executedAt->equalTo($log->executed_at));
    }
}
