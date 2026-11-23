<?php

namespace App\Actions\Loyalty;

use App\Models\Customer;
use App\Models\PointLog;
use App\Models\PointReward;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RedeemCustomerReward
{
    public function handle(User $actor, int $customerId, int $rewardId): PointLog
    {
        if (
            ! $actor->is_active || $actor->tenant_id === null
            || ! in_array($actor->role, ['tenant_owner', 'branch_manager'], true)
            || ! $actor->hasPermissionTo('loyalty.manage')
        ) {
            throw new AuthorizationException('Anda tidak memiliki izin untuk menukarkan poin pelanggan.');
        }

        return DB::transaction(function () use ($actor, $customerId, $rewardId): PointLog {
            $customer = Customer::query()->where('tenant_id', $actor->tenant_id)
                ->lockForUpdate()->find($customerId);

            if (! $customer) {
                throw ValidationException::withMessages(['customer_id' => 'Pelanggan tidak ditemukan pada tenant ini.']);
            }

            $reward = PointReward::query()->where('tenant_id', $actor->tenant_id)
                ->where('is_active', true)->lockForUpdate()->find($rewardId);

            if (! $reward) {
                throw ValidationException::withMessages(['point_reward_id' => 'Hadiah tidak tersedia atau tidak aktif.']);
            }

            $requiredPoints = (int) $reward->points_required;

            if ((int) $customer->loyalty_points < $requiredPoints) {
                throw ValidationException::withMessages(['customer_id' => 'Poin pelanggan tidak mencukupi untuk menukarkan hadiah ini.']);
            }

            if ($reward->stock !== null && (float) $reward->stock < 1) {
                throw ValidationException::withMessages(['point_reward_id' => 'Stok hadiah sudah habis.']);
            }

            // Consume expiring earned points first. Manual legacy points remain available after these lots.
            $remainingToConsume = $requiredPoints;
            $lots = DB::table('loyalty_point_lots')
                ->where('tenant_id', $actor->tenant_id)
                ->where('customer_id', $customer->id)
                ->where('points_remaining', '>', 0)
                ->where('expires_at', '>', now())
                ->orderBy('expires_at')->orderBy('id')
                ->lockForUpdate()->get();

            foreach ($lots as $lot) {
                if ($remainingToConsume < 1) {
                    break;
                }

                $consumed = min($remainingToConsume, (int) $lot->points_remaining);
                DB::table('loyalty_point_lots')->where('id', $lot->id)->update([
                    'points_remaining' => (int) $lot->points_remaining - $consumed,
                    'updated_at' => now(),
                ]);
                $remainingToConsume -= $consumed;
            }

            $customer->forceFill([
                'loyalty_points' => (int) $customer->loyalty_points - $requiredPoints,
            ])->save();

            if ($reward->stock !== null) {
                $reward->stock = (float) $reward->stock - 1;
                $reward->save();
            }

            return PointLog::query()->create([
                'tenant_id' => $actor->tenant_id,
                'customer_id' => $customer->id,
                'type' => PointLog::TYPE_REDEEM,
                'points' => $requiredPoints,
                'description' => 'Penukaran hadiah: '.$reward->reward_name,
            ]);
        });
    }
}
