<?php

namespace Tests\Feature;

use App\Actions\Orders\ChangeOrderStatus;
use App\Actions\Orders\CreateOrder;
use App\Actions\Orders\RecordPayment;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Journal;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderAccountingPostingTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_creation_recognizes_revenue_and_records_receivable(): void
    {
        [$tenant, $branch, $cashier, $customer, $service] = $this->makeOrderSetup();

        $order = app(CreateOrder::class)->handle(
            branch: $branch,
            user: $cashier,
            customerId: $customer->id,
            items: [['service_id' => $service->id, 'quantity' => 2]],
        );

        $journal = $this->journalFor($tenant->id, 'order', $order->id);

        $this->assertSame(
            [
                '1100' => ['debit' => '14000.00', 'credit' => '0.00'],
                '4000' => ['debit' => '0.00', 'credit' => '14000.00'],
            ],
            $this->journalEntriesByCode($journal),
        );
    }

    public function test_payment_posts_to_the_asset_account_for_its_method_and_reduces_receivable(): void
    {
        [$tenant, $branch, $cashier, $customer, $service] = $this->makeOrderSetup();

        $order = app(CreateOrder::class)->handle(
            branch: $branch,
            user: $cashier,
            customerId: $customer->id,
            items: [['service_id' => $service->id, 'quantity' => 2]],
        );

        $payment = app(RecordPayment::class)->handle(
            order: $order,
            amount: '14000.00',
            method: PaymentMethod::Qris,
            user: $cashier,
        );

        $journal = $this->journalFor($tenant->id, 'payment', $payment->id);

        $this->assertSame(
            [
                '1020' => ['debit' => '14000.00', 'credit' => '0.00'],
                '1100' => ['debit' => '0.00', 'credit' => '14000.00'],
            ],
            $this->journalEntriesByCode($journal),
        );
        $this->assertSame('0.00', $order->fresh()->balanceDue());
        $this->assertSame(2, Journal::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count());
    }

    public function test_all_payment_methods_post_to_their_configured_asset_accounts(): void
    {
        $methods = [
            [PaymentMethod::Cash, '1000'],
            [PaymentMethod::Transfer, '1010'],
            [PaymentMethod::Qris, '1020'],
            [PaymentMethod::EdcDebit, '1030'],
            [PaymentMethod::EdcCredit, '1030'],
        ];

        [$tenant, $branch, $cashier, $customer, $service] = $this->makeOrderSetup();

        foreach ($methods as [$method, $assetCode]) {
            $order = app(CreateOrder::class)->handle(
                branch: $branch,
                user: $cashier,
                customerId: $customer->id,
                items: [['service_id' => $service->id, 'quantity' => 1]],
            );

            $payment = app(RecordPayment::class)->handle(
                order: $order,
                amount: '7000.00',
                method: $method,
                user: $cashier,
            );

            $journal = $this->journalFor($tenant->id, 'payment', $payment->id);

            $this->assertSame(
                [
                    $assetCode => ['debit' => '7000.00', 'credit' => '0.00'],
                    '1100' => ['debit' => '0.00', 'credit' => '7000.00'],
                ],
                $this->journalEntriesByCode($journal),
                'Payment method '.$method->value.' should post to account '.$assetCode.'.',
            );
        }
    }

    public function test_payment_retry_does_not_duplicate_payment_or_accounting_journal(): void
    {
        [$tenant, $branch, $cashier, $customer, $service] = $this->makeOrderSetup();

        $order = app(CreateOrder::class)->handle(
            branch: $branch,
            user: $cashier,
            customerId: $customer->id,
            items: [['service_id' => $service->id, 'quantity' => 2]],
        );

        $arguments = [
            'order' => $order,
            'amount' => '14000.00',
            'method' => PaymentMethod::Cash,
            'user' => $cashier,
            'idempotencyKey' => 'cashier-retry-001',
        ];

        $first = app(RecordPayment::class)->handle(...$arguments);
        $retry = app(RecordPayment::class)->handle(...$arguments);

        $this->assertSame($first->id, $retry->id);
        $this->assertSame(1, $order->payments()->count());
        $this->assertSame(1, Journal::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('reference_type', 'payment')
            ->where('reference_id', $first->id)
            ->count());
    }

    public function test_refund_reverses_the_original_payment_asset_movement(): void
    {
        [$tenant, $branch, $cashier, $customer, $service] = $this->makeOrderSetup();

        $order = app(CreateOrder::class)->handle(
            branch: $branch,
            user: $cashier,
            customerId: $customer->id,
            items: [['service_id' => $service->id, 'quantity' => 2]],
        );

        app(RecordPayment::class)->handle(
            order: $order,
            amount: '14000.00',
            method: PaymentMethod::Cash,
            user: $cashier,
        );

        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $this->actingAs($owner);

        $refund = app(RecordPayment::class)->handle(
            order: $order,
            amount: '2000.00',
            method: PaymentMethod::Cash,
            user: $owner,
            type: PaymentType::Refund,
        );

        $journal = $this->journalFor($tenant->id, 'payment', $refund->id);

        $this->assertSame(
            [
                '1000' => ['debit' => '0.00', 'credit' => '2000.00'],
                '1100' => ['debit' => '2000.00', 'credit' => '0.00'],
            ],
            $this->journalEntriesByCode($journal),
        );
        $this->assertSame('12000.00', $order->fresh()->paid_amount);
    }

    public function test_cancelling_an_unpaid_order_reverses_the_revenue_and_receivable(): void
    {
        [$tenant, $branch, $cashier, $customer, $service] = $this->makeOrderSetup();

        $order = app(CreateOrder::class)->handle(
            branch: $branch,
            user: $cashier,
            customerId: $customer->id,
            items: [['service_id' => $service->id, 'quantity' => 2]],
        );

        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $this->actingAs($owner);

        app(ChangeOrderStatus::class)->handle(
            order: $order,
            to: OrderStatus::Cancelled,
            user: $owner,
        );

        $journal = $this->journalFor($tenant->id, 'order_cancellation', $order->id);

        $this->assertSame(
            [
                '1100' => ['debit' => '0.00', 'credit' => '14000.00'],
                '4000' => ['debit' => '14000.00', 'credit' => '0.00'],
            ],
            $this->journalEntriesByCode($journal),
        );
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_zero_value_order_does_not_create_revenue_journal(): void
    {
        [$tenant, $branch, $cashier, $customer, $service] = $this->makeOrderSetup();

        $order = app(CreateOrder::class)->handle(
            branch: $branch,
            user: $cashier,
            customerId: $customer->id,
            items: [['service_id' => $service->id, 'quantity' => 1]],
            discount: '7000.00',
        );

        $this->assertSame(0, Journal::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('reference_type', 'order')
            ->where('reference_id', $order->id)
            ->count());
    }

    private function makeOrderSetup(): array
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $cashier = User::factory()->for($tenant)->withRole('cashier')->create();
        $cashier->branches()->attach($branch);
        $customer = Customer::factory()->for($tenant)->create();
        $service = Service::factory()->for($tenant)->create(['price' => '7000.00']);

        $this->actingAs($cashier);

        return [$tenant, $branch, $cashier, $customer, $service];
    }

    private function journalFor(int $tenantId, string $referenceType, int $referenceId): Journal
    {
        return Journal::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->with('entries.account')
            ->sole();
    }

    private function journalEntriesByCode(Journal $journal): array
    {
        return $journal->entries->mapWithKeys(fn ($entry): array => [
            $entry->account->code => [
                'debit' => $entry->debit,
                'credit' => $entry->credit,
            ],
        ])->sortKeys()->all();
    }
}
