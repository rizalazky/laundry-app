<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            // Extend the shared movement ledger created by PR #30.
            $table->unsignedBigInteger('branch_id')->nullable()->after('tenant_id');
            $table->decimal('stock_before', 18, 4)->nullable()->after('quantity');
            $table->decimal('stock_after', 18, 4)->nullable()->after('stock_before');
            $table->text('notes')->nullable()->after('stock_after');

            $table->index(['tenant_id', 'branch_id', 'created_at'], 'stock_movements_tenant_branch_created_index');
            $table->foreign(['branch_id', 'tenant_id'], 'stock_movements_branch_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('branches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign('stock_movements_branch_tenant_foreign');
            $table->dropIndex('stock_movements_tenant_branch_created_index');
            $table->dropColumn(['branch_id', 'stock_before', 'stock_after', 'notes']);
        });
    }
};
