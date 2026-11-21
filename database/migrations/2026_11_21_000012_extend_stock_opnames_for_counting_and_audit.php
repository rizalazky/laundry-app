<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_opnames', function (Blueprint $table) {
            $table->unsignedBigInteger('finalized_by')->nullable()->after('created_by');
            $table->timestamp('finalized_at')->nullable()->after('finalized_by');
            $table->foreign('finalized_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('stock_opname_items', function (Blueprint $table) {
            $table->decimal('physical_qty', 18, 4)->nullable()->change();
            $table->decimal('difference_qty', 18, 4)->nullable()->change();
            $table->string('item_name_snapshot')->after('inventory_item_id');
            $table->string('item_sku_snapshot')->nullable()->after('item_name_snapshot');
            $table->string('unit_snapshot', 100)->nullable()->after('item_sku_snapshot');
            $table->unsignedTinyInteger('decimal_places_snapshot')->default(4)->after('unit_snapshot');
            $table->timestamp('counted_at')->nullable()->after('difference_qty');
        });
    }

    public function down(): void
    {
        Schema::table('stock_opname_items', function (Blueprint $table) {
            $table->dropColumn([
                'item_name_snapshot',
                'item_sku_snapshot',
                'unit_snapshot',
                'decimal_places_snapshot',
                'counted_at',
            ]);
            $table->decimal('physical_qty', 18, 4)->default(0)->change();
            $table->decimal('difference_qty', 18, 4)->default(0)->change();
        });

        Schema::table('stock_opnames', function (Blueprint $table) {
            $table->dropForeign(['finalized_by']);
            $table->dropColumn(['finalized_by', 'finalized_at']);
        });
    }
};
