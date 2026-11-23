<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\PointLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireLoyaltyPoints extends Command
{
    protected $signature = 'loyalty:expire-points';

    protected $description = 'Expire loyalty points after their earning program ends';

    public function handle(): int
    {
        PointLog::query()->withoutGlobalScope('tenant')
            ->where('type', PointLog::TYPE_EARN)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereNull('expired_at')
            ->orderBy('id')
            ->chunkById(100, function ($logs): void {
                foreach ($logs as $log) {
                    DB::transaction(function () use ($log): void {
                        $lockedLog = PointLog::query()->withoutGlobalScope('tenant')
                            ->lockForUpdate()->find($log->id);

                        if ($lockedLog === null || $lockedLog->expired_at !== null || $lockedLog->expires_at?->isFuture()) {
                            return;
                        }

                        $customer = Customer::query()->withoutGlobalScope('tenant')
                            ->where('tenant_id', $lockedLog->tenant_id)
                            ->lockForUpdate()->find($lockedLog->customer_id);

                        if ($customer === null) {
                            return;
                        }

                        $points = min((int) $lockedLog->points, (int) $customer->loyalty_points);
                        $customer->forceFill(['loyalty_points' => (int) $customer->loyalty_points - $points])->save();
                        $lockedLog->forceFill(['expired_at' => now()])->save();

                        if ($points > 0) {
                            PointLog::query()->withoutGlobalScope('tenant')->create([
                                'tenant_id' => $lockedLog->tenant_id,
                                'customer_id' => $customer->id,
                                'type' => PointLog::TYPE_EXPIRED,
                                'points' => $points,
                                'description' => 'Poin kedaluwarsa dari program poin.',
                            ]);
                        }
                    });
                }
            });

        $this->info('Poin kedaluwarsa berhasil diproses.');

        return self::SUCCESS;
    }
}
