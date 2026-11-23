<?php

namespace App\Actions\Commissions;

use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\ProductionLog;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;

class AwardOrderCommissions
{
    public function handle(Order $order, CarbonInterface $earnedAt): void
    {
        $productionLogs = ProductionLog::query()
            ->where('tenant_id', $order->tenant_id)
            ->where('order_id', $order->getKey())
            ->whereNotNull('worker_id')
            ->whereNotNull('finished_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($productionLogs->isEmpty()) {
            return;
        }

        foreach ($order->items()->lockForUpdate()->get() as $item) {
            $totalCommission = BigDecimal::of($item->commission_snapshot ?? '0');

            if ($totalCommission->isLessThanOrEqualTo(0)) {
                continue;
            }

            $stageShare = $totalCommission->dividedBy($productionLogs->count(), 2, RoundingMode::Down);
            $allocated = BigDecimal::zero();

            foreach ($productionLogs->values() as $index => $productionLog) {
                $amount = $index === $productionLogs->count() - 1
                    ? $totalCommission->minus($allocated)
                    : $stageShare;

                $allocated = $allocated->plus($amount);

                CommissionLog::query()->firstOrCreate(
                    [
                        'order_item_id' => $item->getKey(),
                        'production_log_id' => $productionLog->getKey(),
                    ],
                    [
                        'tenant_id' => $order->tenant_id,
                        'user_id' => $productionLog->worker_id,
                        'order_id' => $order->getKey(),
                        'service_id' => $item->service_id,
                        'amount' => (string) $amount->toScale(2),
                        'earned_at' => $earnedAt,
                    ],
                );
            }
        }
    }
}
