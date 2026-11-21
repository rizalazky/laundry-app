<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edc_machines', function (Blueprint $table) {
            $table->unique(['id', 'tenant_id'], 'edc_machines_id_tenant_unique');
        });

        Schema::table('cash_settlements', function (Blueprint $table) {
            $table->decimal('gross_amount', 15, 2)->default(0)->after('amount');
            $table->decimal('mdr_amount', 15, 2)->default(0)->after('gross_amount');
            $table->decimal('net_amount', 15, 2)->default(0)->after('mdr_amount');
            $table->unsignedBigInteger('edc_machine_id')->nullable()->after('net_amount');
            $table->string('reference_number', 100)->nullable()->after('target_bank_id');

            $table->foreign(
                ['edc_machine_id', 'tenant_id'],
                'cash_settlements_edc_tenant_foreign',
            )->references(['id', 'tenant_id'])->on('edc_machines')->restrictOnDelete();

            $table->index(['tenant_id', 'target_bank_id', 'settled_at'], 'cash_settlements_bank_date_index');
        });

        // Preserve historical settlement values before gross/MDR/net were separated.
        DB::table('cash_settlements')->update([
            'gross_amount' => DB::raw('amount'),
            'mdr_amount' => 0,
            'net_amount' => DB::raw('amount'),
        ]);
    }

    public function down(): void
    {
        Schema::table('cash_settlements', function (Blueprint $table) {
            $table->dropIndex('cash_settlements_bank_date_index');
            $table->dropForeign('cash_settlements_edc_tenant_foreign');
            $table->dropColumn([
                'gross_amount', 'mdr_amount', 'net_amount', 'edc_machine_id', 'reference_number',
            ]);
        });

        Schema::table('edc_machines', function (Blueprint $table) {
            $table->dropUnique('edc_machines_id_tenant_unique');
        });
    }
};
