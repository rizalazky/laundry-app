<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->string('payment_source_type', 20)->default('cash')->after('coa_id');
            $table->unsignedBigInteger('bank_account_id')->nullable()->after('payment_source_type');

            $table->index(['tenant_id', 'payment_source_type', 'spent_at'], 'expenses_tenant_source_date_index');
            $table->foreign(['bank_account_id', 'tenant_id'], 'expenses_bank_tenant_foreign')
                ->references(['id', 'tenant_id'])
                ->on('bank_accounts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropForeign('expenses_bank_tenant_foreign');
            $table->dropIndex('expenses_tenant_source_date_index');
            $table->dropColumn(['payment_source_type', 'bank_account_id']);
        });
    }
};
