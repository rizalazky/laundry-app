<?php

namespace App\\Actions\\Orders;

use App\\Enums\\OrderStatus;
use App\\Enums\\PaymentStatus;
use App\\Models\\Order;
use App\\Models\\OrderStatusLog;
use App\\Models\\User;
use Brick\\Math\\BigDecimal;
use DomainException;
use Illuminate\\Support\\Facades\\DB;

class ChangeOrderStatus
{
    public function __construct(private OrderActionAuthorization $authorization) {}

    /**
     * @throws DomainException
     */
    public function handle(Order $order, OrderStatus $to, User $user, ?string $note = null): Order
    {
        $this->authorization->authorizeOrder(
            $user,
            $order,
            ['tenant_owner', 'branch_manager', 'cashier', 'operator'],
        );

        if (! $to->canBeSetByRole($user->role)) {
            throw new DomainException('Peran Anda tidak diizinkan mengubah status ke "'.$to->label().'".');
        }

        return DB::transaction(function () use ($order, $to, $user, $note): Order {
            $locked = Order::query()
                ->withoutGlobalScope('tenant')
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->canMoveTo($to)) {
                throw new DomainException('Status tidak bisa diubah dari "'.$locked->status->label().'" ke "'.$to->label().'".');
            }

            if ($to === OrderStatus::Completed && $locked->payment_status !== PaymentStatus::Paid) {
                throw new DomainException('Order belum lunas. Selesaikan pembayaran sebelum menyerahkan cucian.');
            }

            if ($to === OrderStatus::Cancelled && BigDecimal::of($locked->paid_amount)->isGreaterThan(0)) {
                throw new DomainException('Order sudah menerima pembayaran. Lakukan refund terlebih dahulu sebelum membatalkan.');
            }

            $from = $locked->status;

            $locked->forceFill(['status' => $to])->save();

            OrderStatusLog::query()->create([
                'order_id' => $locked->getKey(),
                'user_id' => $user->getKey(),
                'from_status' => $from,
                'to_status' => $to,
                'note' => $note,
            ]);

            $order->refresh();

            return $order;
        });
    }
}
