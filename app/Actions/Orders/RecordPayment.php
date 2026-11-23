<?php

namespace App\Actions\Orders;

use App\Actions\Accounting\PostOrderRevenue;
use App\Actions\Accounting\PostPaymentToAccounting;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ActivityLogger;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\Exception\RoundingNecessaryException;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

class RecordPayment
{
    public function __construct(
        private OrderActionAuthorization $authorization,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * Append a payment or refund to the order ledger and refresh the order's
     * cached paid amount. Any number of partial payments can be recorded.
     *
     * @throws DomainException
     */
    public function handle(
        Order $order,
        string|int|float $amount,
        PaymentMethod $method,
        User $user,
        PaymentType $type = PaymentType::Payment,
        ?CarbonInterface $paidAt = null,
        ?string $reference = null,
        ?string $notes = null,
        ?string $idempotencyKey = null,
    ): Payment {
        $roles = $type === PaymentType::Refund
            ? ['tenant_owner', 'branch_manager']
            : ['tenant_owner', 'branch_manager', 'cashier'];

        $this->authorization->authorizeOrder($user, $order, $roles, requireActiveBusiness: $type !== PaymentType::Refund);

        try {
            $amount = BigDecimal::of((string) $amount)->toScale(2);
        } catch (RoundingNecessaryException|NumberFormatException) {
            throw new DomainException('Nominal tidak valid. Gunakan angka dengan maksimal 2 desimal.');
        }

        if ($amount->isLessThanOrEqualTo(0)) {
            throw new DomainException('Nominal harus lebih dari 0.');
        }

        return DB::transaction(function () use ($order, $amount, $method, $user, $type, $paidAt, $reference, $notes, $idempotencyKey): Payment {
            $locked = Order::query()
                ->withoutGlobalScope('tenant')
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($idempotencyKey !== null) {
                $existing = Payment::query()
                    ->withoutGlobalScope('tenant')
                    ->where('order_id', $locked->getKey())
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    app(PostOrderRevenue::class)->handle($locked);
                    app(PostPaymentToAccounting::class)->handle($existing);

                    return $existing;
                }
            }

            if ($locked->status === OrderStatus::Cancelled) {
                throw new DomainException('Order yang dibatalkan tidak bisa menerima transaksi pembayaran.');
            }

            if ($type === PaymentType::Payment) {
                if ($amount->isGreaterThan($locked->balanceDue())) {
                    throw new DomainException('Nominal melebihi sisa tagihan ('.$locked->balanceDue().').');
                }

                $tenant = Tenant::query()->find($locked->tenant_id);

                if ($tenant !== null && ! $tenant->allowsPartialPayment() && $amount->isLessThan($locked->balanceDue())) {
                    throw new DomainException('Pembayaran sebagian dinonaktifkan untuk perusahaan ini. Silakan bayar seluruh sisa tagihan.');
                }
            }

            if ($type === PaymentType::Refund && $amount->isGreaterThan($locked->paid_amount)) {
                throw new DomainException('Nominal refund melebihi total yang sudah dibayar ('.$locked->paid_amount.').');
            }

            $payment = Payment::query()->create([
                'tenant_id' => $locked->tenant_id,
                'branch_id' => $locked->branch_id,
                'order_id' => $locked->getKey(),
                'received_by' => $user->getKey(),
                'type' => $type,
                'method' => $method,
                'amount' => (string) $amount,
                'paid_at' => $paidAt ?? now(),
                'reference' => $reference,
                'notes' => $notes,
                'idempotency_key' => $idempotencyKey,
            ]);

            app(PostOrderRevenue::class)->handle($locked);
            app(PostPaymentToAccounting::class)->handle($payment);

            $locked->recalculatePayment();

            $this->activityLogger->recordForAuthorizedActor($user, $type === PaymentType::Refund ? 'payment.refunded' : 'payment.recorded', $payment, [
                'order_id' => $locked->getKey(),
                'invoice_no' => $locked->invoice_no,
                'branch_id' => $locked->branch_id,
                'type' => $type->value,
                'method' => $method->value,
                'amount' => $payment->amount,
                'paid_at' => $payment->paid_at?->toISOString(),
                'reference' => $payment->reference,
            ]);

            $order->refresh();

            return $payment;
        });
    }
}
