<?php

namespace Tests\Feature;

use App\Actions\Orders\CreateOrder as CreateOrderAction;
use App\Filament\App\Resources\Orders\Pages\CreateOrder;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Promo;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voucher;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Livewire\Livewire;
use Tests\TestCase;

class PromoVoucherCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Branch $branch;

    private Customer $customer;

    private Service $service;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['max_branches' => 2]);
        $this->branch = Branch::factory()->for($this->tenant)->create(['code' => 'PUSAT']);
        $this->customer = Customer::factory()->for($this->tenant)->create();
        $this->service = Service::factory()->for($this->tenant)->create(['price' => 7000]);
        $this->owner = User::factory()->for($this->tenant)->withRole('tenant_owner')->create();
    }

    private function makePromo(array $attributes = []): Promo
    {
        return Promo::query()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'name' => 'Promo Hemat',
            'category' => Promo::CATEGORY_GENERAL,
            'reward_type' => Promo::REWARD_PERCENT,
            'reward_value' => 10,
            'is_active' => true,
            'rules' => ['minimum_order' => '50000'],
        ], $attributes));
    }

    private function makeVoucher(Promo $promo, array $attributes = []): Voucher
    {
        return Voucher::query()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'promo_id' => $promo->id,
            'code' => 'HEMAT10',
            'voucher_type' => Voucher::TYPE_GENERAL,
            'quota' => 5,
            'used_count' => 0,
            'is_active' => true,
        ], $attributes));
    }

    private function createOrder(?string $voucherCode = null, string|int|float $manualDiscount = 0): Order
    {
        return app(CreateOrderAction::class)->handle(
            branch: $this->branch,
            user: $this->owner,
            customerId: $this->customer->id,
            items: [['service_id' => $this->service->id, 'quantity' => 10]],
            discount: $manualDiscount,
            voucherCode: $voucherCode,
        );
    }

    public function test_order_checkout_applies_percentage_voucher_and_saves_immutable_discount_snapshots(): void
    {
        $promo = $this->makePromo();
        $voucher = $this->makeVoucher($promo);

        $order = $this->createOrder(' hemat10 ', 1000);

        $this->assertSame('70000.00', $order->subtotal);
        $this->assertSame('7000.00', $order->promo_discount);
        $this->assertSame('8000.00', $order->discount);
        $this->assertSame('62000.00', $order->total);
        $this->assertSame($promo->id, $order->promo_id);
        $this->assertSame($voucher->id, $order->voucher_id);
        $this->assertSame('Promo Hemat', $order->promo_name_snapshot);
        $this->assertSame('HEMAT10', $order->voucher_code_snapshot);
        $this->assertSame(1, $voucher->fresh()->used_count);
    }

    public function test_checkout_rejects_expired_or_exhausted_vouchers_without_creating_orders(): void
    {
        $promo = $this->makePromo();

        $expiredVoucher = $this->makeVoucher($promo, [
            'code' => 'EXPIRED',
            'expires_at' => now()->subDay(),
        ]);

        try {
            $this->createOrder('EXPIRED');
            $this->fail('An expired voucher must not be accepted.');
        } catch (DomainException $exception) {
            $this->assertSame('Voucher belum berlaku atau sudah kedaluwarsa.', $exception->getMessage());
        }

        $exhaustedVoucher = $this->makeVoucher($promo, [
            'code' => 'SOLDOUT',
            'quota' => 1,
            'used_count' => 1,
        ]);

        try {
            $this->createOrder('SOLDOUT');
            $this->fail('A voucher with no remaining quota must not be accepted.');
        } catch (DomainException $exception) {
            $this->assertSame('Kuota voucher sudah habis.', $exception->getMessage());
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, $expiredVoucher->fresh()->used_count);
        $this->assertSame(1, $exhaustedVoucher->fresh()->used_count);
    }

    public function test_checkout_rejects_vouchers_from_another_tenant_and_below_minimum_subtotal(): void
    {
        $promo = $this->makePromo();
        $otherTenant = Tenant::factory()->create();
        $foreignPromo = Promo::query()->create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Promo Tenant Lain',
            'category' => Promo::CATEGORY_GENERAL,
            'reward_type' => Promo::REWARD_FIXED,
            'reward_value' => 5000,
            'is_active' => true,
        ]);
        $foreignVoucher = Voucher::query()->create([
            'tenant_id' => $otherTenant->id,
            'promo_id' => $foreignPromo->id,
            'code' => 'FOREIGN',
            'voucher_type' => Voucher::TYPE_GENERAL,
            'is_active' => true,
        ]);

        try {
            $this->createOrder('FOREIGN');
            $this->fail('A voucher belonging to another tenant must not be accepted.');
        } catch (DomainException $exception) {
            $this->assertSame('Voucher tidak ditemukan atau tidak aktif.', $exception->getMessage());
        }

        $promo->update(['rules' => ['minimum_order' => '100000']]);
        $this->makeVoucher($promo, ['code' => 'MINIMUM']);

        try {
            $this->createOrder('MINIMUM');
            $this->fail('A promo minimum order must be enforced.');
        } catch (DomainException $exception) {
            $this->assertSame('Subtotal belum memenuhi minimum transaksi promo.', $exception->getMessage());
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, $foreignVoucher->fresh()->used_count);
    }

    public function test_order_creation_form_passes_the_voucher_code_to_checkout(): void
    {
        $promo = $this->makePromo(['rules' => []]);
        $voucher = $this->makeVoucher($promo);
        $this->actingAs($this->owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->branch);

        Livewire::test(CreateOrder::class)
            ->fillForm([
                'customer_id' => $this->customer->id,
                'items' => [['service_id' => $this->service->id, 'quantity' => 10]],
                'voucher_code' => 'HEMAT10',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $order = Order::query()->sole();

        $this->assertSame($voucher->id, $order->voucher_id);
        $this->assertSame('63000.00', $order->total);
        $this->assertSame(1, $voucher->fresh()->used_count);
    }
    public function test_promotion_financial_snapshots_cannot_be_modified_after_order_creation(): void
    {
        $promo = $this->makePromo(['rules' => []]);
        $this->makeVoucher($promo);

        $order = $this->createOrder('HEMAT10');

        $this->expectException(QueryException::class);

        $order->update(['promo_discount' => '1.00']);
    }

}
