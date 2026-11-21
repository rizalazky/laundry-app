<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;

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
            $table->string('item_name_snapshot')->nullable()->after('inventory_item_id');
            $table->string('item_sku_snapshot')->nullable()->after('item_name_snapshot');
            $table->string('unit_snapshot', 100)->nullable()->after('item_sku_snapshot');
            $table->unsignedTinyInteger('decimal_places_snapshot')->default(4)->after('unit_snapshot');
            $table->timestamp('counted_at')->nullable()->after('difference_qty');
        });

        $existingItems = DB::table('stock_opname_items as opname_items')
            ->leftJoin('inventory_items as items', 'items.id', '=', 'opname_items.inventory_item_id')
            ->leftJoin('inventory_units as units', 'units.id', '=', 'items.base_unit_id')
            ->select([
                'opname_items.id',
                'opname_items.inventory_item_id',
                'items.name as item_name',
                'items.sku as item_sku',
                'items.unit as legacy_unit',
                'units.code as base_unit_code',
                'units.decimal_places as base_unit_decimal_places',
            ])
            ->get();

        foreach ($existingItems as $line) {
            DB::table('stock_opname_items')
                ->where('id', $line->id)
                ->update([
                    'item_name_snapshot' => $line->item_name ?? 'Barang #'.$line->inventory_item_id,
                    'item_sku_snapshot' => $line->item_sku,
                    'unit_snapshot' => $line->base_unit_code ?? $line->legacy_unit,
                    'decimal_places_snapshot' => min((int) ($line->base_unit_decimal_places ?? 4), 4),
                ]);
        }

        Schema::table('stock_opname_items', function (Blueprint $table) {
            $table->string('item_name_snapshot')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        // Legacy schema requires numeric counts. Treat uncounted draft rows as unchanged
        // from their system snapshot before restoring the original NOT NULL constraints.
        DB::table('stock_opname_items')
            ->whereNull('physical_qty')
            ->update(['physical_qty' => DB::raw('system_qty')]);

        DB::table('stock_opname_items')
            ->whereNull('difference_qty')
            ->update(['difference_qty' => 0]);

        Schema::table('stock_opname_items', function (Blueprint $table) {
            $table->dropColumn([
                'item_name_snapshot',
                'item_sku_snapshot',
                'unit_snapshot',
                'decimal_places_snapshot',
                'counted_at',
            ]);
            $table->decimal('physical_qty', 18, 4)->nullable(false)->change();
            $table->decimal('difference_qty', 18, 4)->nullable(false)->change();
        });

        Schema::table('stock_opnames', function (Blueprint $table) {
            $table->dropForeign(['finalized_by']);
            $table->dropColumn(['finalized_by', 'finalized_at']);
        });
    }
};
