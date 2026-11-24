<?php

namespace App\Console\Commands;

use App\Actions\MassInvoices\GenerateMassInvoices;
use App\Models\MassInvoiceSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunDueMassInvoiceSchedules extends Command
{
    protected $signature = 'mass-invoices:run-due';

    protected $description = 'Generate scheduled mass invoices for customers with outstanding orders';

    public function handle(GenerateMassInvoices $generator): int
    {
        $dueSchedules = MassInvoiceSchedule::query()
            ->withoutGlobalScope('tenant')
            ->where('is_active', true)
            ->where('next_run_at', '<=', now())
            ->orderBy('next_run_at')
            ->pluck('id');

        foreach ($dueSchedules as $scheduleId) {
            $schedule = MassInvoiceSchedule::query()
                ->withoutGlobalScope('tenant')
                ->find($scheduleId);

            if ($schedule === null) {
                continue;
            }

            try {
                $count = $generator->handle($schedule, scheduled: true);
                $this->info("Schedule {$scheduleId}: {$count} faktur dibuat.");
            } catch (Throwable $exception) {
                Log::error('Scheduled mass invoice generation failed.', [
                    'schedule_id' => $scheduleId,
                    'exception' => $exception,
                ]);

                $this->error("Schedule {$scheduleId}: {$exception->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
