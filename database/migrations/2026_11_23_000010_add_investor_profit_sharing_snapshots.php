<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profit_sharings', function (Blueprint $table) {
            $table->decimal('share_percent_snapshot', 5, 2)->default(0)->after('net_profit_base');
            $table->unsignedBigInteger('journal_id')->nullable()->after('paid_at');
            $table->unique(['journal_id', 'tenant_id'], 'profit_sharings_journal_tenant_unique');
            $table->foreign(['journal_id', 'tenant_id'], 'profit_sharings_journal_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('journals')->restrictOnDelete();
        });

        DB::table('tenants')->select('id')->orderBy('id')->each(function (object $tenant): void {
            DB::table('chart_of_accounts')->insertOrIgnore([
                'tenant_id' => $tenant->id,
                'code' => '3300',
                'name' => 'Distribusi Laba Investor',
                'account_type' => 'equity',
                'is_system' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('profit_sharings', function (Blueprint $table) {
            $table->dropForeign('profit_sharings_journal_tenant_foreign');
            $table->dropUnique('profit_sharings_journal_tenant_unique');
            $table->dropColumn(['share_percent_snapshot', 'journal_id']);
        });

    }
};
