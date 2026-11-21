<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 40);
            $table->string('transfer_type', 20)->default('distribution');
            $table->unsignedBigInteger('source_branch_id');
            $table->unsignedBigInteger('destination_branch_id');
            $table->string('status', 20)->default('requested');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('shipped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'reference']);
            $table->unique(['id', 'tenant_id'], 'stock_transfers_id_tenant_unique');
            $table->index(['tenant_id', 'status', 'requested_at']);
            $table->index(['tenant_id', 'source_branch_id', 'status']);
            $table->index(['tenant_id', 'destination_branch_id', 'status']);
            $table->foreign(['source_branch_id', 'tenant_id'], 'stock_transfers_source_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['destination_branch_id', 'tenant_id'], 'stock_transfers_destination_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('branches')->restrictOnDelete();
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('stock_transfer_id');
            $table->unsignedBigInteger('inventory_item_id');
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['stock_transfer_id', 'inventory_item_id']);
            $table->foreign(['stock_transfer_id', 'tenant_id'], 'stock_transfer_items_transfer_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('stock_transfers')->cascadeOnDelete();
            $table->foreign(['inventory_item_id', 'tenant_id'], 'stock_transfer_items_item_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('inventory_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
    }
};
