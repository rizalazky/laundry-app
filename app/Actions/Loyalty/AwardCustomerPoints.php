<?php

namespace App\Actions\Loyalty;

use App\Models\Customer;
use App\Models\PointLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EntitlementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AwardCustomerPoints
{
    public function handle(User $actor, int $customerId, int $points, ?string $description = null): PointLog
    {
        $this->authorize($actor);

        if ($points < 1) {
            throw ValidationException::withMessages(['points' => 'Jumlah poin harus lebih dari nol.']);
        }

        return DB::transaction(function () use ($actor, $customerId, $points, $description): PointLog {
            $customer = Customer::query()
                ->where('tenant_id', $actor->tenant_id)
                ->lockForUpdate()
                ->find($customerId);

            if (! $customer) {
                throw ValidationException::withMessages(['customer_id' => 'Pelanggan tidak ditemukan pada tenant ini.']);
            }

            $customer->loyalty_points = (int) $customer->loyalty_points + $points;
            $customer->save();

            return PointLog::query()->create([
                'tenant_id' => $actor->tenant_id,
                'customer_id' => $customer->id,
                'type' => PointLog::TYPE_EARN,
                'points' => $points,
                'description' => filled($description) ? $description : 'Penambahan poin manual oleh '.$actor->name,
            ]);
        });
    }

    private function authorize(User $actor): void
    {
        if (
            ! $actor->is_active || $actor->tenant_id === null
            || ! in_array($actor->role, ['tenant_owner', 'branch_manager'], true)
            || ! $actor->hasPermissionTo('loyalty.manage')
        ) {
            throw new AuthorizationException('Anda tidak memiliki izin untuk mengelola poin pelanggan.');
        }

        $tenant = Tenant::query()->find($actor->tenant_id);

        if ($tenant === null) {
            throw new AuthorizationException('Tenant loyalty tidak ditemukan.');
        }

        app(EntitlementService::class)->assertCanWrite($tenant);
    }
}
