<?php

namespace App\Actions\Orders;

use App\Actions\Accounting\PostOrderRevenue;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ServiceUnit;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\Service;
use App\Models\User;
use App\Services\ActivityLogger;
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
        private OrderActionAuthorization $authorization,
        private ResolveOrderPromotion $resolveOrderPromotion,
        private ActivityLogger $activityLogger,
    ) {}

    /**
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
        ?string $voucherCode = null,
        OrderSource $source = OrderSource::Pos,
        ?int $workshopId = null,
    ): Order {
        if ($items === []) {
            throw new DomainException('Order harus memiliki minimal satu layanan.');
        }

        $branch = $this->authorization->authorizeBranch(
            $user,
            $branch,
            ['tenant_owner', 'branch_manager', 'cashier'],
        );

        return DB::transaction(function () use ($branch, $user, $customerId, $items, $discount, $notes, $dueAt, $initialPayment, $voucherCode, $source, $workshopId): Order {
            $mappedWorkshops = $branch->is_workshop
                ? collect()
                : $branch->workshops()
                    ->where('branches.is_active', true)
                    ->get(['branches.id', 'branches.name']);

            if ($workshopId !== null && ! $mappedWorkshops->contains('id', $workshopId)) {
                throw new DomainException('Workshop yang dipilih tidak aktif atau tidak terhubung ke outlet ini.');
            }

            if ($workshopId === null && $mappedWorkshops->count() === 1) {
                $workshopId = (int) $mappedWorkshops->sole()->getKey();
            }

            if ($workshopId === null && $mappedWorkshops->count() > 1) {
                throw new DomainException('Pilih workshop tujuan untuk order ini.');
            }

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

                if (
                    $service->unit === ServiceUnit::Piece
                    && ! $quantity->isEqualTo($quantity->toScale(0, RoundingMode::Down))
                ) {
                    throw new DomainException('Layanan dengan satuan PCS harus menggunakan jumlah bulat.');
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

            $manualDiscount = BigDecimal::of((string) $discount)->toScale(2, RoundingMode::HalfUp);

            if ($manualDiscount->isLessThan(0) || $manualDiscount->isGreaterThan($subtotal)) {
                throw new DomainException('Diskon tidak boleh negatif atau melebihi subtotal.');
            }

            $promotion = null;

            if (filled($voucherCode)) {
                $promotion = $this->resolveOrderPromotion->handle(
                    tenantId: (int) $branch->tenant_id,
                    voucherCode: $voucherCode,
                    subtotal: $subtotal,
                );
            }

            $promoDiscount = $promotion['discount'] ?? BigDecimal::zero();
            $totalDiscount = $manualDiscount->plus($promoDiscount)->toScale(2, RoundingMode::HalfUp);

            if ($totalDiscount->isGreaterThan($subtotal)) {
                throw new DomainException('Total diskon tidak boleh melebihi subtotal.');
            }

            $total = $subtotal->minus($totalDiscount);

            foreach ($lines as &$line) {
                $service = $services->get((int) $line['service_id']);
                $quantity = BigDecimal::of($line['quantity']);
                $lineSubtotal = BigDecimal::of($line['subtotal']);
                $discountedLineSubtotal = $subtotal->isZero()
                    ? BigDecimal::zero()
                    : $lineSubtotal->multipliedBy($total)->dividedBy($subtotal, 8, RoundingMode::HalfUp);
                $discountedUnitPrice = $quantity->isZero()
                    ? BigDecimal::zero()
                    : $discountedLineSubtotal->dividedBy($quantity, 8, RoundingMode::HalfUp);
                $commissionValue = BigDecimal::of($service->commission_value ?? '0');

                $commission = match ($service->commission_method ?? 'fixed') {
                    'percentage' => $discountedUnitPrice
                        ->multipliedBy($commissionValue)
                        ->dividedBy(BigDecimal::of(100), 8, RoundingMode::HalfUp)
                        ->multipliedBy($quantity),
                    default => $commissionValue->multipliedBy($quantity),
                };

                $line['commission_snapshot'] = (string) $commission->toScale(2, RoundingMode::HalfUp);
            }
            unset($line);

            $orderDate = now();
            $maxHours = $services->max('estimated_hours') ?: $branch->tenant->getDefaultEstimationHours();

            $order = Order::query()->create([
                'tenant_id' => $branch->tenant_id,
                'branch_id' => $branch->getKey(),
                'workshop_id' => $workshopId,
                'customer_id' => $customer->getKey(),
                'created_by' => $user->getKey(),
                'invoice_no' => $this->generateInvoiceNumber->handle($branch, $orderDate),
                'order_source' => $source->value,
                'order_date' => $orderDate,
                'due_at' => $dueAt ?? ($maxHours ? $orderDate->copy()->addHours((int) $maxHours) : null),
                'subtotal' => (string) $subtotal,
                'discount' => (string) $totalDiscount,
                'total' => (string) $total,
                'promo_id' => ($promotion['promo'] ?? null)?->getKey(),
                'voucher_id' => ($promotion['voucher'] ?? null)?->getKey(),
                'promo_name_snapshot' => ($promotion['promo'] ?? null)?->name,
                'voucher_code_snapshot' => ($promotion['voucher'] ?? null)?->code,
                'promo_discount' => (string) $promoDiscount,
                'notes' => $notes,
            ]);

            $order->forceFill([
                'status' => OrderStatus::Received,
                'payment_status' => $total->isZero() ? PaymentStatus::Paid : PaymentStatus::Unpaid,
                'paid_amount' => '0.00',
            ])->save();

            $order->items()->createMany($lines);

            if ($promotion !== null) {
                $promotion['voucher']->increment('used_count');
            }

            app(PostOrderRevenue::class)->handle($order);

            OrderStatusLog::query()->create([
                'order_id' => $order->getKey(),
                'user_id' => $user->getKey(),
                'from_status' => null,
                'to_status' => OrderStatus::Received,
            ]);

            $this->activityLogger->recordForAuthorizedActor($user, 'order.created', $order, [
                'invoice_no' => $order->invoice_no,
                'branch_id' => $order->branch_id,
                'workshop_id' => $order->workshop_id,
                'customer_id' => $order->customer_id,
                'order_source' => $order->order_source->value,
                'total' => $order->total,
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
