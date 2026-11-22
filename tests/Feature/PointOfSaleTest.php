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

    public function test_touchscreen_pos_shows_only_active_services_from_the_current_tenant(): void
    {
        $inactive = Service::factory()->for($this->tenant)->inactive()->create(['name' => 'Layanan Nonaktif']);
        $foreign = Service::factory()->create(['name' => 'Layanan Tenant Lain']);

        $this->actAsCashier();

        Livewire::test(PointOfSale::class)
            ->assertSee('Kasir Laundry')
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
            ->call('addToCart', $this->service->id)
            ->assertSee('Rp 9.000')
            ->call('selectCustomer', $this->customer->id)
            ->call('createOrder')
            ->assertHasNoErrors();

        $order = Order::query()->sole();
        $this->assertSame('9000.00', $order->total);
        $this->assertSame('9000.00', $order->items()->sole()->unit_price);
    }
}
