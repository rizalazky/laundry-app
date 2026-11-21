<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('inventory_item_id');
            $table->string('movement_type', 20);
            // In/out use positive input quantities; out movements are stored as negative deltas.
            // Adjustments store a signed delta.
            $table->decimal('quantity', 18, 4);
            $table->decimal('stock_before', 18, 4);
            $table->decimal('stock_after', 18, 4);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at');

            $table->index(['tenant_id', 'branch_id', 'created_at'], 'stock_movements_tenant_branch_created_index');
            $table->index(['tenant_id', 'inventory_item_id', 'created_at'], 'stock_movements_tenant_item_created_index');

            $table->foreign(['branch_id', 'tenant_id'], 'stock_movements_branch_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['inventory_item_id', 'tenant_id'], 'stock_movements_item_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('inventory_items')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
