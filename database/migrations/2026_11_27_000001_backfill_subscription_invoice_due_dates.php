<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('subscription_invoices')
            ->whereNull('due_at')
            ->whereIn('status', ['issued', 'pending'])
            ->orderBy('id')
            ->chunkById(100, function ($invoices): void {
                foreach ($invoices as $invoice) {
                    DB::table('subscription_invoices')
                        ->where('id', $invoice->id)
                        ->update([
                            'due_at' => Carbon::parse($invoice->created_at)->addHours(24),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Keep generated due dates; the original null values cannot be
        // distinguished from dates explicitly assigned after this migration.
    }
};
