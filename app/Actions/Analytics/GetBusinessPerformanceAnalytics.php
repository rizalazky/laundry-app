<?php

namespace App\Actions\Analytics;

use App\Models\Branch;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class GetBusinessPerformanceAnalytics
{
    /**
     * Build operational performance metrics from order, service, production, attendance and commission records.
     *
     * Turnover is based on non-cancelled order totals, not payment receipts.
     * Service value uses stored line subtotals before order-level discounts.
     * Employee production metrics use stage assignments; attendance and commission totals use their own recorded timestamps.
     *
     * @return array{
     *   from: string,
     *   to: string,
     *   summary: array{orders: int, completed_orders: int, turnover: string, average_order_value: string, expenses_total: string, promo_discount_total: string},
     *   daily_trend: array<int, array{date: string, revenue: string, expenses: string}>,
     *   promo_impact: array<int, array{promo_name: string, orders: int, discount_total: string}>,
     *   outlets: array<int, array{branch_id: int, branch_name: string, orders: int, completed_orders: int, turnover: string, average_order_value: string}>,
     *   services: array<int, array{service_id: int, service_name: string, orders: int, quantity: string, gross_value: string}>,
     *   employees: array<int, array{user_id: int, employee_name: string, assigned_orders: int, production_stages: int, completed_stages: int, attendance_records: int, commission_earned: string}>
     * }
     *
     * @throws DomainException
     */
    public function handle(
        int $tenantId,
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $branchId = null,
    ): array {
        $this->assertAccess($tenantId, $branchId);
        $this->assertDateRange($from, $to);

        $orders = DB::table('orders')
            ->where('orders.tenant_id', $tenantId)
            ->whereBetween('orders.order_date', [$from, $to])
            ->where('orders.status', '!=', 'cancelled');

        if ($branchId !== null) {
            $orders->where('orders.branch_id', $branchId);
        }

        $summary = (clone $orders)
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw('SUM(orders.total) as turnover')
            ->selectRaw("SUM(CASE WHEN orders.status = 'completed' THEN 1 ELSE 0 END) as completed_orders")
            ->first();

        $orderCount = (int) ($summary->order_count ?? 0);
        $turnover = BigDecimal::of((string) ($summary->turnover ?? '0'));

        $revenueByDate = (clone $orders)
            ->selectRaw('DATE(orders.order_date) as report_date')
            ->selectRaw('SUM(orders.total) as amount')
            ->groupBy('report_date')
            ->get()
            ->keyBy('report_date');

        $expenseQuery = DB::table('expenses')
            ->where('tenant_id', $tenantId)
            ->whereBetween('spent_at', [$from, $to]);

        if ($branchId !== null) {
            $expenseQuery
                ->whereIn('location_type', ['outlet', 'workshop'])
                ->where('location_id', $branchId);
        }

        $expensesByDate = (clone $expenseQuery)
            ->selectRaw('DATE(spent_at) as report_date')
            ->selectRaw('SUM(amount) as amount')
            ->groupBy('report_date')
            ->get()
            ->keyBy('report_date');

        $expensesTotal = BigDecimal::of((string) ((clone $expenseQuery)->sum('amount') ?: '0'));

        $dailyTrend = $revenueByDate->keys()
            ->merge($expensesByDate->keys())
            ->unique()
            ->sort()
            ->map(fn (string $date): array => [
                'date' => $date,
                'revenue' => $this->money((string) ($revenueByDate->get($date)->amount ?? '0')),
                'expenses' => $this->money((string) ($expensesByDate->get($date)->amount ?? '0')),
            ])
            ->values()
            ->all();

        $promoImpact = (clone $orders)
            ->whereNotNull('orders.promo_id')
            ->where('orders.promo_discount', '>', 0)
            ->select('orders.promo_name_snapshot as promo_name')
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw('SUM(orders.promo_discount) as discount_total')
            ->groupBy('orders.promo_name_snapshot')
            ->orderByDesc('discount_total')
            ->get()
            ->map(fn (object $row): array => [
                'promo_name' => $row->promo_name ?? 'Promo tidak diketahui',
                'orders' => (int) $row->order_count,
                'discount_total' => $this->money((string) $row->discount_total),
            ])
            ->values()
            ->all();

        $promoDiscountTotal = BigDecimal::of((string) ((clone $orders)->sum('orders.promo_discount') ?: '0'));

        $outlets = (clone $orders)
            ->join('branches', 'branches.id', '=', 'orders.branch_id')
            ->where('branches.tenant_id', $tenantId)
            ->select('branches.id as branch_id', 'branches.name as branch_name')
            ->selectRaw('COUNT(orders.id) as order_count')
            ->selectRaw('SUM(orders.total) as turnover')
            ->selectRaw("SUM(CASE WHEN orders.status = 'completed' THEN 1 ELSE 0 END) as completed_orders")
            ->groupBy('branches.id', 'branches.name')
            ->orderByDesc('turnover')
            ->get()
            ->map(function (object $row): array {
                $count = (int) $row->order_count;
                $amount = BigDecimal::of((string) $row->turnover);

                return [
                    'branch_id' => (int) $row->branch_id,
                    'branch_name' => $row->branch_name,
                    'orders' => $count,
                    'completed_orders' => (int) $row->completed_orders,
                    'turnover' => $this->money((string) $amount),
                    'average_order_value' => $this->average((string) $amount, $count),
                ];
            })
            ->values()
            ->all();

        $services = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.tenant_id', $tenantId)
            ->whereBetween('orders.order_date', [$from, $to])
            ->where('orders.status', '!=', 'cancelled')
            ->when($branchId !== null, fn ($query) => $query->where('orders.branch_id', $branchId))
            ->select('order_items.service_id', 'order_items.service_name')
            ->selectRaw('COUNT(DISTINCT orders.id) as order_count')
            ->selectRaw('SUM(order_items.quantity) as total_quantity')
            ->selectRaw('SUM(order_items.subtotal) as gross_value')
            ->groupBy('order_items.service_id', 'order_items.service_name')
            ->orderByDesc('gross_value')
            ->get()
            ->map(fn (object $row): array => [
                'service_id' => (int) $row->service_id,
                'service_name' => $row->service_name,
                'orders' => (int) $row->order_count,
                'quantity' => $this->quantity((string) $row->total_quantity),
                'gross_value' => $this->money((string) $row->gross_value),
            ])
            ->values()
            ->all();

        $production = DB::table('production_logs')
            ->join('orders', 'orders.id', '=', 'production_logs.order_id')
            ->join('users', 'users.id', '=', 'production_logs.worker_id')
            ->where('production_logs.tenant_id', $tenantId)
            ->where('users.tenant_id', $tenantId)
            ->where('orders.tenant_id', $tenantId)
            ->whereBetween('production_logs.started_at', [$from, $to])
            ->when($branchId !== null, fn ($query) => $query->where('orders.branch_id', $branchId))
            ->select('users.id as user_id', 'users.name as employee_name')
            ->selectRaw('COUNT(DISTINCT production_logs.order_id) as assigned_orders')
            ->selectRaw('COUNT(production_logs.id) as production_stages')
            ->selectRaw('SUM(CASE WHEN production_logs.finished_at IS NOT NULL THEN 1 ELSE 0 END) as completed_stages')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('completed_stages')
            ->get()
            ->keyBy('user_id');

        $attendance = DB::table('attendances')
            ->join('users', 'users.id', '=', 'attendances.user_id')
            ->where('attendances.tenant_id', $tenantId)
            ->where('users.tenant_id', $tenantId)
            ->whereBetween('attendances.clock_in', [$from, $to])
            ->when($branchId !== null, fn ($query) => $query->where('attendances.branch_id', $branchId))
            ->select('attendances.user_id', 'users.name as employee_name')
            ->selectRaw('COUNT(attendances.id) as attendance_records')
            ->groupBy('attendances.user_id', 'users.name')
            ->get()
            ->keyBy('user_id');

        $commissions = DB::table('commission_logs')
            ->join('orders', 'orders.id', '=', 'commission_logs.order_id')
            ->join('users', 'users.id', '=', 'commission_logs.user_id')
            ->where('commission_logs.tenant_id', $tenantId)
            ->where('orders.tenant_id', $tenantId)
            ->where('users.tenant_id', $tenantId)
            ->whereBetween('commission_logs.earned_at', [$from, $to])
            ->when($branchId !== null, fn ($query) => $query->where('orders.branch_id', $branchId))
            ->select('users.id as user_id', 'users.name as employee_name')
            ->selectRaw('SUM(commission_logs.amount) as commission_earned')
            ->groupBy('users.id', 'users.name')
            ->get()
            ->keyBy('user_id');

        // Commission can be earned in a different reporting period from the
        // production stage start, so include employees found by any of the reporting queries.
        $employeeIds = $production->pluck('user_id')
            ->merge($commissions->keys())
            ->merge($attendance->keys())
            ->unique()
            ->values();

        $employees = $employeeIds
            ->map(function (int $userId) use ($production, $commissions, $attendance): array {
                $productionRow = $production->get($userId);
                $commissionRow = $commissions->get($userId);
                $attendanceRow = $attendance->get($userId);

                return [
                    'user_id' => $userId,
                    'employee_name' => $productionRow?->employee_name ?? $commissionRow?->employee_name ?? $attendanceRow?->employee_name ?? '—',
                    'assigned_orders' => (int) ($productionRow->assigned_orders ?? 0),
                    'production_stages' => (int) ($productionRow->production_stages ?? 0),
                    'completed_stages' => (int) ($productionRow->completed_stages ?? 0),
                    'attendance_records' => (int) ($attendanceRow->attendance_records ?? 0),
                    'commission_earned' => $this->money((string) ($commissionRow->commission_earned ?? '0')),
                ];
            })
            ->sortByDesc('commission_earned')
            ->values()
            ->all();

        return [
            'from' => $from->toDateTimeString(),
            'to' => $to->toDateTimeString(),
            'summary' => [
                'orders' => $orderCount,
                'completed_orders' => (int) ($summary->completed_orders ?? 0),
                'turnover' => $this->money((string) $turnover),
                'average_order_value' => $this->average((string) $turnover, $orderCount),
                'expenses_total' => $this->money((string) $expensesTotal),
                'promo_discount_total' => $this->money((string) $promoDiscountTotal),
            ],
            'daily_trend' => $dailyTrend,
            'promo_impact' => $promoImpact,
            'outlets' => $outlets,
            'services' => $services,
            'employees' => $employees,
        ];
    }

    private function assertAccess(int $tenantId, ?int $branchId): void
    {
        $user = Auth::user();

        if ($user === null || ! $user->is_active || ! $user->hasPermissionTo('reports.view')) {
            throw new DomainException('Anda tidak memiliki izin untuk melihat analitik performa usaha.');
        }

        if ($user->role !== 'super_admin' && ($user->tenant_id === null || (int) $user->tenant_id !== $tenantId)) {
            throw new DomainException('Tenant analitik tidak sesuai dengan tenant pengguna.');
        }

        if ($branchId === null) {
            if (! in_array($user->role, ['tenant_owner', 'super_admin'], true)) {
                throw new DomainException('Pengguna cabang hanya dapat melihat analitik untuk cabang yang ditugaskan.');
            }

            return;
        }

        $branchExists = Branch::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereKey($branchId)
            ->exists();

        if (! $branchExists) {
            throw new DomainException('Cabang analitik tidak ditemukan atau bukan milik tenant ini.');
        }

        if (
            ! in_array($user->role, ['tenant_owner', 'super_admin'], true)
            && ! $user->branches()
                ->whereKey($branchId)
                ->where('branches.tenant_id', $tenantId)
                ->where('branches.is_active', true)
                ->exists()
        ) {
            throw new DomainException('Pengguna tidak memiliki akses ke cabang analitik ini.');
        }
    }

    private function assertDateRange(CarbonInterface $from, CarbonInterface $to): void
    {
        if ($from->greaterThan($to)) {
            throw new DomainException('Tanggal awal analitik tidak boleh melewati tanggal akhir.');
        }
    }

    private function money(string $amount): string
    {
        return (string) BigDecimal::of($amount)->toScale(2, RoundingMode::HalfUp);
    }

    private function average(string $amount, int $count): string
    {
        if ($count === 0) {
            return '0.00';
        }

        return (string) BigDecimal::of($amount)->dividedBy($count, 2, RoundingMode::HalfUp);
    }

    private function quantity(string $amount): string
    {
        return (string) BigDecimal::of($amount)->toScale(2, RoundingMode::HalfUp);
    }
}
