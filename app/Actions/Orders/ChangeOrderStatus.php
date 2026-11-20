<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\User;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Support\Facades\DB;

class ChangeOrderStatus
{
    public function __construct(private OrderActionAuthorization $authorization) {}

    /**
     * @throws DomainException
     */
    public function handle(Order $order, OrderStatus $to, User $user, ?string $note = null, ?int $workerId = null, ?string $machineCode = null): Order
    {
        $actor = User::query()->find($user->getKey());

        if ($actor === null) {
            throw new DomainException('Akun pengguna tidak ditemukan.');
        }

        $this->authorization->authorizeOrder(
            $actor,
            $order,
            ['tenant_owner', 'branch_manager', 'cashier', 'operator'],
        );

        if (! $to->canBeSetByRole($actor->role)) {
            throw new DomainException('Peran Anda tidak diizinkan mengubah status ke "'.$to->label().'".');
        }

        return DB::transaction(function () use ($order, $to, $actor, $note, $workerId, $machineCode): Order {
            $locked = Order::query()
                ->withoutGlobalScope('tenant')
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($workerId !== null) {
                $workerIsAssignedToBranch = User::query()
                    ->whereKey($workerId)
                    ->where('tenant_id', $locked->tenant_id)
                    ->where('role', 'operator')
                    ->where('is_active', true)
                    ->whereHas('branches', fn ($query) => $query->whereKey($locked->branch_id))
                    ->exists();

                if (! $workerIsAssignedToBranch) {
                    throw new DomainException('Petugas produksi tidak valid atau tidak ditugaskan ke cabang order ini.');
                }
            }

            $machineCode = $machineCode !== null ? trim($machineCode) : null;
            $machineCode = $machineCode === '' ? null : $machineCode;

            if ($machineCode !== null && mb_strlen($machineCode) > 50) {
                throw new DomainException('Kode mesin maksimal 50 karakter.');
            }

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
                'user_id' => $actor->getKey(),
                'worker_id' => $workerId,
                'machine_code' => $machineCode,
                'from_status' => $from,
                'to_status' => $to,
                'note' => $note,
            ]);

            $order->refresh();

            return $order;
        });
    }
}
