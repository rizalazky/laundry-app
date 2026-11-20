<?php

namespace Tests\Feature;

use App\Actions\Orders\CreateOrder as CreateOrderAction;
use App\Actions\Orders\RecordPayment;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Filament\App\Resources\Orders\Pages\CreateOrder;
use App\Filament\App\Resources\Orders\Pages\ViewOrder;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OrderModuleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Branch $branch;

    private Customer $customer;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['max_branches' => 3]);
        $this->branch = Branch::factory()->for($this->tenant)->create(['code' => 'PUSAT']);
        $this->customer = Customer::factory()->for($this->tenant)->create(['name' => 'Budi Santoso']);
        $this->service = Service::factory()->for($this->tenant)->create(['name' => 'Cuci Kiloan', 'price' => 7000]);
    }

    private function staff(string $role, ?Branch $branch = null): User
    {
        $user = User::factory()->for($this->tenant)->withRole($role)->create();
        $user->branches()->attach($branch ?? $this->branch);

        return $user;
    }

    private function makeOrder(?Branch $branch = null, ?User $by = null, int $quantity = 10): Order
    {
        return app(CreateOrderAction::class)->handle(
            branch: $branch ?? $this->branch,
            user: $by ?? $this->staff('cashier'),
            customerId: $this->customer->id,
            items: [['service_id' => $this->service->id, 'quantity' => $quantity]],
        );
    }

    private function actAsInPanel(User $user, ?Branch $branch = null): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch ?? $this->branch);
    }

    public function test_order_list_only_shows_orders_of_the_current_branch_and_tenant(): void
    {
        $otherBranch = Branch::factory()->for($this->tenant)->create(['code' => 'DGO']);
        $owner = User::factory()->for($this->tenant)->withRole('tenant_owner')->create();

        $mine = $this->makeOrder();
        $elsewhere = $this->makeOrder($otherBranch);
        $foreign = Order::factory()->create();

        $this->actingAs($owner)
            ->get('/app/PUSAT/orders')
            ->assertOk()
            ->assertSee($mine->invoice_no)
            ->assertDontSee($elsewhere->invoice_no)
            ->assertDontSee($foreign->invoice_no);
    }

    public function test_order_of_another_branch_or_tenant_cannot_be_opened_through_this_branch(): void
    {
        $otherBranch = Branch::factory()->for($this->tenant)->create(['code' => 'DGO']);
        $owner = User::factory()->for($this->tenant)->withRole('tenant_owner')->create();
        $elsewhere = $this->makeOrder($otherBranch);
        $foreign = Order::factory()->create();

        $this->actingAs($owner)->get('/app/PUSAT/orders/'.$elsewhere->id)->assertNotFound();
        $this->actingAs($owner)->get('/app/PUSAT/orders/'.$foreign->id)->assertNotFound();
        $this->actingAs($owner)->get('/app/DGO/orders/'.$elsewhere->id)->assertOk();
    }

    public function test_only_cashier_level_roles_can_open_the_create_page_but_everyone_can_view_the_list(): void
    {
        foreach (['tenant_owner', 'branch_manager', 'cashier'] as $role) {
            $this->actingAs($this->staff($role))->get('/app/PUSAT/orders/create')->assertOk();
        }

        $operator = $this->staff('operator');

        $this->actingAs($operator)->get('/app/PUSAT/orders')->assertOk();
        $this->actingAs($operator)->get('/app/PUSAT/orders/create')->assertForbidden();
    }

    public function test_cashier_can_create_an_order_with_a_down_payment(): void
    {
        $cashier = $this->staff('cashier');
        $this->actAsInPanel($cashier);

        Livewire::test(CreateOrder::class)
            ->fillForm([
                'customer_id' => $this->customer->id,
                'items' => [['service_id' => $this->service->id, 'quantity' => 5]],
                'discount' => 5000,
                'down_payment' => 10000,
                'payment_method' => 'qris',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $order = Order::query()->sole();

        $this->assertSame($this->branch->id, $order->branch_id);
        $this->assertSame('30000.00', $order->total);
        $this->assertSame('10000.00', $order->paid_amount);
        $this->assertSame(PaymentStatus::Partial, $order->payment_status);
        $this->assertSame(PaymentMethod::Qris, $order->payments()->sole()->method);
        $this->assertSame($cashier->id, $order->created_by);
    }

    public function test_form_previews_subtotal_and_total_using_the_branch_price(): void
    {
        $this->actAsInPanel($this->staff('cashier'));

        Livewire::test(CreateOrder::class)
            ->fillForm([
                'items' => [['service_id' => $this->service->id, 'quantity' => 5]],
            ])
            ->set('data.discount', 5000)
            ->assertSet('data.estimated_subtotal', '35.000')
            ->assertSet('data.estimated_total', '30.000');
    }

    public function test_creating_an_order_with_a_down_payment_but_no_method_is_rejected(): void
    {
        $this->actAsInPanel($this->staff('cashier'));

        Livewire::test(CreateOrder::class)
            ->fillForm([
                'customer_id' => $this->customer->id,
                'items' => [['service_id' => $this->service->id, 'quantity' => 1]],
                'down_payment' => 1000,
            ])
            ->call('create')
            ->assertHasFormErrors(['payment_method']);

        $this->assertSame(0, Order::query()->count());
    }

    public function test_down_payment_larger_than_the_total_shows_an_error_and_creates_nothing(): void
    {
        $this->actAsInPanel($this->staff('cashier'));

        Livewire::test(CreateOrder::class)
            ->fillForm([
                'customer_id' => $this->customer->id,
                'items' => [['service_id' => $this->service->id, 'quantity' => 1]],
                'down_payment' => 999999,
                'payment_method' => 'cash',
            ])
            ->call('create');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_installments_can_be_recorded_from_the_order_page_until_fully_paid(): void
    {
        $this->actAsInPanel($this->staff('cashier'));
        $order = $this->makeOrder();

        $page = Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()]);

        $page->callAction('recordPayment', ['amount' => 20000, 'method' => 'cash', 'idempotency_key' => 'k-1'])
            ->assertNotified('Pembayaran dicatat');
        $this->assertSame(PaymentStatus::Partial, $order->fresh()->payment_status);

        $page->callAction('recordPayment', ['amount' => 50000, 'method' => 'transfer', 'reference' => 'TRX-9', 'idempotency_key' => 'k-2'])
            ->assertNotified('Pembayaran dicatat');

        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(2, $order->payments()->count());
        $page->assertActionHidden('recordPayment');
    }

    public function test_payment_above_the_remaining_balance_is_blocked_by_the_form(): void
    {
        $this->actAsInPanel($this->staff('cashier'));
        $order = $this->makeOrder();

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('recordPayment', ['amount' => 70001, 'method' => 'cash'])
            ->assertHasActionErrors(['amount']);

        $this->assertSame(0, $order->payments()->count());
    }

    public function test_only_owner_and_manager_can_refund(): void
    {
        $order = $this->makeOrder();
        app(RecordPayment::class)->handle($order, 30000, PaymentMethod::Cash);

        $this->actAsInPanel($this->staff('cashier'));
        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('refund');

        $this->actAsInPanel($this->staff('branch_manager'));
        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('refund')
            ->callAction('refund', ['amount' => 10000, 'method' => 'cash', 'notes' => 'Salah input', 'idempotency_key' => 'r-1'])
            ->assertNotified('Refund dicatat');

        $this->assertSame('20000.00', $order->fresh()->paid_amount);
    }

    public function test_status_can_be_changed_from_the_order_page_and_unpaid_completion_is_refused(): void
    {
        $this->actAsInPanel($this->staff('cashier'));
        $order = $this->makeOrder();

        $page = Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()]);

        $page->callAction('changeStatus', ['status' => 'washing', 'note' => 'Mesin 2'])
            ->assertNotified('Status diperbarui');
        $this->assertSame(OrderStatus::Washing, $order->fresh()->status);

        $page->callAction('changeStatus', ['status' => 'ready']);
        $this->assertSame(OrderStatus::Ready, $order->fresh()->status);

        $page->callAction('changeStatus', ['status' => 'completed'])
            ->assertNotified('Gagal');
        $this->assertSame(OrderStatus::Ready, $order->fresh()->status);
    }

    public function test_operator_can_update_production_status_but_cannot_record_payments(): void
    {
        $this->actAsInPanel($this->staff('operator'));
        $order = $this->makeOrder();

        $page = Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()]);

        $page->assertActionHidden('recordPayment');
        $page->callAction('changeStatus', ['status' => 'washing'])->assertNotified('Status diperbarui');
        $this->assertSame(OrderStatus::Washing, $order->fresh()->status);
    }

    public function test_staff_assigned_to_another_branch_cannot_view_the_order(): void
    {
        $otherBranch = Branch::factory()->for($this->tenant)->create(['code' => 'DGO']);
        $order = $this->makeOrder();
        $outsider = $this->staff('cashier', $otherBranch);
        $insider = $this->staff('cashier');

        $this->assertFalse($outsider->can('view', $order));
        $this->assertFalse($outsider->can('recordPayment', $order));
        $this->assertTrue($insider->can('view', $order));
        $this->assertFalse($insider->can('update', $order));
        $this->assertFalse($insider->can('delete', $order));
    }
}
