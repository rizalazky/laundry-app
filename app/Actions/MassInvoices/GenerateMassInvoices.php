<?php

namespace App\Actions\MassInvoices;

use App\Models\Customer;
use App\Models\MassInvoice;
use App\Models\MassInvoiceItem;
use App\Models\MassInvoiceSchedule;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class GenerateMassInvoices
{
    /**
     * Generate one invoice per selected customer. Orders are snapshotted once
     * only; the unique order_id on mass_invoice_items is the final duplicate
     * billing guard.
     *
     * @return int Number of customer invoices generated.
     */
    public function handle(MassInvoiceSchedule $schedule, ?User $actor = null, bool $scheduled = false): int
    {
        if ($actor !== null) {
            if (! Auth::hasUser() || (int) Auth::id() !== (int) $actor->getKey()) {
                throw new DomainException('Hanya owner tenant yang dapat menjalankan faktur massal.');
            }

            $actor = User::query()->find($actor->getKey());

            if ($actor === null
                || ! $actor->is_active
                || $actor->role !== 'tenant_owner'
                || $actor->tenant_id === null
                || (int) $actor->tenant_id !== (int) $schedule->tenant_id) {
                throw new DomainException('Hanya owner tenant yang dapat menjalankan faktur massal.');
            }
        } elseif (! $scheduled || Auth::hasUser()) {
            // Manual runs must carry the authenticated owner. Actor-less runs are
            // reserved for the trusted scheduler process, outside web sessions.
            throw new DomainException('Hanya proses terjadwal yang dapat menjalankan faktur tanpa akun owner.');
        }

        return DB::transaction(function () use ($schedule, $actor, $scheduled): int {
            $scheduleQuery = MassInvoiceSchedule::query()
                ->withoutGlobalScope('tenant')
                ->whereKey($schedule->getKey());

            if ($actor !== null) {
                // Never trust tenant_id on the caller-supplied model instance.
                $scheduleQuery->where('tenant_id', $actor->tenant_id);
            }

            $lockedSchedule = $scheduleQuery->lockForUpdate()->first();

            if ($lockedSchedule === null) {
                throw new DomainException('Jadwal faktur tidak ditemukan pada tenant ini.');
            }

            if ($scheduled && ! $lockedSchedule->is_active) {
                throw new DomainException('Jadwal faktur sedang tidak aktif.');
            }

            $tenant = Tenant::query()->find($lockedSchedule->tenant_id);

            if ($tenant === null) {
                throw new DomainException('Data tenant tidak ditemukan.');
            }

            $runAt = $scheduled
                ? Carbon::parse($lockedSchedule->next_run_at, $tenant->getTimezone())
                : Carbon::now($tenant->getTimezone());

            if ($scheduled && $runAt->isFuture()) {
                return 0;
            }

            $periodStart = $lockedSchedule->frequency === 'monthly'
                ? $runAt->copy()->startOfMonth()->toDateString()
                : $runAt->copy()->startOfWeek()->toDateString();
            $periodEnd = $lockedSchedule->frequency === 'monthly'
                ? $runAt->copy()->endOfMonth()->toDateString()
                : $runAt->copy()->endOfWeek()->toDateString();

            $generated = 0;

            $customers = Customer::query()
                ->where('tenant_id', $lockedSchedule->tenant_id)
                ->whereIn('customers.id', $lockedSchedule->customers()->select('customers.id'))
                ->orderBy('customers.id')
                ->get();

            foreach ($customers as $customer) {
                $orders = Order::query()
                    ->withoutGlobalScope('tenant')
                    ->where('tenant_id', $lockedSchedule->tenant_id)
                    ->where('customer_id', $customer->id)
                    ->where('status', '!=', 'cancelled')
                    ->whereIn('payment_status', ['unpaid', 'partial'])
                    ->whereColumn('paid_amount', '<', 'total')
                    ->whereNotExists(function ($query): void {
                        $query->selectRaw('1')
                            ->from('mass_invoice_items')
                            ->whereColumn('mass_invoice_items.order_id', 'orders.id');
                    })
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($orders->isEmpty()) {
                    continue;
                }

                $total = BigDecimal::zero()->toScale(2);

                foreach ($orders as $order) {
                    $total = $total->plus(BigDecimal::of($order->balanceDue()));
                }

                if ($total->isLessThanOrEqualTo(0)) {
                    continue;
                }

                $invoice = MassInvoice::query()->create([
                    'tenant_id' => $lockedSchedule->tenant_id,
                    'mass_invoice_schedule_id' => $lockedSchedule->id,
                    'customer_id' => $customer->id,
                    'invoice_no' => 'PENDING',
                    'billing_period_start' => $periodStart,
                    'billing_period_end' => $periodEnd,
                    'total_amount' => (string) $total,
                    'status' => 'issued',
                    'trigger_type' => $scheduled ? 'scheduled' : 'manual',
                    'generated_at' => $runAt,
                    'triggered_by' => $actor?->getKey(),
                ]);

                $invoice->forceFill([
                    'invoice_no' => 'FMI-'. $runAt->format('Ymd').'-'.str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT),
                ])->save();

                foreach ($orders as $order) {
                    MassInvoiceItem::query()->create([
                        'tenant_id' => $lockedSchedule->tenant_id,
                        'mass_invoice_id' => $invoice->id,
                        'order_id' => $order->id,
                        'balance_snapshot' => $order->balanceDue(),
                    ]);
                }

                $generated++;
            }

            $lockedSchedule->forceFill([
                'last_run_at' => $runAt,
                'next_run_at' => $scheduled
                    ? $lockedSchedule->calculateNextRunAt($runAt)
                    : $lockedSchedule->next_run_at,
            ])->save();

            return $generated;
        });
    }
}
