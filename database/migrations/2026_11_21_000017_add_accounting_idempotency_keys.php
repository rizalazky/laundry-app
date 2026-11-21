<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_settlements', function (Blueprint $table) {
            $table->string('idempotency_key', 128)->nullable()->after('reference_number');
            $table->char('payload_hash', 64)->nullable()->after('idempotency_key');
            $table->unique(['tenant_id', 'reference_number'], 'cash_settlements_tenant_reference_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'cash_settlements_tenant_idempotency_unique');
        });

        Schema::table('bank_transfers', function (Blueprint $table) {
            $table->string('idempotency_key', 128)->nullable()->after('reference_number');
            $table->char('payload_hash', 64)->nullable()->after('idempotency_key');
            $table->unique(['tenant_id', 'reference_number'], 'bank_transfers_tenant_reference_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'bank_transfers_tenant_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('bank_transfers', function (Blueprint $table) {
            $table->dropUnique('bank_transfers_tenant_reference_unique');
            $table->dropUnique('bank_transfers_tenant_idempotency_unique');
            $table->dropColumn(['idempotency_key', 'payload_hash']);
        });

        Schema::table('cash_settlements', function (Blueprint $table) {
            $table->dropUnique('cash_settlements_tenant_reference_unique');
            $table->dropUnique('cash_settlements_tenant_idempotency_unique');
            $table->dropColumn(['idempotency_key', 'payload_hash']);
        });
    }
};
