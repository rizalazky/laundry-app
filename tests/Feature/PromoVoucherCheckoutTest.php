<?php

namespace Tests\Feature;

use App\Actions\Orders\CreateOrder as CreateOrderAction;
use App\Filament\App\Resources\Orders\Pages\CreateOrder;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerMembership;
use App\Models\MemberTier;
use App\Models\Order;
use App\Models\Promo;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voucher;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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


    public function test_membership_discount_stacks_with_voucher_and_is_saved_as_immutable_snapshots(): void
    {
        $tier = MemberTier::query()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Gold',
            'price' => 100000,
            'duration_days' => 30,
            'discount_percent' => 10,
            'is_active' => true,
        ]);

        $membership = CustomerMembership::query()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'member_tier_id' => $tier->id,
            'started_at' => now()->subDay(),
            'expired_at' => now()->addDays(29),
            'status' => CustomerMembership::STATUS_ACTIVE,
        ]);

        $promo = $this->makePromo();
        $voucher = $this->makeVoucher($promo);

        $order = $this->createOrder('HEMAT10', 1000);

        $this->assertSame('70000.00', $order->subtotal);
        $this->assertSame('1000.00', $order->manual_discount);
        $this->assertSame('7000.00', $order->membership_discount);
        $this->assertSame('10.00', $order->membership_discount_percent_snapshot);
        $this->assertSame('Gold', $order->member_tier_name_snapshot);
        $this->assertSame($membership->id, $order->customer_membership_id);
        $this->assertSame($tier->id, $order->member_tier_id);
        $this->assertSame('7000.00', $order->promo_discount);
        $this->assertSame('15000.00', $order->discount);
        $this->assertSame('55000.00', $order->total);
        $this->assertSame('HEMAT10', $order->voucher_code_snapshot);
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
    public function test_referral_vouchers_and_unsupported_promo_rules_are_not_applied_as_general_discounts(): void
    {
        $promo = $this->makePromo(['rules' => []]);
        $this->makeVoucher($promo, [
            'code' => 'REFERRAL',
            'voucher_type' => Voucher::TYPE_REFERRAL,
        ]);

        try {
            $this->createOrder('REFERRAL');
            $this->fail('Referral vouchers must not be accepted as general vouchers.');
        } catch (DomainException $exception) {
            $this->assertSame('Voucher referral belum dapat digunakan pada checkout umum.', $exception->getMessage());
        }

        $promo->update(['rules' => ['minimum_order' => '50000', 'first_order_only' => 'true']]);
        $this->makeVoucher($promo, ['code' => 'UNSUPPORTED']);

        try {
            $this->createOrder('UNSUPPORTED');
            $this->fail('Unsupported promotion rules must not be silently ignored.');
        } catch (DomainException $exception) {
            $this->assertSame('Promo memiliki ketentuan yang belum didukung pada checkout.', $exception->getMessage());
        }

        $this->assertSame(0, Order::query()->count());
    }


    public function test_fixed_amount_promotion_applies_exact_amount_and_never_exceeds_subtotal(): void
    {
        $promo = $this->makePromo([
            'reward_type' => Promo::REWARD_FIXED,
            'reward_value' => 15000,
            'rules' => [],
        ]);
        $this->makeVoucher($promo, ['code' => 'FIXED15']);

        $order = $this->createOrder('FIXED15');

        $this->assertSame('70000.00', $order->subtotal);
        $this->assertSame('15000.00', $order->promo_discount);
        $this->assertSame('55000.00', $order->total);

        $cappedPromo = $this->makePromo([
            'name' => 'Promo Gratis',
            'reward_type' => Promo::REWARD_FIXED,
            'reward_value' => 100000,
            'rules' => [],
        ]);
        $this->makeVoucher($cappedPromo, ['code' => 'FREEORDER']);

        $freeOrder = $this->createOrder('FREEORDER');

        $this->assertSame('70000.00', $freeOrder->subtotal);
        $this->assertSame('70000.00', $freeOrder->promo_discount);
        $this->assertSame('0.00', $freeOrder->total);
    }

    public function test_percentage_promotion_rounds_discount_to_two_decimal_places_using_half_up(): void
    {
        $this->service->update(['price' => 7001]);

        $promo = $this->makePromo([
            'reward_value' => 12.35,
            'rules' => [],
        ]);
        $this->makeVoucher($promo, ['code' => 'ROUND1235']);

        $order = $this->createOrder('ROUND1235');

        $this->assertSame('70010.00', $order->subtotal);
        $this->assertSame('8646.24', $order->promo_discount);
        $this->assertSame('61363.76', $order->total);
    }

    public function test_promotion_requires_valid_positive_reward_values(): void
    {
        $invalidPercentage = $this->makePromo([
            'reward_value' => 100.01,
            'rules' => [],
        ]);
        $percentageVoucher = $this->makeVoucher($invalidPercentage, ['code' => 'TOOMUCH']);

        try {
            $this->createOrder('TOOMUCH');
            $this->fail('A percentage reward above 100 must be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame('Persentase promo harus lebih dari 0 dan maksimal 100%.', $exception->getMessage());
        }

        $zeroFixedPromo = $this->makePromo([
            'reward_type' => Promo::REWARD_FIXED,
            'reward_value' => 0,
            'rules' => [],
        ]);
        $fixedVoucher = $this->makeVoucher($zeroFixedPromo, ['code' => 'ZEROAMOUNT']);

        try {
            $this->createOrder('ZEROAMOUNT');
            $this->fail('A fixed reward of zero must be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame('Nominal promo harus lebih dari 0.', $exception->getMessage());
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, $percentageVoucher->fresh()->used_count);
        $this->assertSame(0, $fixedVoucher->fresh()->used_count);
    }

    public function test_promotion_minimum_order_accepts_subtotal_equal_to_the_threshold(): void
    {
        $promo = $this->makePromo(['rules' => ['minimum_order' => '70000']]);
        $voucher = $this->makeVoucher($promo, ['code' => 'EXACTMIN']);

        $order = $this->createOrder('EXACTMIN');

        $this->assertSame('70000.00', $order->subtotal);
        $this->assertSame('7000.00', $order->promo_discount);
        $this->assertSame('63000.00', $order->total);
        $this->assertSame(1, $voucher->fresh()->used_count);
    }


    public function test_checkout_rejects_inactive_and_not_yet_started_vouchers_without_consuming_quota(): void
    {
        $promo = $this->makePromo(['rules' => []]);

        $inactiveVoucher = $this->makeVoucher($promo, [
            'code' => 'INACTIVE',
            'is_active' => false,
        ]);
        $futureVoucher = $this->makeVoucher($promo, [
            'code' => 'FUTURE',
            'starts_at' => now()->addDay(),
        ]);

        foreach ([
            'INACTIVE' => 'Voucher tidak ditemukan atau tidak aktif.',
            'FUTURE' => 'Voucher belum berlaku atau sudah kedaluwarsa.',
        ] as $code => $expectedMessage) {
            try {
                $this->createOrder($code);
                $this->fail("Voucher {$code} must not be accepted.");
            } catch (DomainException $exception) {
                $this->assertSame($expectedMessage, $exception->getMessage());
            }
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, $inactiveVoucher->fresh()->used_count);
        $this->assertSame(0, $futureVoucher->fresh()->used_count);
    }

    public function test_checkout_rejects_inactive_future_and_ended_promos_without_consuming_voucher_quota(): void
    {
        $cases = [
            [
                'name' => 'Promo Nonaktif',
                'code' => 'PROMOINACTIVE',
                'attributes' => ['is_active' => false],
                'message' => 'Promo untuk voucher ini tidak aktif.',
            ],
            [
                'name' => 'Promo Belum Mulai',
                'code' => 'PROMOFUTURE',
                'attributes' => ['start_at' => now()->addDay()],
                'message' => 'Promo belum berlaku atau sudah berakhir.',
            ],
            [
                'name' => 'Promo Berakhir',
                'code' => 'PROMOENDED',
                'attributes' => ['end_at' => now()->subSecond()],
                'message' => 'Promo belum berlaku atau sudah berakhir.',
            ],
        ];

        $vouchers = [];

        foreach ($cases as $case) {
            $promo = $this->makePromo(array_merge([
                'name' => $case['name'],
                'rules' => [],
            ], $case['attributes']));

            $vouchers[] = $this->makeVoucher($promo, [
                'code' => $case['code'],
            ]);

            try {
                $this->createOrder($case['code']);
                $this->fail("{$case['name']} must not be accepted.");
            } catch (DomainException $exception) {
                $this->assertSame($case['message'], $exception->getMessage());
            }
        }

        $this->assertSame(0, Order::query()->count());

        foreach ($vouchers as $voucher) {
            $this->assertSame(0, $voucher->fresh()->used_count);
        }
    }

    public function test_voucher_expiring_at_the_current_time_is_still_valid_only_before_the_boundary(): void
    {
        $promo = $this->makePromo(['rules' => []]);
        $voucher = $this->makeVoucher($promo, [
            'code' => 'BOUNDARY',
            'expires_at' => now()->subSecond(),
        ]);

        try {
            $this->createOrder('BOUNDARY');
            $this->fail('An already expired voucher must not be accepted.');
        } catch (DomainException $exception) {
            $this->assertSame('Voucher belum berlaku atau sudah kedaluwarsa.', $exception->getMessage());
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, $voucher->fresh()->used_count);
    }
}
