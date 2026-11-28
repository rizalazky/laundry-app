<?php

namespace Tests\Feature;

use App\Actions\Orders\CreateOrder;
use App\Actions\Orders\ManageOrderAdjustment;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\OrderAdjustment;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderAdjustmentTenantIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Branch $branch;
    private User $owner;
    private OrderAdjustment $adjustment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->branch = Branch::factory()->for($this->tenant)->create();
        $this->owner = User::factory()->for($this->tenant)->withRole('tenant_owner')->create();

        $customer = Customer::factory()->for($this->tenant)->create();
        $service = Service::factory()->for($this->tenant)->create(['price' => 10000]);
        $order = app(CreateOrder::class)->handle(
            branch: $this->branch,
            user: $this->owner,
            customerId: $customer->id,
            items: [['service_id' => $service->id, 'quantity' => 1]],
            initialPayment: ['amount' => 10000, 'method' => PaymentMethod::Cash],
        );

        $this->adjustment = app(ManageOrderAdjustment::class)->request(
            $order,
            OrderAdjustment::TYPE_REFUND,
            '5000',
            'Regression fixture',
            $this->owner,
        );
    }

    public function test_requester_must_belong_to_the_adjustment_tenant(): void
    {
        $foreignOwner = User::factory()->for(Tenant::factory()->create())->withRole('tenant_owner')->create();

        $this->expectException(QueryException::class);

        DB::table('order_adjustments')->where('id', $this->adjustment->id)
            ->update(['requested_by' => $foreignOwner->id]);
    }

    public function test_branch_must_belong_to_the_adjustment_tenant(): void
    {
        $foreignBranch = Branch::factory()->for(Tenant::factory()->create())->create();

        $this->expectException(QueryException::class);

        DB::table('order_adjustments')->where('id', $this->adjustment->id)
            ->update(['branch_id' => $foreignBranch->id]);
    }

    public function test_order_must_belong_to_the_adjustment_tenant_and_branch(): void
    {
        $foreignTenant = Tenant::factory()->create();
        $foreignBranch = Branch::factory()->for($foreignTenant)->create();
        $foreignOwner = User::factory()->for($foreignTenant)->withRole('tenant_owner')->create();
        $foreignCustomer = Customer::factory()->for($foreignTenant)->create();
        $foreignService = Service::factory()->for($foreignTenant)->create(['price' => 10000]);
        $foreignOrder = app(CreateOrder::class)->handle(
            branch: $foreignBranch,
            user: $foreignOwner,
            customerId: $foreignCustomer->id,
            items: [['service_id' => $foreignService->id, 'quantity' => 1]],
        );

        $this->expectException(QueryException::class);

        DB::table('order_adjustments')->where('id', $this->adjustment->id)
            ->update(['order_id' => $foreignOrder->id]);
    }

    public function test_approver_and_payer_must_belong_to_the_adjustment_tenant(): void
    {
        $foreignOwner = User::factory()->for(Tenant::factory()->create())->withRole('tenant_owner')->create();

        try {
            DB::table('order_adjustments')->where('id', $this->adjustment->id)
                ->update(['approved_by' => $foreignOwner->id]);
            $this->fail('Cross-tenant approver must be rejected.');
        } catch (QueryException) {
            $this->assertDatabaseHas('order_adjustments', [
                'id' => $this->adjustment->id,
                'approved_by' => null,
            ]);
        }

        $this->expectException(QueryException::class);

        DB::table('order_adjustments')->where('id', $this->adjustment->id)
            ->update(['paid_by' => $foreignOwner->id]);
    }
}
