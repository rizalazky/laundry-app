<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_global_scope_limits_queries_to_the_authenticated_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $ownCustomer = Customer::factory()->for($tenant)->create();
        Customer::factory()->for($otherTenant)->create();

        $this->actingAs($owner);

        $this->assertSame(
            [$ownCustomer->id],
            Customer::query()->pluck('id')->all(),
        );
        $this->assertSame(
            2,
            Customer::query()->withoutGlobalScope('tenant')->count(),
        );
    }

    public function test_tenant_scoped_creation_overrides_a_foreign_tenant_id_from_authenticated_context(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $this->actingAs($owner);

        $customer = Customer::query()->create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Customer from forged tenant id',
            'phone' => '081234567890',
        ]);

        $this->assertSame($tenant->id, $customer->fresh()->tenant_id);
        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(
            0,
            Customer::query()->withoutGlobalScope('tenant')
                ->where('tenant_id', $otherTenant->id)
                ->count(),
        );
    }
}
