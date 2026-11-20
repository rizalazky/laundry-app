<?php

namespace Tests\Feature;

use App\Actions\Orders\ChangeOrderStatus;
use App\Actions\Orders\CreateOrder;
use App\Actions\Orders\RecordPayment;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderFinancialSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_guards_keep_order_financial_snapshots_and_items_immutable(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create(['code' => 'PUSAT']);
        $cashier = User::factory()->for($tenant)->withRole('cashier')->create();
        $cashier->branches()->attach($branch);
        $customer = Customer::factory()->for($tenant)->create();
        $service = Service::factory()->for($tenant)->create(['price' => 7000]);

        $order = app(CreateOrder::class)->handle(
            branch: $branch,
            user: $cashier,
            customerId: $customer->getKey(),
            items: [['service_id' => $service->getKey(), 'quantity' => 2]],
        );

        $item = $order->items()->sole();

        $attempts = [
            fn () => DB::table('orders')->where('id', $order->getKey())->update(['total' => '1.00']),
            fn () => DB::table('orders')->where('id', $order->getKey())->update(['subtotal' => '1.00']),
            fn () => DB::table('orders')->where('id', $order->getKey())->update(['invoice_no' => 'ALTERED']),
            fn () => DB::table('order_items')->where('id', $item->getKey())->update(['quantity' => '99.00']),
            fn () => DB::table('order_items')->where('id', $item->getKey())->delete(),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
                $this->fail('Database guards must reject changes to historical order snapshots.');
            } catch (QueryException) {
                $this->assertDatabaseHas('orders', [
                    'id' => $order->getKey(),
                    'invoice_no' => $order->invoice_no,
                    'subtotal' => '14000.00',
                    'total' => '14000.00',
                ]);
                $this->assertDatabaseHas('order_items', [
                    'id' => $item->getKey(),
                    'quantity' => '2.00',
                    'subtotal' => '14000.00',
                ]);
            }
        }

        app(RecordPayment::class)->handle($order, 1000, PaymentMethod::Cash, $cashier);
        app(ChangeOrderStatus::class)->handle($order, OrderStatus::Washing, $cashier);

        $this->assertSame('1000.00', $order->fresh()->paid_amount);
        $this->assertSame(OrderStatus::Washing, $order->fresh()->status);
        $this->assertSame('14000.00', $order->fresh()->total);
    }
}
