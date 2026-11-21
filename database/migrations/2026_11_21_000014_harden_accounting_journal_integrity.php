<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journals', function (Blueprint $table) {
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
        });
    }
};
