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
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Branch $branch;
    private User $cashier;
    private User $owner;
    private \App\Models\Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->branch = Branch::factory()->for($this->tenant)->create();
        $this->cashier = User::factory()->for($this->tenant)->withRole('cashier')->create();
        $this->cashier->branches()->attach($this->branch);
        $this->owner = User::factory()->for($this->tenant)->withRole('tenant_owner')->create();

        $customer = Customer::factory()->for($this->tenant)->create();
        $service = Service::factory()->for($this->tenant)->create(['price' => 7000]);
        $this->order = app(CreateOrder::class)->handle(
            branch: $this->branch,
            user: $this->cashier,
            customerId: $customer->id,
            items: [['service_id' => $service->id, 'quantity' => 10]],
            initialPayment: ['amount' => 70000, 'method' => PaymentMethod::Cash],
        );
    }

    public function test_cashier_can_request_refund_but_request_does_not_create_a_payment(): void
    {
        $adjustment = app(ManageOrderAdjustment::class)->request(
            $this->order, OrderAdjustment::TYPE_REFUND, '25000', 'Pakaian rusak', $this->cashier,
        );

        $this->assertSame(OrderAdjustment::STATUS_PENDING, $adjustment->status);
        $this->assertSame('25000.00', $adjustment->requested_amount);
        $this->assertSame(1, $this->order->payments()->count());
        $this->assertNull($adjustment->payment_id);
    }

    public function test_pending_refund_requests_reserve_available_refund_amount(): void
    {
        $action = app(ManageOrderAdjustment::class);
        $action->request($this->order, OrderAdjustment::TYPE_REFUND, 40000, 'Keluhan pertama', $this->cashier);

        $this->expectException(DomainException::class);
        $action->request($this->order, OrderAdjustment::TYPE_REFUND, 40000, 'Keluhan kedua', $this->cashier);
    }

    public function test_owner_approval_is_not_a_payment_and_actual_payout_posts_refund_ledger(): void
    {
        $action = app(ManageOrderAdjustment::class);
        $adjustment = $action->request(
            $this->order, OrderAdjustment::TYPE_REFUND, 30000, 'Pakaian hilang', $this->cashier,
        );

        $approved = $action->approve($adjustment, 25000, $this->owner);

        $this->assertSame(OrderAdjustment::STATUS_APPROVED, $approved->status);
        $this->assertSame('25000.00', $approved->approved_amount);
        $this->assertSame(1, $this->order->payments()->count());
        $this->assertSame('70000.00', $this->order->fresh()->paid_amount);

        $paid = $action->markPaid($approved, $this->owner, PaymentMethod::Cash);

        $this->assertSame(OrderAdjustment::STATUS_PAID, $paid->status);
        $this->assertNotNull($paid->payment_id);
        $this->assertSame(2, $this->order->payments()->count());
        $this->assertSame('45000.00', $this->order->fresh()->paid_amount);
    }

    public function test_only_owner_can_approve_or_pay_and_only_pending_requests_can_be_rejected(): void
    {
        $action = app(ManageOrderAdjustment::class);
        $adjustment = $action->request(
            $this->order, OrderAdjustment::TYPE_COMPENSATION, 100000, 'Kerusakan barang', $this->cashier,
        );

        try {
            $action->approve($adjustment, 100000, $this->cashier);
            $this->fail('Cashier must not approve an adjustment.');
        } catch (DomainException) {
            $this->assertSame(OrderAdjustment::STATUS_PENDING, $adjustment->fresh()->status);
        }

        $rejected = $action->reject($adjustment, 'Tidak memenuhi kebijakan', $this->owner);

        $this->assertSame(OrderAdjustment::STATUS_REJECTED, $rejected->status);
        $this->assertSame('100000.00', $rejected->requested_amount);
        $this->assertNull($rejected->expense_id);
    }
}
