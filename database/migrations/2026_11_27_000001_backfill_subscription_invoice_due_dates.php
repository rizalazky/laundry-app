<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('subscription_invoices')
            ->whereNull('due_at')
            ->whereIn('status', ['issued', 'pending'])
            ->update([
                'due_at' => DB::raw('DATE_ADD(created_at, INTERVAL 24 HOUR)'),
            ]);
    }

    public function down(): void
    {
        // Keep generated due dates; the original null values cannot be
        // distinguished from dates explicitly assigned after this migration.
    }
};
