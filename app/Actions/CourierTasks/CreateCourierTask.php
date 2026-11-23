<?php

namespace App\Actions\CourierTasks;

use App\Actions\Orders\OrderActionAuthorization;
use App\Enums\CourierTaskStatus;
use App\Enums\CourierTaskType;
use App\Enums\OrderStatus;
use App\Models\CourierTask;
use App\Models\Order;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class CreateCourierTask
{
    public function __construct(private OrderActionAuthorization $authorization) {}

    public function handle(
        Order $order,
        CourierTaskType $type,
        string $address,
        int $courierId,
        User $user,
    ): CourierTask {
        $actor = User::query()->find($user->getKey());

        if ($actor === null) {
            throw new DomainException('Akun pengguna tidak ditemukan.');
        }

        if (! $actor->hasPermissionTo('courier_tasks.manage')) {
            throw new DomainException('Anda tidak memiliki izin untuk menugaskan kurir.');
        }

        $this->authorization->authorizeOrder(
            $actor,
            $order,
            ['tenant_owner', 'branch_manager', 'cashier'],
        );

        $address = trim($address);

        if ($address === '' || mb_strlen($address) > 2000) {
            throw new DomainException('Alamat tugas wajib diisi dan maksimal 2.000 karakter.');
        }

        return DB::transaction(function () use ($order, $type, $address, $courierId, $actor): CourierTask {
            $lockedOrder = Order::query()->withoutGlobalScope('tenant')
                ->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedOrder->status === OrderStatus::Cancelled || $lockedOrder->status === OrderStatus::Completed) {
                throw new DomainException('Tugas kurir tidak dapat dibuat untuk order yang sudah final.');
            }

            if ($type === CourierTaskType::Delivery && $lockedOrder->status !== OrderStatus::Ready) {
                throw new DomainException('Tugas delivery hanya dapat dibuat setelah order siap diambil.');
            }

            if ($type === CourierTaskType::Pickup && $lockedOrder->status !== OrderStatus::Received) {
                throw new DomainException('Tugas pickup hanya dapat dibuat saat order baru diterima.');
            }

            $courierIsAssignedToBranch = User::query()
                ->whereKey($courierId)
                ->where('tenant_id', $lockedOrder->tenant_id)
                ->whereIn('role', ['cashier', 'operator'])
                ->where('is_active', true)
                ->whereHas('branches', fn ($query) => $query
                    ->whereKey($lockedOrder->branch_id)
                    ->where('branches.tenant_id', $lockedOrder->tenant_id)
                    ->where('branches.is_active', true))
                ->exists();

            if (! $courierIsAssignedToBranch) {
                throw new DomainException('Kurir harus merupakan staff aktif yang ditugaskan ke outlet order ini.');
            }

            $activeTaskExists = CourierTask::query()
                ->where('tenant_id', $lockedOrder->tenant_id)
                ->where('order_id', $lockedOrder->getKey())
                ->where('task_type', $type->value)
                ->whereNotIn('status', [CourierTaskStatus::Completed->value, CourierTaskStatus::Cancelled->value])
                ->exists();

            if ($activeTaskExists) {
                throw new DomainException('Masih ada tugas '.$type->label().' aktif untuk order ini.');
            }

            return CourierTask::query()->create([
                'tenant_id' => $lockedOrder->tenant_id,
                'order_id' => $lockedOrder->getKey(),
                'courier_id' => $courierId,
                'created_by' => $actor->getKey(),
                'task_type' => $type,
                'address' => $address,
                'status' => CourierTaskStatus::Assigned,
            ]);
        });
    }
}
