<?php

namespace App\Actions\Orders;

use App\Actions\Accounting\PostOrderRevenue;
use App\Actions\Accounting\ReverseOrderRevenue;
use App\Actions\Commissions\AwardOrderCommissions;
use App\Actions\Loyalty\AwardOrderPoints;
use App\Enums\CourierTaskStatus;
use App\Enums\CourierTaskType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\CourierTask;
use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\ProductionLog;
use App\Models\User;
use App\Services\ActivityLogger;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Support\Facades\DB;

class ChangeOrderStatus
{
    public function __construct(
        private OrderActionAuthorization $authorization,
        private ActivityLogger $activityLogger,
    ) {}

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
            allowMappedWorkshopAccess: true,
        );

        if (! $to->canBeSetByRole($actor->role)) {
            throw new DomainException('Peran Anda tidak diizinkan mengubah status ke "'.$to->label().'".');
        }

        return DB::transaction(function () use ($order, $to, $actor, $note, $workerId, $machineCode): Order {
            $locked = Order::query()->withoutGlobalScope('tenant')
                ->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($workerId !== null) {
                $allowedWorkerBranchIds = $locked->workshop_id !== null
                    ? [$locked->workshop_id]
                    : [$locked->branch_id];

                $workerIsAssignedToOrderLocation = User::query()->whereKey($workerId)
                    ->where('tenant_id', $locked->tenant_id)->where('role', 'operator')->where('is_active', true)
                    ->whereHas('branches', fn ($query) => $query->whereIn('branches.id', $allowedWorkerBranchIds)
                        ->where('branches.tenant_id', $locked->tenant_id)->where('branches.is_active', true))
                    ->exists();

                if (! $workerIsAssignedToOrderLocation) {
                    throw new DomainException('Petugas produksi harus aktif dan ditugaskan ke outlet atau workshop yang menangani order ini.');
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

            $productionStage = $this->productionStageFor($to);

            if ($productionStage !== null || $to === OrderStatus::Ready) {
                $pickupTasks = CourierTask::query()
                    ->where('tenant_id', $locked->tenant_id)
                    ->where('order_id', $locked->getKey())
                    ->where('task_type', CourierTaskType::Pickup->value)
                    ->lockForUpdate()
                    ->get(['id', 'status']);

                if ($pickupTasks->contains(
                    fn (CourierTask $task): bool => $task->status !== CourierTaskStatus::Completed
                )) {
                    throw new DomainException('Tugas pickup harus diselesaikan sebelum order masuk ke proses produksi.');
                }
            }

            if ($to === OrderStatus::Completed) {
                $deliveryTasks = CourierTask::query()
                    ->where('tenant_id', $locked->tenant_id)
                    ->where('order_id', $locked->getKey())
                    ->where('task_type', CourierTaskType::Delivery->value)
                    ->lockForUpdate()
                    ->get(['id', 'status']);

                if ($deliveryTasks->contains(
                    fn (CourierTask $task): bool => $task->status !== CourierTaskStatus::Completed
                )) {
                    throw new DomainException('Tugas delivery harus diselesaikan sebelum order ditandai selesai.');
                }
            }

            if ($to === OrderStatus::Completed && $locked->payment_status !== PaymentStatus::Paid) {
                throw new DomainException('Order belum lunas. Selesaikan pembayaran sebelum menyerahkan cucian.');
            }

            if ($to === OrderStatus::Cancelled && BigDecimal::of($locked->paid_amount)->isGreaterThan(0)) {
                throw new DomainException('Order sudah menerima pembayaran. Lakukan refund terlebih dahulu sebelum membatalkan.');
            }

            $from = $locked->status;
            $transitionedAt = now();

            ProductionLog::query()->where('tenant_id', $locked->tenant_id)
                ->where('order_id', $locked->getKey())->whereNull('finished_at')
                ->lockForUpdate()->update(['finished_at' => $transitionedAt, 'updated_at' => $transitionedAt]);

            if ($to === OrderStatus::Ready) {
                app(AwardOrderCommissions::class)->handle($locked, $transitionedAt);
            }

            $productionStage = $this->productionStageFor($to);

            if ($productionStage !== null) {
                ProductionLog::query()->create([
                    'tenant_id' => $locked->tenant_id, 'order_id' => $locked->getKey(),
                    'stage' => $productionStage, 'worker_id' => $workerId,
                    'machine_code' => $machineCode, 'started_at' => $transitionedAt,
                ]);
            }

            $locked->forceFill(['status' => $to])->save();

            if ($to === OrderStatus::Cancelled) {
                app(PostOrderRevenue::class)->handle($locked);
                app(ReverseOrderRevenue::class)->handle(
                    order: $locked, cancelledAt: $transitionedAt, createdBy: (int) $actor->getKey(),
                );
            }

            if ($to === OrderStatus::Completed && $locked->payment_status === PaymentStatus::Paid) {
                app(AwardOrderPoints::class)->handle($locked);
            }

            OrderStatusLog::query()->create([
                'order_id' => $locked->getKey(), 'user_id' => $actor->getKey(),
                'worker_id' => $workerId, 'machine_code' => $machineCode,
                'from_status' => $from, 'to_status' => $to, 'note' => $note,
            ]);

            $this->activityLogger->recordForAuthorizedActor($actor, 'order.status_changed', $locked, [
                'invoice_no' => $locked->invoice_no,
                'branch_id' => $locked->branch_id,
                'workshop_id' => $locked->workshop_id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'worker_id' => $workerId,
                'machine_code' => $machineCode,
                'note' => $note,
            ]);

            $order->refresh();

            return $order;
        });
    }

    private function productionStageFor(OrderStatus $status): ?string
    {
        return match ($status) {
            OrderStatus::Washing => 'washer',
            OrderStatus::Drying => 'dryer',
            OrderStatus::Ironing => 'ironing',
            OrderStatus::Packing => 'packing',
            default => null,
        };
    }
}
