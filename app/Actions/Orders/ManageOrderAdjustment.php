<?php

namespace App\Actions\Orders;

use App\Actions\Accounting\RecordExpense;
use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Models\Order;
use App\Models\OrderAdjustment;
use App\Models\User;
use App\Services\ActivityLogger;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\Exception\RoundingNecessaryException;
use DomainException;
use Illuminate\Support\Facades\DB;

class ManageOrderAdjustment
{
    public function __construct(
        private OrderActionAuthorization $authorization,
    ) {}

    public function request(
        Order $order,
        string $type,
        string|int|float $amount,
        string $reason,
        User $user,
        ?string $notes = null,
    ): OrderAdjustment {
        if (! in_array($type, [OrderAdjustment::TYPE_REFUND, OrderAdjustment::TYPE_COMPENSATION], true)) {
            throw new DomainException('Jenis pengajuan tidak valid.');
        }

        $this->authorization->authorizeOrder(
            $user,
            $order,
            ['tenant_owner', 'branch_manager', 'cashier'],
        );
        $amount = $this->money($amount);
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 2000) {
            throw new DomainException('Alasan wajib diisi dan maksimal 2.000 karakter.');
        }

        return DB::transaction(function () use ($order, $type, $amount, $reason, $user, $notes): OrderAdjustment {
            $locked = Order::query()->withoutGlobalScope('tenant')->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status->value === 'cancelled') {
                throw new DomainException('Order yang dibatalkan tidak dapat diajukan refund atau kompensasi.');
            }

            if ($type === OrderAdjustment::TYPE_REFUND) {
                $this->assertRefundCapacity($locked, $amount);
            }

            $adjustment = OrderAdjustment::query()->create([
                'tenant_id' => $locked->tenant_id,
                'branch_id' => $locked->branch_id,
                'order_id' => $locked->getKey(),
                'type' => $type,
                'status' => OrderAdjustment::STATUS_PENDING,
                'requested_amount' => (string) $amount,
                'reason' => $reason,
                'notes' => $notes,
                'requested_by' => $user->getKey(),
                'requested_at' => now(),
            ]);

            app(ActivityLogger::class)->recordForAuthorizedActor($user, 'order_adjustment.requested', $adjustment, [
                'order_id' => $locked->getKey(),
                'type' => $type,
                'requested_amount' => $adjustment->requested_amount,
            ]);

            return $adjustment;
        });
    }

    public function approve(OrderAdjustment $adjustment, string|int|float $amount, User $user): OrderAdjustment
    {
        $this->assertOwner($user, $adjustment->order);
        $amount = $this->money($amount);

        return DB::transaction(function () use ($adjustment, $amount, $user): OrderAdjustment {
            $locked = OrderAdjustment::query()->withoutGlobalScope('tenant')->whereKey($adjustment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== OrderAdjustment::STATUS_PENDING) {
                throw new DomainException('Hanya pengajuan pending yang dapat disetujui.');
            }

            $order = Order::query()->withoutGlobalScope('tenant')->whereKey($locked->order_id)->lockForUpdate()->firstOrFail();

            if ($locked->type === OrderAdjustment::TYPE_REFUND) {
                $this->assertRefundCapacity($order, $amount, $locked->getKey());
            }

            $locked->forceFill([
                'status' => OrderAdjustment::STATUS_APPROVED,
                'approved_amount' => (string) $amount,
                'approved_by' => $user->getKey(),
                'approved_at' => now(),
            ])->save();

            app(ActivityLogger::class)->recordForAuthorizedActor($user, 'order_adjustment.approved', $locked, [
                'approved_amount' => $locked->approved_amount,
            ]);

            return $locked->refresh();
        });
    }

    public function reject(OrderAdjustment $adjustment, string $reason, User $user): OrderAdjustment
    {
        $this->assertOwner($user, $adjustment->order);
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 2000) {
            throw new DomainException('Alasan penolakan wajib diisi dan maksimal 2.000 karakter.');
        }

        $locked = DB::transaction(function () use ($adjustment, $reason, $user): OrderAdjustment {
            $locked = OrderAdjustment::query()->withoutGlobalScope('tenant')->whereKey($adjustment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== OrderAdjustment::STATUS_PENDING) {
                throw new DomainException('Hanya pengajuan pending yang dapat ditolak.');
            }

            $locked->forceFill([
                'status' => OrderAdjustment::STATUS_REJECTED,
                'approved_by' => $user->getKey(),
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            app(ActivityLogger::class)->recordForAuthorizedActor($user, 'order_adjustment.rejected', $locked, [
                'rejection_reason' => $reason,
            ]);

            return $locked;
        });

        return $locked;
    }

    public function markPaid(
        OrderAdjustment $adjustment,
        User $user,
        ?PaymentMethod $method = null,
        ?string $reference = null,
        ?int $expenseAccountId = null,
        string $paymentSourceType = 'cash',
        ?int $bankAccountId = null,
    ): OrderAdjustment {
        $this->assertOwner($user, $adjustment->order);

        return DB::transaction(function () use (
            $adjustment, $user, $method, $reference, $expenseAccountId, $paymentSourceType, $bankAccountId,
        ): OrderAdjustment {
            $locked = OrderAdjustment::query()->withoutGlobalScope('tenant')->whereKey($adjustment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== OrderAdjustment::STATUS_APPROVED || $locked->approved_amount === null) {
                throw new DomainException('Hanya pengajuan yang sudah disetujui yang dapat dibayar.');
            }

            $order = Order::query()->withoutGlobalScope('tenant')->whereKey($locked->order_id)->lockForUpdate()->firstOrFail();

            if ($locked->type === OrderAdjustment::TYPE_REFUND) {
                $payment = app(RecordPayment::class)->handle(
                    order: $order,
                    amount: $locked->approved_amount,
                    method: $method ?? PaymentMethod::Cash,
                    user: $user,
                    type: PaymentType::Refund,
                    reference: $reference,
                    notes: 'Refund order '.$order->invoice_no.' — '.$locked->reason,
                    idempotencyKey: 'order-adjustment-'.$locked->getKey(),
                );
                $locked->payment_id = $payment->getKey();
            } else {
                if ($expenseAccountId === null) {
                    throw new DomainException('Pilih akun beban untuk pencatatan kompensasi.');
                }

                $expense = app(RecordExpense::class)->handle(
                    tenantId: (int) $locked->tenant_id,
                    branchId: (int) $locked->branch_id,
                    expenseAccountId: $expenseAccountId,
                    paymentSourceType: $paymentSourceType,
                    bankAccountId: $bankAccountId,
                    amount: $locked->approved_amount,
                    description: 'Kompensasi order '.$order->invoice_no.' — '.$locked->reason,
                    spentAt: now(),
                    createdBy: (int) $user->getKey(),
                );
                $locked->expense_id = $expense->getKey();
            }

            $locked->forceFill([
                'status' => OrderAdjustment::STATUS_PAID,
                'paid_by' => $user->getKey(),
                'paid_at' => now(),
            ])->save();

            app(ActivityLogger::class)->recordForAuthorizedActor($user, 'order_adjustment.paid', $locked, [
                'approved_amount' => $locked->approved_amount,
                'payment_id' => $locked->payment_id,
                'expense_id' => $locked->expense_id,
            ]);

            return $locked->refresh();
        });
    }

    private function assertOwner(User $user, ?Order $order): void
    {
        if ($user->role !== 'tenant_owner') {
            throw new DomainException('Hanya owner yang dapat menyetujui, menolak, atau membayar pengajuan.');
        }

        $this->authorization->authorizeOrder($user, $order ?? throw new DomainException('Order tidak ditemukan.'), ['tenant_owner'], requireActiveBusiness: false);
    }

    private function assertRefundCapacity(Order $order, BigDecimal $amount, ?int $exceptAdjustmentId = null): void
    {
        $reserved = $order->adjustments()
            ->where('type', OrderAdjustment::TYPE_REFUND)
            ->whereIn('status', [OrderAdjustment::STATUS_PENDING, OrderAdjustment::STATUS_APPROVED])
            ->when($exceptAdjustmentId !== null, fn ($query) => $query->where('id', '!=', $exceptAdjustmentId))
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'approved' THEN approved_amount ELSE requested_amount END), 0) as reserved")
            ->value('reserved');

        $reserved = number_format((float) $reserved, 2, '.', '');
        $capacity = BigDecimal::of($order->paid_amount)->minus($reserved);

        if ($amount->isGreaterThan($capacity)) {
            throw new DomainException('Nominal refund melebihi sisa dana yang dapat dikembalikan (Rp '.number_format((float) $capacity, 2, ',', '.').').');
        }
    }

    private function money(string|int|float $amount): BigDecimal
    {
        try {
            $value = BigDecimal::of((string) $amount)->toScale(2);
        } catch (NumberFormatException|RoundingNecessaryException) {
            throw new DomainException('Nominal tidak valid. Gunakan angka dengan maksimal 2 desimal.');
        }

        if ($value->isLessThanOrEqualTo(0)) {
            throw new DomainException('Nominal harus lebih dari 0.');
        }

        return $value;
    }
}
