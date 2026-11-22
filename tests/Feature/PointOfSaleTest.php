<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\ServiceUnit;
use App\Filament\App\Pages\PointOfSale;
use App\Models\Branch;
use App\Models\BranchServicePrice;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PointOfSaleTest extends TestCase
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
        $this->branch = Branch::factory()->for($this->tenant)->create(['code' => 'POS-01']);
        $this->customer = Customer::factory()->for($this->tenant)->create(['name' => 'Budi Santoso', 'phone' => '081234567890']);
        $this->service = Service::factory()->for($this->tenant)->create([
            'name' => 'Cuci Kiloan',
            'unit' => ServiceUnit::Kilogram,
            'price' => 7000,
        ]);
    }

    private function cashier(?Branch $branch = null): User
    {
        $user = User::factory()->for($this->tenant)->withRole('cashier')->create();
        $user->branches()->attach($branch ?? $this->branch);

        foreach (['orders.view', 'orders.create', 'orders.payment'] as $slug) {
            $permission = Permission::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'group' => 'Pesanan'],
            );

            RolePermission::query()->firstOrCreate([
                'tenant_id' => $this->tenant->id,
                'role' => 'cashier',
                'permission_id' => $permission->id,
            ]);
        }

        return $user;
    }

    private function actAsCashier(?Branch $branch = null): User
    {
        $user = $this->cashier($branch);
        $this->actingAs($user);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch ?? $this->branch);

        return $user;
    }

    public function test_pos_requires_a_customer_before_showing_the_service_catalog(): void
    {
        $this->actAsCashier();

        Livewire::test(PointOfSale::class)
            ->assertSee('Pilih pelanggan terlebih dahulu')
            ->assertDontSee('Cuci Kiloan')
            ->call('selectCustomer', $this->customer->id)
            ->assertSee('Cuci Kiloan')
            ->assertDontSee('Pilih pelanggan terlebih dahulu');
    }

    public function test_cart_action_is_available_after_selecting_a_customer(): void
    {
        $this->actAsCashier();

        Livewire::test(PointOfSale::class)
            ->assertDontSee('Keranjang')
            ->call('selectCustomer', $this->customer->id)
            ->assertSee('Keranjang');
    }

    public function test_quick_add_customer_callback_creates_and_selects_a_customer_for_the_current_tenant(): void
    {
        $this->actAsCashier();

        $page = Livewire::test(PointOfSale::class)
            ->call('createCustomerOption', [
                'name' => 'Pelanggan Walk In',
                'phone' => '081299988877',
            ])
            ->assertHasNoErrors()
            ->assertSet('customerId', Customer::query()->where('name', 'Pelanggan Walk In')->value('id'))
            ->assertSee('Cuci Kiloan');

        $this->assertDatabaseHas('customers', [
            'tenant_id' => $this->tenant->id,
            'name' => 'Pelanggan Walk In',
            'phone' => '6281299988877',
        ]);
    }

    public function test_cashier_cannot_add_services_before_selecting_a_customer(): void
    {
        $this->actAsCashier();

        Livewire::test(PointOfSale::class)
            ->call('addToCart', $this->service->id)
            ->assertForbidden();

        $this->assertSame(0, Order::query()->count());
    }

    public function test_touchscreen_pos_shows_only_active_services_from_the_current_tenant(): void
    {
        $inactive = Service::factory()->for($this->tenant)->inactive()->create(['name' => 'Layanan Nonaktif']);
        $foreign = Service::factory()->create(['name' => 'Layanan Tenant Lain']);

        $this->actAsCashier();

        Livewire::test(PointOfSale::class)
            ->call('selectCustomer', $this->customer->id)
            ->assertSee($this->service->name)
            ->assertDontSee($inactive->name)
            ->assertDontSee($foreign->name);
    }

    public function test_cashier_can_create_an_order_with_two_payment_methods_and_open_its_receipt(): void
    {
        $cashier = $this->actAsCashier();

        $page = Livewire::test(PointOfSale::class)
            ->call('selectCustomer', $this->customer->id)
            ->call('addToCart', $this->service->id)
            ->call('updateQuantity', $this->service->id, '5')
            ->set('discount', '5000')
            ->call('openPaymentModal')
            ->set('paymentLines', [
                ['amount' => '10000', 'method' => PaymentMethod::Cash->value, 'reference' => ''],
                ['amount' => '20000', 'method' => PaymentMethod::Qris->value, 'reference' => 'QR-123'],
            ])
            ->call('createOrder')
            ->assertHasNoErrors()
            ->assertNotified('Order berhasil dibuat');

        $order = Order::query()->sole();

        $this->assertSame('30000.00', $order->total);
        $this->assertSame('30000.00', $order->paid_amount);
        $this->assertSame(2, $order->payments()->count());
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'method' => PaymentMethod::Cash->value,
            'amount' => '10000.00',
        ]);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'method' => PaymentMethod::Qris->value,
            'amount' => '20000.00',
            'reference' => 'QR-123',
        ]);

        $page
            ->assertSee($order->invoice_no)
            ->assertSee(route('orders.receipt', $order), false);

        $this->actingAs($cashier)
            ->get(route('orders.receipt', $order))
            ->assertOk()
            ->assertSee($order->invoice_no);
    }

    public function test_mobile_cart_action_closes_before_opening_the_payment_modal(): void
    {
        $this->actAsCashier();

        Livewire::test(PointOfSale::class)
            ->call('selectCustomer', $this->customer->id)
            ->call('addToCart', $this->service->id)
            ->mountAction('cart')
            ->assertSee('Lanjut Pembayaran')
            ->assertSee('close();setTimeout(() => $wire.openPaymentModal(), 200);', false)
            ->call('openPaymentModal')
            ->assertSet('paymentModalOpen', true)
            ->assertNotDispatched('close-modal')
            ->assertSee('Pembayaran')
            ->assertSee('wire:click="createOrder"', false);
    }

    public function test_payment_amount_field_uses_live_binding_and_remaining_balance_updates(): void
    {
        $this->actAsCashier();

        Livewire::test(PointOfSale::class)
            ->call('selectCustomer', $this->customer->id)
            ->call('addToCart', $this->service->id)
            ->call('openPaymentModal')
            ->assertSee('wire:model.live.debounce.300ms="paymentLines.0.amount"', false)
            ->set('paymentLines.0.amount', '3000')
            ->assertSet('paymentLines.0.amount', '3000')
            ->assertSee('Rp 4.000');
    }

    public function test_cashier_can_create_a_partially_paid_order_when_enabled(): void
    {
        $this->actAsCashier();

        Livewire::test(PointOfSale::class)
            ->call('selectCustomer', $this->customer->id)
            ->call('addToCart', $this->service->id)
            ->set('paymentLines', [
                ['amount' => '3000', 'method' => PaymentMethod::Transfer->value, 'reference' => 'BANK-1'],
            ])
            ->call('createOrder')
            ->assertHasNoErrors();

        $order = Order::query()->sole();

        $this->assertSame('7000.00', $order->total);
        $this->assertSame('3000.00', $order->paid_amount);
    }

    public function test_zero_total_order_can_be_saved_without_a_payment_line(): void
    {
        $this->actAsCashier();

        Livewire::test(PointOfSale::class)
            ->call('selectCustomer', $this->customer->id)
            ->call('addToCart', $this->service->id)
            ->set('discount', '7000')
            ->call('openPaymentModal')
            ->assertSet('paymentLines', [])
            ->call('createOrder')
            ->assertHasNoErrors();

        $order = Order::query()->sole();

        $this->assertSame('0.00', $order->total);
        $this->assertSame('0.00', $order->paid_amount);
        $this->assertSame('paid', $order->payment_status->value);
        $this->assertSame(0, $order->payments()->count());
    }

    public function test_partial_payment_is_rejected_when_the_tenant_disables_it(): void
    {
        $this->tenant->updateGeneralSettings(['allow_partial_payment' => false]);
        $this->actAsCashier();

        Livewire::test(PointOfSale::class)
            ->call('selectCustomer', $this->customer->id)
            ->call('addToCart', $this->service->id)
            ->set('paymentLines', [
                ['amount' => '3000', 'method' => PaymentMethod::Cash->value, 'reference' => ''],
            ])
            ->call('createOrder')
            ->assertHasErrors();

        $this->assertSame(0, Order::query()->count());
    }

    public function test_payment_above_order_total_is_rejected_without_creating_an_order(): void
    {
        $this->actAsCashier();

        Livewire::test(PointOfSale::class)
            ->call('selectCustomer', $this->customer->id)
            ->call('addToCart', $this->service->id)
            ->set('paymentLines', [
                ['amount' => '9000', 'method' => PaymentMethod::Cash->value, 'reference' => ''],
            ])
            ->call('createOrder')
            ->assertHasErrors();

        $this->assertSame(0, Order::query()->count());
    }

    public function test_fractional_quantity_is_rejected_for_piece_services(): void
    {
        $piece = Service::factory()->for($this->tenant)->create([
            'name' => 'Laundry Sepatu',
            'unit' => ServiceUnit::Piece,
            'price' => 25000,
        ]);
        $this->actAsCashier();

        Livewire::test(PointOfSale::class)
            ->call('selectCustomer', $this->customer->id)
            ->call('addToCart', $piece->id)
            ->call('updateQuantity', $piece->id, '1.5')
            ->assertHasErrors();

        $this->assertSame(0, Order::query()->count());
    }

    public function test_customer_from_another_tenant_cannot_be_selected(): void
    {
        $foreignTenant = Tenant::factory()->create();
        $foreignCustomer = Customer::factory()->for($foreignTenant)->create();

        $this->actAsCashier();

        Livewire::test(PointOfSale::class)
            ->call('selectCustomer', $foreignCustomer->id)
            ->assertNotFound();

        $this->assertSame(0, Order::query()->count());
    }

    public function test_branch_specific_service_price_is_used_in_the_cart_and_saved_order(): void
    {
        $cashier = $this->actAsCashier();
        BranchServicePrice::query()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'service_id' => $this->service->id,
            'price' => '9000.00',
        ]);

        Livewire::test(PointOfSale::class)
            ->call('selectCustomer', $this->customer->id)
            ->call('addToCart', $this->service->id)
            ->assertSee('Rp 9.000')
            ->call('createOrder')
            ->assertHasNoErrors();

        $order = Order::query()->sole();
        $this->assertSame('9000.00', $order->total);
        $this->assertSame('9000.00', $order->items()->sole()->unit_price);
    }
}
