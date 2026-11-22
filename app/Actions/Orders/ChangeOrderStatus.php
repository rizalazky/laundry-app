<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\ProductionLog;
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
            allowMappedWorkshopAccess: true,
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
                $orderBranch = Branch::query()
                    ->where('tenant_id', $locked->tenant_id)
                    ->find($locked->branch_id);

                $allowedWorkerBranchIds = [$locked->branch_id];

                if ($orderBranch !== null && ! $orderBranch->is_workshop) {
                    $allowedWorkerBranchIds = [
                        ...$allowedWorkerBranchIds,
                        ...$orderBranch->workshops()
                            ->where('branches.is_active', true)
                            ->pluck('branches.id')
                            ->all(),
                    ];
                }

                $workerIsAssignedToOrderLocation = User::query()
                    ->whereKey($workerId)
                    ->where('tenant_id', $locked->tenant_id)
                    ->where('role', 'operator')
                    ->where('is_active', true)
                    ->whereHas('branches', fn ($query) => $query
                        ->whereIn('branches.id', $allowedWorkerBranchIds)
                        ->where('branches.tenant_id', $locked->tenant_id)
                        ->where('branches.is_active', true))
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

            if ($to === OrderStatus::Completed && $locked->payment_status !== PaymentStatus::Paid) {
                throw new DomainException('Order belum lunas. Selesaikan pembayaran sebelum menyerahkan cucian.');
            }

            if ($to === OrderStatus::Cancelled && BigDecimal::of($locked->paid_amount)->isGreaterThan(0)) {
                throw new DomainException('Order sudah menerima pembayaran. Lakukan refund terlebih dahulu sebelum membatalkan.');
            }

            $from = $locked->status;
            $transitionedAt = now();

            ProductionLog::query()
                ->where('tenant_id', $locked->tenant_id)
                ->where('order_id', $locked->getKey())
                ->whereNull('finished_at')
                ->lockForUpdate()
                ->update([
                    'finished_at' => $transitionedAt,
                    'updated_at' => $transitionedAt,
                ]);

            $productionStage = $this->productionStageFor($to);

            if ($productionStage !== null) {
                ProductionLog::query()->create([
                    'tenant_id' => $locked->tenant_id,
                    'order_id' => $locked->getKey(),
                    'stage' => $productionStage,
                    'worker_id' => $workerId,
                    'machine_code' => $machineCode,
                    'started_at' => $transitionedAt,
                ]);
            }

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
