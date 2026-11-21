<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing deployments may contain duplicate source references because
        // the original schema only had a non-unique index. Never delete or merge
        // accounting journals automatically: their entries require reconciliation.
        $duplicates = \Illuminate\\Support\\Facades\\DB::table('journals')
            ->select('tenant_id', 'reference_type', 'reference_id')
            ->selectRaw('COUNT(*) as duplicate_count')
            ->whereNotNull('reference_type')
            ->whereNotNull('reference_id')
            ->groupBy('tenant_id', 'reference_type', 'reference_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $details = $duplicates->map(function ($duplicate) {
                $journalIds = \Illuminate\\Support\\Facades\\DB::table('journals')
                    ->where('tenant_id', $duplicate->tenant_id)
                    ->where('reference_type', $duplicate->reference_type)
                    ->where('reference_id', $duplicate->reference_id)
                    ->orderBy('id')
                    ->pluck('id')
                    ->implode(', ');

                return sprintf(
                    'tenant_id=%s, reference_type=%s, reference_id=%s, journal_ids=[%s]',
                    $duplicate->tenant_id,
                    $duplicate->reference_type,
                    $duplicate->reference_id,
                    $journalIds,
                );
            })->implode('; ');

            throw new \RuntimeException(
                'Cannot enforce unique journal source references because duplicate journals exist. '
                .'Reconcile these journal records and their entries, then rerun the migration: '.$details
            );
        }

        Schema::table('journals', function (Blueprint $table) {
            // The unique index also serves the existing reference lookup pattern.
            // Replace the non-unique index instead of maintaining both.
            $table->dropIndex('journals_tenant_reference_index');

            // A source transaction may produce at most one journal per tenant.
            // Null references remain available for manually entered journals.
            $table->unique(
                ['tenant_id', 'reference_type', 'reference_id'],
                'journals_tenant_reference_unique',
            );
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            // Supports tenant-scoped general-ledger queries by account and journal.
            $table->index(
                ['tenant_id', 'coa_id', 'journal_id'],
                'journal_entries_tenant_coa_journal_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex('journal_entries_tenant_coa_journal_index');
        });

        Schema::table('journals', function (Blueprint $table) {
            $table->dropUnique('journals_tenant_reference_unique');
            $table->index(
                ['tenant_id', 'reference_type', 'reference_id'],
                'journals_tenant_reference_index',
            );
        });
    }
};
