<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\Service;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

class CreateOrder
{
    public function __construct(
        private GenerateInvoiceNumber $generateInvoiceNumber,
        private RecordPayment $recordPayment,
    ) {}

    /**
     * Create an order with priced line items and, optionally, a first payment
     * (down payment). Prices are snapshotted from the branch price at order time.
     *
     * @param  list<array{service_id: int|string, quantity: int|string|float}>  $items
     * @param  array{amount: int|string|float, method: PaymentMethod, reference?: string|null}|null  $initialPayment
     *
     * @throws DomainException
     */
    public function handle(
        Branch $branch,
        User $user,
        int $customerId,
        array $items,
        string|int|float $discount = 0,
        ?string $notes = null,
        ?CarbonInterface $dueAt = null,
        ?array $initialPayment = null,
    ): Order {
        if ($items === []) {
            throw new DomainException('Order harus memiliki minimal satu layanan.');
        }

        return DB::transaction(function () use ($branch, $user, $customerId, $items, $discount, $notes, $dueAt, $initialPayment): Order {
            $customer = Customer::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $branch->tenant_id)
                ->find($customerId);

            if ($customer === null) {
                throw new DomainException('Pelanggan tidak ditemukan.');
            }

            $services = Service::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $branch->tenant_id)
                ->where('is_active', true)
                ->whereIn('id', collect($items)->pluck('service_id')->unique()->all())
                ->with(['branchPrices' => fn ($query) => $query->where('branch_id', $branch->getKey())])
                ->get()
                ->keyBy('id');

            $subtotal = BigDecimal::zero();
            $lines = [];

            foreach ($items as $item) {
                $service = $services->get((int) $item['service_id']);

                if ($service === null) {
                    throw new DomainException('Layanan tidak tersedia atau tidak aktif.');
                }

                $quantity = BigDecimal::of((string) $item['quantity']);

                if ($quantity->isLessThanOrEqualTo(0)) {
                    throw new DomainException('Jumlah/berat harus lebih dari 0.');
                }

                $unitPrice = BigDecimal::of($service->priceForBranch($branch));
                $normalizedQuantity = BigDecimal::of($quantity)->toScale(2, RoundingMode::HalfUp);
                $lineSubtotal = $unitPrice->multipliedBy($normalizedQuantity)->toScale(2, RoundingMode::HalfUp);
                $subtotal = $subtotal->plus($lineSubtotal);

                $lines[] = [
                    'service_id' => $service->getKey(),
                    'service_name' => $service->name,
                    'unit' => $service->unit,
                    'quantity' => (string) $quantity->toScale(2, RoundingMode::HalfUp),
                    'unit_price' => (string) $unitPrice->toScale(2),
                    'subtotal' => (string) $lineSubtotal,
                ];
            }

            $discount = BigDecimal::of((string) $discount)->toScale(2, RoundingMode::HalfUp);

            if ($discount->isLessThan(0) || $discount->isGreaterThan($subtotal)) {
                throw new DomainException('Diskon tidak boleh negatif atau melebihi subtotal.');
            }

            $total = $subtotal->minus($discount);
            $orderDate = now();
            $maxHours = $services->max('estimated_hours');

            $order = Order::query()->create([
                'tenant_id' => $branch->tenant_id,
                'branch_id' => $branch->getKey(),
                'customer_id' => $customer->getKey(),
                'created_by' => $user->getKey(),
                'invoice_no' => $this->generateInvoiceNumber->handle($branch, $orderDate),
                'order_date' => $orderDate,
                'due_at' => $dueAt ?? ($maxHours ? $orderDate->copy()->addHours((int) $maxHours) : null),
                'subtotal' => (string) $subtotal,
                'discount' => (string) $discount,
                'total' => (string) $total,
                'notes' => $notes,
            ]);

            $order->forceFill([
                'status' => OrderStatus::Received,
                'payment_status' => $total->isZero() ? PaymentStatus::Paid : PaymentStatus::Unpaid,
                'paid_amount' => '0.00',
            ])->save();

            $order->items()->createMany($lines);

            OrderStatusLog::query()->create([
                'order_id' => $order->getKey(),
                'user_id' => $user->getKey(),
                'from_status' => null,
                'to_status' => OrderStatus::Received,
            ]);

            if ($initialPayment !== null && BigDecimal::of((string) $initialPayment['amount'])->isGreaterThan(0)) {
                $this->recordPayment->handle(
                    order: $order,
                    amount: $initialPayment['amount'],
                    method: $initialPayment['method'],
                    user: $user,
                    reference: $initialPayment['reference'] ?? null,
                    notes: 'Pembayaran awal (DP)',
                );
            }

            return $order->refresh();
        });
    }
}
