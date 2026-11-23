<?php

namespace Tests\Feature;

use App\Actions\Orders\ChangeOrderStatus;
use App\Actions\Orders\CreateOrder;
use App\Actions\Orders\RecordPayment;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\ServiceUnit;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\BranchServicePrice;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class OrderActionsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Branch $branch;

    private User $cashier;

    private Customer $customer;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->branch = Branch::factory()->for($this->tenant)->create(['code' => 'pusat']);
        $this->cashier = User::factory()->for($this->tenant)->withRole('cashier')->create();
        $this->cashier->branches()->attach($this->branch);
        $this->customer = Customer::factory()->for($this->tenant)->create();
        $this->service = Service::factory()->for($this->tenant)->create([
            'name' => 'Cuci Kiloan',
            'price' => 7000,
            'estimated_hours' => 48,
        ]);
    }

    private function createOrder(?array $items = null, mixed $discount = 0, ?array $initialPayment = null, ?Branch $branch = null): Order
    {
        return app(CreateOrder::class)->handle(
            branch: $branch ?? $this->branch,
            user: $this->cashier,
            customerId: $this->customer->getKey(),
            items: $items ?? [['service_id' => $this->service->getKey(), 'quantity' => 10]],
            discount: $discount,
            initialPayment: $initialPayment,
        );
    }

    public function test_order_is_created_with_snapshot_prices_totals_log_and_due_date(): void
    {
        $order = $this->createOrder(
            items: [
                ['service_id' => $this->service->getKey(), 'quantity' => '2.5'],
            ],
            discount: 1000,
        );

        $item = $order->items->sole();

        $this->assertSame('17500.00', $order->subtotal);
        $this->assertSame('16500.00', $order->total);
        $this->assertSame('0.00', $order->paid_amount);
        $this->assertSame(OrderStatus::Received, $order->status);
        $this->assertSame(PaymentStatus::Unpaid, $order->payment_status);
        $this->assertSame('Cuci Kiloan', $item->service_name);
        $this->assertSame('7000.00', $item->unit_price);
        $this->assertSame('17500.00', $item->subtotal);
        $this->assertTrue($order->due_at->equalTo($order->order_date->copy()->addHours(48)));
        $this->assertSame(1, $order->statusLogs()->count());
        $this->assertSame($this->tenant->id, $order->tenant_id);
    }

    public function test_branch_price_override_is_used_and_later_price_changes_do_not_alter_the_order(): void
    {
        BranchServicePrice::factory()->create([
            'branch_id' => $this->branch->id,
            'service_id' => $this->service->id,
            'price' => 9000,
        ]);

        $order = $this->createOrder();
        $this->assertSame('90000.00', $order->total);

        $this->service->update(['price' => 20000]);
        BranchServicePrice::query()->update(['price' => 30000]);

        $this->assertSame('90000.00', $order->fresh()->total);
        $this->assertSame('9000.00', $order->items()->sole()->unit_price);
    }

    public function test_piece_services_reject_fractional_quantities_but_accept_whole_numbers(): void
    {
        $pieceService = Service::factory()->for($this->tenant)->create(['unit' => ServiceUnit::Piece]);

        try {
            $this->createOrder([['service_id' => $pieceService->id, 'quantity' => '1.5']]);
            $this->fail('PCS quantities must be whole numbers.');
        } catch (DomainException) {
            $this->assertSame(0, Order::query()->count());
        }

        $order = $this->createOrder([['service_id' => $pieceService->id, 'quantity' => '2']]);

        $this->assertSame('2.00', $order->items()->sole()->quantity);
    }

    public function test_invoice_numbers_are_sequential_per_branch_and_reset_each_day(): void
    {
        $otherBranch = Branch::factory()->for($this->tenant)->create(['code' => 'DGO']);
        $this->cashier->branches()->attach($otherBranch);

        $this->travelTo(now()->setDate(2026, 11, 19)->setTime(10, 0));

        $first = $this->createOrder();
        $second = $this->createOrder();
        $other = $this->createOrder(branch: $otherBranch);

        $this->assertSame('PUSAT-261119-0001', $first->invoice_no);
        $this->assertSame('PUSAT-261119-0002', $second->invoice_no);
        $this->assertSame('DGO-261119-0001', $other->invoice_no);

        $this->travelTo(now()->addDay());

        $this->assertSame('PUSAT-261120-0001', $this->createOrder()->invoice_no);
    }

    public function test_tenant_invoice_prefix_overrides_branch_code_when_configured(): void
    {
        $this->tenant->updateGeneralSettings(['invoice_prefix' => 'SMART']);

        $this->travelTo(now()->setDate(2026, 11, 19)->setTime(10, 0));

        $this->assertSame('SMART-PUSAT-261119-0001', $this->createOrder()->invoice_no);
    }

    public function test_tenant_default_estimation_is_used_when_services_have_no_estimate(): void
    {
        $this->service->update(['estimated_hours' => 0]);
        $this->tenant->updateGeneralSettings(['default_estimation_hours' => 36]);

        $order = $this->createOrder();

        $this->assertTrue($order->due_at->equalTo($order->order_date->copy()->addHours(36)));
    }

    public function test_partial_payments_can_be_disabled_per_tenant(): void
    {
        $this->tenant->updateGeneralSettings(['allow_partial_payment' => false]);
        $order = $this->createOrder();
        $recordPayment = app(RecordPayment::class);

        try {
            $recordPayment->handle($order, 20000, PaymentMethod::Cash, $this->cashier);
            $this->fail('Partial payments should be disabled for this tenant.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Pembayaran sebagian dinonaktifkan', $exception->getMessage());
        }

        $this->assertSame(0, $order->payments()->count());

        $recordPayment->handle($order, 70000, PaymentMethod::Cash, $this->cashier);

        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
    }

    public function test_down_payment_at_creation_marks_the_order_partial(): void
    {
        $order = $this->createOrder(initialPayment: ['amount' => 20000, 'method' => PaymentMethod::Qris]);

        $this->assertSame('20000.00', $order->paid_amount);
        $this->assertSame('50000.00', $order->balanceDue());
        $this->assertSame(PaymentStatus::Partial, $order->payment_status);
        $this->assertSame(1, $order->payments()->count());
    }

    public function test_installments_move_the_order_from_unpaid_to_partial_to_paid(): void
    {
        $order = $this->createOrder();
        $record = app(RecordPayment::class);

        $this->assertSame(PaymentStatus::Unpaid, $order->payment_status);

        $record->handle($order, 30000, PaymentMethod::Cash, $this->cashier);
        $this->assertSame(PaymentStatus::Partial, $order->payment_status);

        $record->handle($order, 25000, PaymentMethod::Transfer, $this->cashier, reference: 'TRX-1');
        $this->assertSame(PaymentStatus::Partial, $order->payment_status);
        $this->assertSame('15000.00', $order->balanceDue());

        $record->handle($order, 15000, PaymentMethod::Cash, $this->cashier);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame('70000.00', $order->paid_amount);
        $this->assertSame('0.00', $order->balanceDue());
        $this->assertSame(3, $order->payments()->count());
    }

    public function test_ledger_sum_always_matches_the_cached_paid_amount(): void
    {
        $order = $this->createOrder();
        $record = app(RecordPayment::class);

        $record->handle($order, 10000.50, PaymentMethod::Cash, $this->cashier);
        $record->handle($order, 20000, PaymentMethod::Qris, $this->cashier);
        $manager = User::factory()->for($this->tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($this->branch);
        $record->handle($order, 5000.25, PaymentMethod::Cash, $manager, PaymentType::Refund);

        $ledger = $order->payments()->get()->sum(
            fn (Payment $payment) => $payment->type === PaymentType::Refund ? -$payment->amount : $payment->amount
        );

        $this->assertEqualsWithDelta($ledger, (float) $order->fresh()->paid_amount, 0.001);
        $this->assertSame('25000.25', $order->fresh()->paid_amount);
    }

    public function test_overpayment_and_invalid_amounts_are_rejected_without_writing_to_the_ledger(): void
    {
        $order = $this->createOrder();
        $record = app(RecordPayment::class);

        foreach ([70000.01, 0, -5, '12.345', 'abc'] as $amount) {
            try {
                $record->handle($order, $amount, PaymentMethod::Cash, $this->cashier);
                $this->fail('Expected DomainException for amount '.$amount);
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(0, Payment::query()->count());
        $this->assertSame('0.00', $order->fresh()->paid_amount);
    }

    public function test_refund_reduces_paid_amount_and_cannot_exceed_it(): void
    {
        $order = $this->createOrder(initialPayment: ['amount' => 70000, 'method' => PaymentMethod::Cash]);
        $record = app(RecordPayment::class);

        $this->assertSame(PaymentStatus::Paid, $order->payment_status);

        $manager = User::factory()->for($this->tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($this->branch);
        $record->handle($order, 20000, PaymentMethod::Cash, $manager, PaymentType::Refund, notes: 'Salah hitung');
        $this->assertSame('50000.00', $order->paid_amount);
        $this->assertSame(PaymentStatus::Partial, $order->payment_status);

        $this->expectException(DomainException::class);
        $record->handle($order, 50000.01, PaymentMethod::Cash, $manager, PaymentType::Refund);
    }

    public function test_full_refund_returns_the_order_to_unpaid(): void
    {
        $order = $this->createOrder(initialPayment: ['amount' => 10000, 'method' => PaymentMethod::Cash]);

        $manager = User::factory()->for($this->tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($this->branch);
        app(RecordPayment::class)->handle($order, 10000, PaymentMethod::Cash, $manager, PaymentType::Refund);

        $this->assertSame('0.00', $order->paid_amount);
        $this->assertSame(PaymentStatus::Unpaid, $order->payment_status);
    }

    public function test_repeating_a_payment_with_the_same_idempotency_key_does_not_double_charge(): void
    {
        $order = $this->createOrder();
        $record = app(RecordPayment::class);

        $first = $record->handle($order, 10000, PaymentMethod::Cash, $this->cashier, idempotencyKey: 'abc-123');
        $second = $record->handle($order, 10000, PaymentMethod::Cash, $this->cashier, idempotencyKey: 'abc-123');

        $this->assertTrue($first->is($second));
        $this->assertSame(1, $order->payments()->count());
        $this->assertSame('10000.00', $order->fresh()->paid_amount);
    }

    public function test_payment_ledger_entries_are_immutable(): void
    {
        $order = $this->createOrder(initialPayment: ['amount' => 10000, 'method' => PaymentMethod::Cash]);
        $payment = $order->payments()->sole();

        try {
            $payment->update(['amount' => 1]);
            $this->fail('Updating a ledger entry must be blocked.');
        } catch (LogicException) {
            $this->assertSame('10000.00', $payment->fresh()->amount);
        }

        $this->expectException(LogicException::class);
        $payment->delete();
    }

    public function test_database_guards_block_mass_updates_deletes_and_parent_cascades_for_payment_ledger(): void
    {
        $order = $this->createOrder(initialPayment: ['amount' => 10000, 'method' => PaymentMethod::Cash]);
        $payment = $order->payments()->sole();

        foreach ([
            fn () => Payment::query()->whereKey($payment->id)->update(['amount' => 1]),
            fn () => Payment::query()->whereKey($payment->id)->delete(),
            fn () => \Illuminate\Support\Facades\DB::table('payments')->where('id', $payment->id)->update(['amount' => 1]),
            fn () => \Illuminate\Support\Facades\DB::table('payments')->where('id', $payment->id)->delete(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Database-level ledger guards must reject direct updates and deletes.');
            } catch (\Illuminate\Database\QueryException) {
                $this->assertDatabaseHas('payments', [
                    'id' => $payment->id,
                    'amount' => '10000.00',
                ]);
            }
        }

        try {
            $order->delete();
            $this->fail('An order with ledger entries must not be physically deleted.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertDatabaseHas('payments', ['id' => $payment->id]);
        }
    }

    public function test_status_moves_forward_only_and_completion_requires_full_payment(): void
    {
        $order = $this->createOrder();
        $change = app(ChangeOrderStatus::class);

        $change->handle($order, OrderStatus::Washing, $this->cashier);
        $change->handle($order, OrderStatus::Ironing, $this->cashier);
        $this->assertSame(OrderStatus::Ironing, $order->status);

        try {
            $change->handle($order, OrderStatus::Washing, $this->cashier);
            $this->fail('Moving backwards must be rejected.');
        } catch (DomainException) {
            $this->assertSame(OrderStatus::Ironing, $order->fresh()->status);
        }

        $change->handle($order, OrderStatus::Ready, $this->cashier);

        try {
            $change->handle($order, OrderStatus::Completed, $this->cashier);
            $this->fail('Unpaid order must not be completed.');
        } catch (DomainException) {
            $this->assertSame(OrderStatus::Ready, $order->fresh()->status);
        }

        app(RecordPayment::class)->handle($order, 70000, PaymentMethod::Cash, $this->cashier);
        $change->handle($order, OrderStatus::Completed, $this->cashier);

        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertSame(5, $order->statusLogs()->count());
    }

    public function test_cancellation_is_blocked_while_money_is_held_and_cancelled_orders_reject_payments(): void
    {
        $paid = $this->createOrder(initialPayment: ['amount' => 5000, 'method' => PaymentMethod::Cash]);
        $manager = User::factory()->for($this->tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($this->branch);
        $change = app(ChangeOrderStatus::class);

        try {
            $change->handle($paid, OrderStatus::Cancelled, $manager);
            $this->fail('Order with a payment must be refunded before cancelling.');
        } catch (DomainException) {
            $this->assertSame(OrderStatus::Received, $paid->fresh()->status);
        }

        app(RecordPayment::class)->handle($paid, 5000, PaymentMethod::Cash, $manager, PaymentType::Refund);
        $change->handle($paid, OrderStatus::Cancelled, $manager);
        $this->assertSame(OrderStatus::Cancelled, $paid->status);

        $this->expectException(DomainException::class);
        app(RecordPayment::class)->handle($paid, 1000, PaymentMethod::Cash, $manager);
    }

    public function test_roles_are_limited_in_which_statuses_they_can_set(): void
    {
        $order = $this->createOrder();
        $operator = User::factory()->for($this->tenant)->withRole('operator')->create();
        $operator->branches()->attach($this->branch);
        $change = app(ChangeOrderStatus::class);

        $change->handle($order, OrderStatus::Washing, $operator);

        foreach ([OrderStatus::Cancelled, OrderStatus::Completed] as $status) {
            try {
                $change->handle($order, $status, $operator);
                $this->fail('Operator must not set '.$status->value);
            } catch (DomainException) {
                $this->assertSame(OrderStatus::Washing, $order->fresh()->status);
            }
        }

        $this->expectException(DomainException::class);
        $change->handle($order, OrderStatus::Cancelled, $this->cashier);
    }

    public function test_order_rejects_foreign_customers_foreign_and_inactive_services_and_bad_discounts(): void
    {
        $foreignCustomer = Customer::factory()->create();
        $foreignService = Service::factory()->create();
        $inactiveService = Service::factory()->for($this->tenant)->inactive()->create();

        $attempts = [
            fn () => app(CreateOrder::class)->handle($this->branch, $this->cashier, $foreignCustomer->id, [['service_id' => $this->service->id, 'quantity' => 1]]),
            fn () => $this->createOrder([['service_id' => $foreignService->id, 'quantity' => 1]]),
            fn () => $this->createOrder([['service_id' => $inactiveService->id, 'quantity' => 1]]),
            fn () => $this->createOrder([['service_id' => $this->service->id, 'quantity' => 0]]),
            fn () => $this->createOrder(discount: 70001),
            fn () => $this->createOrder(discount: -1),
            fn () => $this->createOrder(items: []),
            fn () => $this->createOrder(initialPayment: ['amount' => 70001, 'method' => PaymentMethod::Cash]),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
                $this->fail('Expected the order to be rejected.');
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_order_creation_checks_actor_branch_membership_active_state_and_subscription(): void
    {
        $otherBranch = Branch::factory()->for($this->tenant)->create(['code' => 'DGO']);

        try {
            $this->createOrder(branch: $otherBranch);
            $this->fail('Cashier assigned to another branch must be rejected.');
        } catch (DomainException) {
            $this->assertSame(0, Order::query()->count());
        }

        $inactiveCashier = User::factory()->for($this->tenant)->withRole('cashier')->create(['is_active' => false]);
        $inactiveCashier->branches()->attach($this->branch);

        try {
            app(CreateOrder::class)->handle($this->branch, $inactiveCashier, $this->customer->id, [['service_id' => $this->service->id, 'quantity' => 1]]);
            $this->fail('Inactive users must be rejected.');
        } catch (DomainException) {
            $this->assertSame(0, Order::query()->count());
        }

        $inactiveBranch = Branch::factory()->for($this->tenant)->create(['is_active' => false]);

        try {
            $this->createOrder(branch: $inactiveBranch);
            $this->fail('Inactive branches must be rejected.');
        } catch (DomainException) {
            $this->assertSame(0, Order::query()->count());
        }

        $this->tenant->update(['subscription_ends_at' => now()->subMinute()]);

        try {
            $this->createOrder();
            $this->fail('Expired subscriptions must be rejected.');
        } catch (DomainException) {
            $this->assertSame(0, Order::query()->count());
        }
    }

    public function test_tenant_owner_can_create_orders_in_any_active_branch_of_their_tenant(): void
    {
        $otherBranch = Branch::factory()->for($this->tenant)->create(['code' => 'DGO']);
        $owner = User::factory()->for($this->tenant)->withRole('tenant_owner')->create();

        $order = app(CreateOrder::class)->handle(
            $otherBranch,
            $owner,
            $this->customer->id,
            [['service_id' => $this->service->id, 'quantity' => 1]],
        );

        $this->assertSame($otherBranch->id, $order->branch_id);
    }

    public function test_order_actions_reject_staff_from_another_branch_and_inactive_staff(): void
    {
        $order = $this->createOrder();
        $otherBranch = Branch::factory()->for($this->tenant)->create(['code' => 'DGO']);
        $outsider = User::factory()->for($this->tenant)->withRole('cashier')->create();
        $outsider->branches()->attach($otherBranch);

        foreach ([
            fn () => app(ChangeOrderStatus::class)->handle($order, OrderStatus::Washing, $outsider),
            fn () => app(RecordPayment::class)->handle($order, 1000, PaymentMethod::Cash, $outsider),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Staff without branch access must be rejected.');
            } catch (DomainException) {
                $this->assertSame(OrderStatus::Received, $order->fresh()->status);
                $this->assertSame('0.00', $order->fresh()->paid_amount);
            }
        }

        $inactive = User::factory()->for($this->tenant)->withRole('cashier')->create(['is_active' => false]);
        $inactive->branches()->attach($this->branch);

        $this->expectException(DomainException::class);
        app(RecordPayment::class)->handle($order, 1000, PaymentMethod::Cash, $inactive);
    }

    public function test_cashier_cannot_record_refunds_through_the_business_action(): void
    {
        $order = $this->createOrder(initialPayment: ['amount' => 10000, 'method' => PaymentMethod::Cash]);

        $this->expectException(DomainException::class);
        app(RecordPayment::class)->handle($order, 1000, PaymentMethod::Cash, $this->cashier, PaymentType::Refund);
    }


    public function test_workshop_order_can_progress_through_washing_drying_ironing_and_packing(): void
    {
        $order = $this->createOrder();
        $change = app(ChangeOrderStatus::class);

        foreach ([
            OrderStatus::Washing,
            OrderStatus::Drying,
            OrderStatus::Ironing,
            OrderStatus::Packing,
        ] as $stage) {
            $change->handle($order, $stage, $this->cashier);
            $this->assertSame($stage, $order->fresh()->status);
        }

        $packingLog = $order->statusLogs()->where('to_status', OrderStatus::Packing->value)->sole();

        $this->assertSame('Dikemas', OrderStatus::Packing->label());
        $this->assertSame('info', OrderStatus::Packing->color());
        $this->assertSame($this->cashier->id, $packingLog->user_id);
        $this->assertSame(5, $order->statusLogs()->count());
        $this->assertTrue(OrderStatus::Packing->canMoveTo(OrderStatus::Ready));
        $this->assertFalse(OrderStatus::Packing->canMoveTo(OrderStatus::Ironing));
    }

    public function test_production_stage_transitions_close_previous_logs_and_open_the_next_stage(): void
    {
        $operator = User::factory()->for($this->tenant)->withRole('operator')->create();
        $operator->branches()->attach($this->branch);
        $order = $this->createOrder();

        $this->travelTo(now()->startOfSecond());

        app(ChangeOrderStatus::class)->handle(
            $order,
            OrderStatus::Washing,
            $this->cashier,
            workerId: $operator->getKey(),
            machineCode: 'WASH-01',
        );

        $washingLog = $order->productionLogs()->sole();

        $this->assertSame('washer', $washingLog->stage);
        $this->assertSame($operator->getKey(), $washingLog->worker_id);
        $this->assertSame('WASH-01', $washingLog->machine_code);
        $this->assertNull($washingLog->finished_at);

        $this->travel(20)->minutes();

        app(ChangeOrderStatus::class)->handle(
            $order->fresh(),
            OrderStatus::Drying,
            $this->cashier,
            machineCode: 'DRY-01',
        );

        $this->travel(30)->minutes();

        app(ChangeOrderStatus::class)->handle(
            $order->fresh(),
            OrderStatus::Ready,
            $this->cashier,
        );

        $logs = $order->productionLogs()->orderBy('id')->get();

        $this->assertCount(2, $logs);
        $this->assertSame('washer', $logs[0]->stage);
        $this->assertTrue($logs[0]->finished_at->greaterThan($logs[0]->started_at));
        $this->assertSame('dryer', $logs[1]->stage);
        $this->assertSame('DRY-01', $logs[1]->machine_code);
        $this->assertTrue($logs[1]->finished_at->greaterThan($logs[1]->started_at));
    }

    public function test_cancelling_an_order_closes_its_active_production_log(): void
    {
        $manager = User::factory()->for($this->tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($this->branch);
        $order = $this->createOrder();

        app(ChangeOrderStatus::class)->handle(
            $order,
            OrderStatus::Washing,
            $this->cashier,
        );

        app(ChangeOrderStatus::class)->handle(
            $order->fresh(),
            OrderStatus::Cancelled,
            $manager,
        );

        $productionLog = $order->productionLogs()->sole();

        $this->assertSame('washer', $productionLog->stage);
        $this->assertNotNull($productionLog->finished_at);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_invalid_worker_does_not_create_a_production_log_or_change_order_status(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherBranch = Branch::factory()->for($otherTenant)->create();
        $foreignWorker = User::factory()->for($otherTenant)->withRole('operator')->create();
        $foreignWorker->branches()->attach($otherBranch);
        $order = $this->createOrder();

        try {
            app(ChangeOrderStatus::class)->handle(
                $order,
                OrderStatus::Washing,
                $this->cashier,
                workerId: $foreignWorker->getKey(),
                machineCode: 'WASH-FOREIGN',
            );
            $this->fail('A worker from another tenant must be rejected.');
        } catch (DomainException) {
            $this->assertSame(OrderStatus::Received, $order->fresh()->status);
            $this->assertDatabaseCount('production_logs', 0);
        }
    }


    public function test_order_creation_records_audit_activity(): void
    {
        $order = $this->createOrder();

        $log = ActivityLog::query()->where('action', 'order.created')->sole();

        $this->assertSame($this->cashier->id, $log->user_id);
        $this->assertSame(Order::class, $log->subject_type);
        $this->assertSame($order->id, $log->subject_id);
        $this->assertSame($order->invoice_no, $log->properties['invoice_no']);
        $this->assertSame($this->branch->id, $log->properties['branch_id']);
        $this->assertSame('pos', $log->properties['order_source']);
        $this->assertArrayNotHasKey('notes', $log->properties);
    }

    public function test_payment_and_refund_events_are_audited_without_duplicate_idempotent_entries(): void
    {
        $order = $this->createOrder();
        $record = app(RecordPayment::class);

        $first = $record->handle(
            $order,
            10000,
            PaymentMethod::Cash,
            $this->cashier,
            idempotencyKey: 'audit-payment-001',
            reference: 'CASH-001',
        );
        $record->handle(
            $order,
            10000,
            PaymentMethod::Cash,
            $this->cashier,
            idempotencyKey: 'audit-payment-001',
            reference: 'CASH-001',
        );

        $paymentLog = ActivityLog::query()->where('action', 'payment.recorded')->sole();

        $this->assertSame(Payment::class, $paymentLog->subject_type);
        $this->assertSame($first->id, $paymentLog->subject_id);
        $this->assertSame('10000.00', $paymentLog->properties['amount']);
        $this->assertSame('cash', $paymentLog->properties['method']);
        $this->assertSame('CASH-001', $paymentLog->properties['reference']);

        $manager = User::factory()->for($this->tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($this->branch);

        $refund = $record->handle($order, 5000, PaymentMethod::Cash, $manager, PaymentType::Refund);
        $refundLog = ActivityLog::query()->where('action', 'payment.refunded')->sole();

        $this->assertSame($refund->id, $refundLog->subject_id);
        $this->assertSame('refund', $refundLog->properties['type']);
        $this->assertSame('5000.00', $refundLog->properties['amount']);
        $this->assertSame(1, ActivityLog::query()->where('action', 'payment.recorded')->count());
    }

    public function test_order_status_transitions_are_audited_with_production_metadata(): void
    {
        $order = $this->createOrder();
        $operator = User::factory()->for($this->tenant)->withRole('operator')->create();
        $operator->branches()->attach($this->branch);

        app(ChangeOrderStatus::class)->handle(
            $order,
            OrderStatus::Washing,
            $this->cashier,
            note: 'Mulai proses cuci',
            workerId: $operator->id,
            machineCode: 'WASH-01',
        );

        $log = ActivityLog::query()->where('action', 'order.status_changed')->sole();

        $this->assertSame(Order::class, $log->subject_type);
        $this->assertSame($order->id, $log->subject_id);
        $this->assertSame('received', $log->properties['from_status']);
        $this->assertSame('washing', $log->properties['to_status']);
        $this->assertSame($operator->id, $log->properties['worker_id']);
        $this->assertSame('WASH-01', $log->properties['machine_code']);
        $this->assertSame('Mulai proses cuci', $log->properties['note']);
    }

    public function test_order_actions_reject_a_forged_manager_actor_when_authenticated_as_cashier(): void
    {
        $order = $this->createOrder();
        $manager = User::factory()->for($this->tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($this->branch);
        $this->actingAs($this->cashier);
        $this->expectException(DomainException::class);
        app(RecordPayment::class)->handle($order, 1000, PaymentMethod::Cash, $manager);
    }

    public function test_order_status_action_rejects_a_forged_manager_actor_when_authenticated_as_cashier(): void
    {
        $order = $this->createOrder();
        $manager = User::factory()->for($this->tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($this->branch);
        $this->actingAs($this->cashier);
        $this->expectException(DomainException::class);
        app(ChangeOrderStatus::class)->handle($order, OrderStatus::Cancelled, $manager);
    }
}
