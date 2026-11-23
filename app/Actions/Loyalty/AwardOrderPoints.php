<?php

namespace App\Actions\Loyalty;

use App\Models\Customer;
use App\Models\CustomerMembership;
use App\Models\LoyaltyPointRule;
use App\Models\Order;
use App\Models\PointLog;
use Illuminate\Support\Facades\DB;

class AwardOrderPoints
{
    public function handle(Order $order): ?PointLog
    {
        return DB::transaction(function () use ($order): ?PointLog {
            $lockedOrder = Order::query()->withoutGlobalScope('tenant')
                ->whereKey($order->getKey())->lockForUpdate()->first();

            if ($lockedOrder === null || $lockedOrder->status->value !== 'completed'
                || $lockedOrder->payment_status->value !== 'paid' || $lockedOrder->customer_id === null) {
                return null;
            }

            $customer = Customer::query()->withoutGlobalScope('tenant')
                ->where('tenant_id', $lockedOrder->tenant_id)
                ->lockForUpdate()->find($lockedOrder->customer_id);

            if ($customer === null) {
                return null;
            }

            $membership = CustomerMembership::query()->withoutGlobalScope('tenant')
                ->where('tenant_id', $lockedOrder->tenant_id)
                ->where('customer_id', $customer->id)
                ->where('status', CustomerMembership::STATUS_ACTIVE)
                ->where('started_at', '<=', now())
                ->where('expired_at', '>', now())
                ->with('memberTier')
                ->first();

            $rules = LoyaltyPointRule::query()->withoutGlobalScope('tenant')
                ->where('tenant_id', $lockedOrder->tenant_id)
                ->where('branch_id', $lockedOrder->branch_id)
                ->where('is_active', true)
                ->where('starts_at', '<=', now())
                ->where('ends_at', '>', now());

            if ($membership !== null) {
                $rule = (clone $rules)->where('target_type', LoyaltyPointRule::TARGET_MEMBER_TIER)
                    ->where('member_tier_id', $membership->member_tier_id)->first()
                    ?? (clone $rules)->where('target_type', LoyaltyPointRule::TARGET_ALL_MEMBERS)->first();
            } else {
                $rule = (clone $rules)->where('target_type', LoyaltyPointRule::TARGET_ALL_CUSTOMERS)->first();
            }

            if ($rule === null) {
                return null;
            }

            $alreadyAwarded = PointLog::query()->withoutGlobalScope('tenant')
                ->where('tenant_id', $lockedOrder->tenant_id)
                ->where('transaction_id', $lockedOrder->id)
                ->where('loyalty_point_rule_id', $rule->id)
                ->exists();

            if ($alreadyAwarded) {
                return null;
            }

            $stepCents = (int) round(((float) $rule->amount_step) * 100);
            $totalCents = (int) round(((float) $lockedOrder->total) * 100);
            $points = $stepCents > 0 ? intdiv($totalCents, $stepCents) * $rule->points_per_step : 0;

            if ($points < 1) {
                return null;
            }

            $customer->forceFill([
                'loyalty_points' => (int) $customer->loyalty_points + $points,
            ])->save();

            $log = PointLog::query()->withoutGlobalScope('tenant')->create([
                'tenant_id' => $lockedOrder->tenant_id,
                'customer_id' => $customer->id,
                'transaction_id' => $lockedOrder->id,
                'loyalty_point_rule_id' => $rule->id,
                'type' => PointLog::TYPE_EARN,
                'points' => $points,
                'description' => 'Poin otomatis: '.$rule->name,
                'expires_at' => $rule->ends_at,
            ]);

            DB::table('loyalty_point_lots')->insert([
                'tenant_id' => $lockedOrder->tenant_id,
                'customer_id' => $customer->id,
                'loyalty_point_rule_id' => $rule->id,
                'transaction_id' => $lockedOrder->id,
                'points_awarded' => $points,
                'points_remaining' => $points,
                'expires_at' => $rule->ends_at,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $log;
        });
    }
}
