<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Use explicit index statements because SQLite's table-alter rebuild
        // otherwise attempts to create these composite indexes twice.
        DB::statement('CREATE UNIQUE INDEX branches_id_tenant_unique ON branches (id, tenant_id)');
        DB::statement('CREATE UNIQUE INDEX inventory_items_id_tenant_unique ON inventory_items (id, tenant_id)');

        Schema::create('inventory_units', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('code', 50);
            $table->string('name', 100);
            $table->unsignedTinyInteger('decimal_places')->default(4);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['id', 'tenant_id'], 'inventory_units_id_tenant_unique');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->unsignedBigInteger('base_unit_id')->nullable()->after('unit');
            $table->unsignedBigInteger('purchase_unit_id')->nullable()->after('base_unit_id');
            $table->decimal('purchase_unit_conversion_factor', 18, 6)->default(1)->after('purchase_unit_id');

            $table->foreign(['base_unit_id', 'tenant_id'], 'inventory_items_base_unit_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('inventory_units')->restrictOnDelete();
            $table->foreign(['purchase_unit_id', 'tenant_id'], 'inventory_items_purchase_unit_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('inventory_units')->restrictOnDelete();
        });

        // Preserve existing free-text units by creating one tenant-owned unit
        // for each distinct legacy value and using it as both base and purchase unit.
        $legacyItems = DB::table('inventory_items')
            ->select(['id', 'tenant_id', 'unit'])
            ->orderBy('id')
            ->get();

        $unitIds = [];

        foreach ($legacyItems as $item) {
            $name = trim((string) $item->unit);
            $code = Str::lower($name);

            if ($code === '') {
                $code = 'unit';
                $name = 'Unit';
            }

            $key = $item->tenant_id.'|'.$code;

            if (! isset($unitIds[$key])) {
                $existing = DB::table('inventory_units')
                    ->where('tenant_id', $item->tenant_id)
                    ->where('code', $code)
                    ->value('id');

                if ($existing === null) {
                    $now = now();
                    $existing = DB::table('inventory_units')->insertGetId([
                        'tenant_id' => $item->tenant_id,
                        'code' => $code,
                        'name' => $name,
                        'decimal_places' => 4,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $unitIds[$key] = $existing;
            }

            DB::table('inventory_items')
                ->where('id', $item->id)
                ->update([
                    'base_unit_id' => $unitIds[$key],
                    'purchase_unit_id' => $unitIds[$key],
                ]);
        }

        Schema::create('location_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('inventory_item_id');
            $table->decimal('quantity', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'branch_id', 'inventory_item_id'],
                'location_stocks_tenant_branch_item_unique'
            );
            $table->index(['tenant_id', 'inventory_item_id'], 'location_stocks_tenant_item_index');

            $table->foreign(['branch_id', 'tenant_id'], 'location_stocks_branch_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['inventory_item_id', 'tenant_id'], 'location_stocks_item_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('inventory_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_stocks');

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropForeign('inventory_items_base_unit_tenant_foreign');
            $table->dropForeign('inventory_items_purchase_unit_tenant_foreign');
            $table->dropColumn([
                'base_unit_id',
                'purchase_unit_id',
                'purchase_unit_conversion_factor',
            ]);
        });

        Schema::dropIfExists('inventory_units');

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropUnique('inventory_items_id_tenant_unique');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropUnique('branches_id_tenant_unique');
        });
    }
};
