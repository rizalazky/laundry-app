<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->unique(['id', 'tenant_id'], 'suppliers_id_tenant_unique');
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->unsignedBigInteger('supplier_id')->nullable()->after('inventory_item_id');
            $table->string('supplier_name_snapshot')->nullable()->after('supplier_id');
            $table->decimal('purchase_quantity', 18, 4)->nullable()->after('supplier_id');
            $table->unsignedBigInteger('purchase_unit_id')->nullable()->after('purchase_quantity');
            $table->string('purchase_unit_name_snapshot', 100)->nullable()->after('purchase_unit_id');
            $table->decimal('purchase_conversion_factor', 18, 6)->nullable()->after('purchase_unit_name_snapshot');

            $table->index(['tenant_id', 'supplier_id', 'created_at'], 'stock_movements_tenant_supplier_created_index');
            $table->foreign(['supplier_id', 'tenant_id'], 'stock_movements_supplier_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('suppliers')->restrictOnDelete();
            $table->foreign(['purchase_unit_id', 'tenant_id'], 'stock_movements_purchase_unit_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('inventory_units')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropForeign('stock_movements_supplier_tenant_foreign');
            $table->dropForeign('stock_movements_purchase_unit_tenant_foreign');
            $table->dropIndex('stock_movements_tenant_supplier_created_index');
            $table->dropColumn([
                'supplier_id',
                'supplier_name_snapshot',
                'purchase_quantity',
                'purchase_unit_id',
                'purchase_unit_name_snapshot',
                'purchase_conversion_factor',
            ]);
        });

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropUnique('suppliers_id_tenant_unique');
        });
    }
};
