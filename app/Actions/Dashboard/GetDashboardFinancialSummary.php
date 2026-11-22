<?php

namespace App\Actions\Dashboard;

use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;

class GetDashboardFinancialSummary
{
    /**
     * @return array{monthly_turnover: string, monthly_net_payments: string, outstanding_balance: string}
     */
    public function handle(User $user): array
    {
        $empty = [
            'monthly_turnover' => '0.00',
            'monthly_net_payments' => '0.00',
            'outstanding_balance' => '0.00',
        ];

        if (! $user->is_active || $user->tenant_id === null) {
            return $empty;
        }

        $orders = Order::query()->where('tenant_id', $user->tenant_id);
        $payments = Payment::query()->where('tenant_id', $user->tenant_id);

        if ($user->role !== 'tenant_owner') {
            $branchIds = $user->branches()
                ->where('branches.tenant_id', $user->tenant_id)
                ->where('branches.is_active', true)
                ->pluck('branches.id');

            if ($branchIds->isEmpty()) {
                return $empty;
            }

            $orders->whereIn('branch_id', $branchIds);
            $payments->whereIn('branch_id', $branchIds);
        }

        $now = now();
        $monthlyTurnover = (clone $orders)
            ->whereBetween('order_date', [$now->copy()->startOfMonth(), $now])
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->sum('total');

        $monthlyNetPayments = (clone $payments)
            ->whereBetween('paid_at', [$now->copy()->startOfMonth(), $now])
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN type = ? THEN amount WHEN type = ? THEN -amount ELSE 0 END), 0) as net_total',
                [PaymentType::Payment->value, PaymentType::Refund->value],
            )
            ->value('net_total');

        $outstandingBalance = (clone $orders)
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->selectRaw('COALESCE(SUM(total - paid_amount), 0) as outstanding_total')
            ->value('outstanding_total');

        return [
            'monthly_turnover' => number_format((float) $monthlyTurnover, 2, '.', ''),
            'monthly_net_payments' => number_format((float) $monthlyNetPayments, 2, '.', ''),
            'outstanding_balance' => number_format((float) $outstandingBalance, 2, '.', ''),
        ];
    }
}
