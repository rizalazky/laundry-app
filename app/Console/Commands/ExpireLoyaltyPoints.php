<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\PointLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireLoyaltyPoints extends Command
{
    protected $signature = 'loyalty:expire-points';

    protected $description = 'Expire remaining loyalty points after their earning program ends';

    public function handle(): int
    {
        DB::table('loyalty_point_lots')->where('points_remaining', '>', 0)
            ->where('expires_at', '<=', now())->whereNull('expired_at')
            ->orderBy('id')->chunkById(100, function ($lots): void {
                foreach ($lots as $lot) {
                    DB::transaction(function () use ($lot): void {
                        $lockedLot = DB::table('loyalty_point_lots')->where('id', $lot->id)
                            ->lockForUpdate()->first();

                        if ($lockedLot === null || $lockedLot->expired_at !== null
                            || (int) $lockedLot->points_remaining < 1) {
                            return;
                        }

                        $customer = Customer::query()->withoutGlobalScope('tenant')
                            ->where('tenant_id', $lockedLot->tenant_id)
                            ->lockForUpdate()->find($lockedLot->customer_id);

                        if ($customer === null) {
                            return;
                        }

                        $points = (int) $lockedLot->points_remaining;
                        $customer->forceFill([
                            'loyalty_points' => max(0, (int) $customer->loyalty_points - $points),
                        ])->save();

                        DB::table('loyalty_point_lots')->where('id', $lockedLot->id)->update([
                            'points_remaining' => 0,
                            'expired_at' => now(),
                            'updated_at' => now(),
                        ]);

                        PointLog::query()->withoutGlobalScope('tenant')->create([
                            'tenant_id' => $lockedLot->tenant_id,
                            'customer_id' => $customer->id,
                            'loyalty_point_rule_id' => $lockedLot->loyalty_point_rule_id,
                            'type' => PointLog::TYPE_EXPIRED,
                            'points' => $points,
                            'description' => 'Poin kedaluwarsa dari program poin.',
                        ]);
                    });
                }
            });

        $this->info('Poin kedaluwarsa berhasil diproses.');

        return self::SUCCESS;
    }
}
