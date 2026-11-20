<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->unique(['id', 'tenant_id'], 'branches_id_tenant_unique');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->unique(['id', 'tenant_id'], 'services_id_tenant_unique');
        });

        Schema::table('branch_service_prices', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
        });

        DB::statement(
            'UPDATE branch_service_prices SET tenant_id = (SELECT services.tenant_id FROM services WHERE services.id = branch_service_prices.service_id)'
        );

        $hasCrossTenantPrices = DB::table('branch_service_prices')
            ->join('branches', 'branches.id', '=', 'branch_service_prices.branch_id')
            ->whereColumn('branches.tenant_id', '!=', 'branch_service_prices.tenant_id')
            ->exists();

        if ($hasCrossTenantPrices) {
            throw new RuntimeException(
                'Cannot enforce tenant consistency: branch service prices contain cross-tenant references.'
            );
        }

        Schema::table('branch_service_prices', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable(false)->change();

            $table->dropForeign(['branch_id']);
            $table->dropForeign(['service_id']);

            $table->foreign(['branch_id', 'tenant_id'], 'branch_service_prices_branch_tenant_foreign')
                ->references(['id', 'tenant_id'])
                ->on('branches')
                ->cascadeOnDelete();

            $table->foreign(['service_id', 'tenant_id'], 'branch_service_prices_service_tenant_foreign')
                ->references(['id', 'tenant_id'])
                ->on('services')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('branch_service_prices', function (Blueprint $table) {
            $table->dropForeign('branch_service_prices_branch_tenant_foreign');
            $table->dropForeign('branch_service_prices_service_tenant_foreign');

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('service_id')->references('id')->on('services')->cascadeOnDelete();

            $table->dropColumn('tenant_id');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropUnique('services_id_tenant_unique');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropUnique('branches_id_tenant_unique');
        });
    }
};
