<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Orders\OrderResource;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderResourceBranchIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_manager_order_list_excludes_unassigned_and_foreign_tenant_orders(): void
    {
        $tenant = Tenant::factory()->create();
        $assignedBranch = Branch::factory()->for($tenant)->create();
        $unassignedBranch = Branch::factory()->for($tenant)->create();
        $otherTenant = Tenant::factory()->create();
        $otherTenantBranch = Branch::factory()->for($otherTenant)->create();

        $assignedOrder = Order::factory()->for($assignedBranch)->create();
        Order::factory()->for($unassignedBranch)->create();
        Order::factory()->for($otherTenantBranch)->create();

        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($assignedBranch);

        $this->actingAs($manager);

        $visibleOrderIds = OrderResource::getEloquentQuery()
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame([$assignedOrder->id], $visibleOrderIds);
    }

    public function test_tenant_owner_can_see_all_orders_in_own_tenant_only(): void
    {
        $tenant = Tenant::factory()->create();
        $firstBranch = Branch::factory()->for($tenant)->create();
        $secondBranch = Branch::factory()->for($tenant)->create();
        $otherTenant = Tenant::factory()->create();
        $otherTenantBranch = Branch::factory()->for($otherTenant)->create();

        $firstOrder = Order::factory()->for($firstBranch)->create();
        $secondOrder = Order::factory()->for($secondBranch)->create();
        Order::factory()->for($otherTenantBranch)->create();

        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $this->actingAs($owner);

        $visibleOrderIds = OrderResource::getEloquentQuery()
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame([$firstOrder->id, $secondOrder->id], $visibleOrderIds);
    }
}
