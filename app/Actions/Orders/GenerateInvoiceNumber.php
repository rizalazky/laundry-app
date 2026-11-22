<?php

namespace App\Actions\Orders;

use App\Models\Branch;
use App\Models\InvoiceSequence;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class GenerateInvoiceNumber
{
    /**
     * Build the next invoice number for a branch, for example PUSAT-261119-0001.
     * Must run inside a transaction so the row lock serializes concurrent orders.
     */
    public function handle(Branch $branch, CarbonInterface $date): string
    {
        if (! Branch::query()->whereKey($branch->getKey())->exists()) {
            throw (new ModelNotFoundException())->setModel(Branch::class, [$branch->getKey()]);
        }

        $sequenceDate = $date->toDateString();

        DB::table('invoice_sequences')->insertOrIgnore([
            'branch_id' => $branch->getKey(),
            'sequence_date' => $sequenceDate,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = InvoiceSequence::query()
            ->where('branch_id', $branch->getKey())
            ->whereDate('sequence_date', $sequenceDate)
            ->lockForUpdate()
            ->firstOrFail();

        $sequence->increment('last_number');

        $prefix = $branch->tenant?->getInvoicePrefix() ?? $branch->code;

        return sprintf(
            '%s-%s-%04d',
            strtoupper($prefix),
            $date->format('ymd'),
            $sequence->last_number,
        );
    }
}
