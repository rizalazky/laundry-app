<?php

namespace Tests\Feature;

use App\Actions\Orders\ChangeOrderStatus;
use App\Actions\Orders\CreateOrder;
use App\Actions\Orders\RecordPayment;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
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

class OrderReceiptTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Branch $branch;

    private Customer $customer;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['name' => 'Laundry Bersih', 'max_branches' => 3]);
        $this->branch = Branch::factory()->for($this->tenant)->create([
            'code' => 'PUSAT',
            'name' => 'Cabang Pusat',
            'address' => 'Jl. Melati No. 5',
            'phone' => '0274123456',
        ]);
        $this->customer = Customer::factory()->for($this->tenant)->create(['name' => 'Budi Santoso']);
        $this->service = Service::factory()->for($this->tenant)->create(['name' => 'Cuci Kiloan', 'price' => 7000]);
    }

    private function staff(string $role, ?Branch $branch = null): User
    {
        $user = User::factory()->for($this->tenant)->withRole($role)->create();
        $user->branches()->attach($branch ?? $this->branch);

        return $user;
    }

    private function makeOrder(?string $notes = null, mixed $discount = 0): Order
    {
        return app(CreateOrder::class)->handle(
            branch: $this->branch,
            user: $this->staff('cashier'),
            customerId: $this->customer->id,
            items: [['service_id' => $this->service->id, 'quantity' => '4.5']],
            discount: $discount,
            notes: $notes,
        );
    }

    public function test_guests_are_redirected_to_the_panel_login(): void
    {
        $order = $this->makeOrder();

        $this->get(route('orders.receipt', $order))
            ->assertRedirect(route('filament.app.auth.login'));
    }

    public function test_receipt_shows_business_customer_items_totals_and_the_remaining_balance_for_a_down_payment(): void
    {
        $order = $this->makeOrder(discount: 1500);
        app(RecordPayment::class)->handle($order, 10000, PaymentMethod::Qris, $this->staff('cashier'));

        $this->actingAs($this->staff('cashier'))
            ->get(route('orders.receipt', $order))
            ->assertOk()
            ->assertSeeInOrder([
                'Laundry Bersih',
                'Cabang Pusat',
                'Jl. Melati No. 5',
                $order->invoice_no,
                'Budi Santoso',
                'Cuci Kiloan',
                '4.5 kg x Rp 7.000',
                'Rp 31.500',
                'Diskon',
                'TOTAL',
                'Rp 30.000',
                'QRIS',
                'Rp 10.000',
                'SISA TAGIHAN',
                'Rp 20.000',
            ])
            ->assertDontSee('LUNAS');
    }

    public function test_paid_order_shows_lunas_and_refunds_appear_as_negative_entries(): void
    {
        $order = $this->makeOrder();
        $cashier = $this->staff('cashier');
        $record = app(RecordPayment::class);

        $record->handle($order, 31500, PaymentMethod::Cash, $cashier);

        $this->actingAs($cashier)
            ->get(route('orders.receipt', $order))
            ->assertOk()
            ->assertSee('LUNAS')
            ->assertDontSee('SISA TAGIHAN');

        $record->handle($order, 1500, PaymentMethod::Cash, $this->staff('branch_manager'), PaymentType::Refund);

        $this->actingAs($cashier)
            ->get(route('orders.receipt', $order))
            ->assertSee('Refund')
            ->assertSee('-Rp 1.500')
            ->assertSee('SISA TAGIHAN')
            ->assertDontSee('LUNAS');
    }

    public function test_cancelled_order_is_marked_as_cancelled(): void
    {
        $order = $this->makeOrder();
        app(ChangeOrderStatus::class)->handle($order, OrderStatus::Cancelled, $this->staff('branch_manager'));

        $this->actingAs($this->staff('cashier'))
            ->get(route('orders.receipt', $order))
            ->assertSee('DIBATALKAN')
            ->assertDontSee('SISA TAGIHAN');
    }

    public function test_user_supplied_text_is_escaped(): void
    {
        $order = $this->makeOrder(notes: '<script>alert("xss")</script>');

        $this->actingAs($this->staff('cashier'))
            ->get(route('orders.receipt', $order))
            ->assertOk()
            ->assertDontSee('<script>alert("xss")</script>', false)
            ->assertSee('&lt;script&gt;', false);
    }

    public function test_a4_layout_is_available_and_unknown_sizes_fall_back_to_thermal(): void
    {
        $order = $this->makeOrder();
        $cashier = $this->staff('cashier');

        $this->actingAs($cashier)
            ->get(route('orders.receipt', ['order' => $order, 'size' => 'a4']))
            ->assertOk()
            ->assertSee('size: A4', false);

        $this->actingAs($cashier)
            ->get(route('orders.receipt', ['order' => $order, 'size' => 'bogus']))
            ->assertOk()
            ->assertDontSee('size: A4', false);
    }

    public function test_every_branch_role_can_open_the_receipt_of_their_branch(): void
    {
        $order = $this->makeOrder();

        foreach (['tenant_owner', 'branch_manager', 'cashier', 'operator'] as $role) {
            $this->actingAs($this->staff($role))
                ->get(route('orders.receipt', $order))
                ->assertOk();
        }
    }

    public function test_staff_of_another_branch_inactive_users_and_super_admins_are_forbidden(): void
    {
        $order = $this->makeOrder();
        $otherBranch = Branch::factory()->for($this->tenant)->create(['code' => 'DGO']);
        $inactive = $this->staff('cashier');
        $inactive->update(['is_active' => false]);

        $this->actingAs($this->staff('cashier', $otherBranch))
            ->get(route('orders.receipt', $order))->assertForbidden();

        $this->actingAs($inactive)
            ->get(route('orders.receipt', $order))->assertForbidden();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('orders.receipt', $order))->assertForbidden();
    }

    public function test_receipt_of_another_tenant_is_not_found(): void
    {
        $foreign = Order::factory()->create();

        $this->actingAs($this->staff('tenant_owner'))
            ->get(route('orders.receipt', $foreign))
            ->assertNotFound();
    }

    public function test_receipt_is_blocked_when_the_subscription_has_expired(): void
    {
        $order = $this->makeOrder();
        $cashier = $this->staff('cashier');
        $this->tenant->update(['subscription_ends_at' => now()->subDay()]);

        $this->actingAs($cashier)
            ->get(route('orders.receipt', $order))
            ->assertForbidden();
    }

    public function test_order_page_and_list_link_to_the_receipt(): void
    {
        $order = $this->makeOrder();
        $cashier = $this->staff('cashier');

        $this->actingAs($cashier);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->branch);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('printReceipt')
            ->assertActionHasUrl('printReceipt', route('orders.receipt', $order));

        $this->actingAs($cashier)
            ->get('/app/PUSAT/orders')
            ->assertOk()
            ->assertSee(route('orders.receipt', $order), false);
    }
}
