<?php

namespace Tests\Feature;

use App\Actions\Orders\CreateOrder;
use App\Actions\Orders\RecordPayment;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentLedgerReplaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sqlite_replace_cannot_overwrite_payment_by_id_or_idempotency_key(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $cashier = User::factory()->for($tenant)->withRole('cashier')->create();
        $cashier->branches()->attach($branch);
        $customer = Customer::factory()->for($tenant)->create();
        $service = Service::factory()->for($tenant)->create();

        $order = app(CreateOrder::class)->handle(
            branch: $branch,
            user: $cashier,
            customerId: $customer->id,
            items: [['service_id' => $service->id, 'quantity' => 1]],
        );

        $payment = app(RecordPayment::class)->handle(
            order: $order,
            amount: 1000,
            method: PaymentMethod::Cash,
            user: $cashier,
            idempotencyKey: 'immutable-payment-key',
        );

        $attributes = $payment->getRawOriginal();
        $this->assertSame('0.00', $payment->change_amount);
        $columns = ['id', 'tenant_id', 'branch_id', 'order_id', 'received_by', 'type', 'method', 'amount', 'change_amount', 'paid_at', 'reference', 'notes', 'idempotency_key', 'created_at', 'updated_at'];
        $values = array_map(fn (string $column) => $attributes[$column] ?? null, $columns);

        foreach ([
            array_replace($values, [7 => '1.00']),
            array_replace($values, [0 => $payment->id + 1000, 7 => '1.00']),
        ] as $replacement) {
            if ($replacement[0] !== $payment->id) {
                $replacement[12] = 'immutable-payment-key';
            }

            try {
                DB::statement(
                    'INSERT OR REPLACE INTO payments ('.implode(', ', $columns).') VALUES ('.implode(', ', array_fill(0, count($columns), '?')).')',
                    $replacement,
                );
                $this->fail('SQLite REPLACE must not overwrite an existing ledger entry.');
            } catch (QueryException) {
                $this->assertDatabaseHas('payments', [
                    'id' => $payment->id,
                    'amount' => '1000.00',
                    'idempotency_key' => 'immutable-payment-key',
                ]);
                $this->assertSame(1, Payment::query()->where('order_id', $order->id)->count());
            }
        }
    }
}
