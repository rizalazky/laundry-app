<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasInvalidProductionLogs = DB::table('production_logs as production')
            ->join('orders', 'orders.id', '=', 'production.order_id')
            ->whereColumn('production.tenant_id', '!=', 'orders.tenant_id')
            ->exists()
            || DB::table('production_logs as production')
                ->join('users', 'users.id', '=', 'production.worker_id')
                ->whereNotNull('production.worker_id')
                ->whereColumn('production.tenant_id', '!=', 'users.tenant_id')
                ->exists();

        $hasInvalidCommissionLogs = DB::table('commission_logs as commissions')
            ->leftJoin('orders', 'orders.id', '=', 'commissions.order_id')
            ->leftJoin('order_items', 'order_items.id', '=', 'commissions.order_item_id')
            ->leftJoin('production_logs', 'production_logs.id', '=', 'commissions.production_log_id')
            ->leftJoin('users', 'users.id', '=', 'commissions.user_id')
            ->leftJoin('services', 'services.id', '=', 'commissions.service_id')
            ->where(function ($query): void {
                $query
                    ->whereNull('orders.id')
                    ->orWhereColumn('orders.tenant_id', '!=', 'commissions.tenant_id')
                    ->orWhereNull('order_items.id')
                    ->orWhereColumn('order_items.order_id', '!=', 'commissions.order_id')
                    ->orWhereColumn('order_items.service_id', '!=', 'commissions.service_id')
                    ->orWhereNull('production_logs.id')
                    ->orWhereColumn('production_logs.order_id', '!=', 'commissions.order_id')
                    ->orWhereColumn('production_logs.tenant_id', '!=', 'commissions.tenant_id')
                    ->orWhereColumn('production_logs.worker_id', '!=', 'commissions.user_id')
                    ->orWhereNull('users.id')
                    ->orWhereColumn('users.tenant_id', '!=', 'commissions.tenant_id')
                    ->orWhere(function ($query): void {
                        $query->whereNotNull('commissions.service_id')
                            ->where(function ($query): void {
                                $query->whereNull('services.id')
                                    ->orWhereColumn('services.tenant_id', '!=', 'commissions.tenant_id');
                            });
                    });
            })
            ->exists();

        if ($hasInvalidProductionLogs || $hasInvalidCommissionLogs) {
            throw new RuntimeException(
                'Cannot enforce production and commission tenant integrity: existing records contain cross-tenant or cross-order references.'
            );
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->unique(['id', 'tenant_id'], 'users_id_tenant_unique');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->unique(['id', 'order_id'], 'order_items_id_order_unique');
            $table->unique(['id', 'service_id'], 'order_items_id_service_unique');
        });

        Schema::table('production_logs', function (Blueprint $table): void {
            $table->unique(['id', 'tenant_id'], 'production_logs_id_tenant_unique');
            $table->unique(['id', 'order_id'], 'production_logs_id_order_unique');
            $table->unique(['id', 'worker_id'], 'production_logs_id_worker_unique');

            $table->dropForeign(['order_id']);
            $table->dropForeign(['worker_id']);

            $table->foreign(['order_id', 'tenant_id'], 'production_logs_order_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('orders')->cascadeOnDelete();
            $table->foreign(['worker_id', 'tenant_id'], 'production_logs_worker_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('users')->nullOnDelete();
        });

        Schema::table('commission_logs', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['order_id']);
            $table->dropForeign(['order_item_id']);
            $table->dropForeign(['production_log_id']);
            $table->dropForeign(['service_id']);

            $table->foreign(['order_id', 'tenant_id'], 'commission_logs_order_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('orders')->cascadeOnDelete();
            $table->foreign(['order_item_id', 'order_id'], 'commission_logs_item_order_foreign')
                ->references(['id', 'order_id'])->on('order_items')->cascadeOnDelete();
            $table->foreign(['order_item_id', 'service_id'], 'commission_logs_item_service_foreign')
                ->references(['id', 'service_id'])->on('order_items')->restrictOnDelete();
            $table->foreign(['production_log_id', 'order_id'], 'commission_logs_production_order_foreign')
                ->references(['id', 'order_id'])->on('production_logs')->cascadeOnDelete();
            $table->foreign(['production_log_id', 'user_id'], 'commission_logs_production_worker_foreign')
                ->references(['id', 'worker_id'])->on('production_logs')->cascadeOnDelete();
            $table->foreign(['user_id', 'tenant_id'], 'commission_logs_user_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
            $table->foreign(['service_id', 'tenant_id'], 'commission_logs_service_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('services')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('commission_logs', function (Blueprint $table): void {
            $table->dropForeign('commission_logs_order_tenant_foreign');
            $table->dropForeign('commission_logs_item_order_foreign');
            $table->dropForeign('commission_logs_item_service_foreign');
            $table->dropForeign('commission_logs_production_order_foreign');
            $table->dropForeign('commission_logs_production_worker_foreign');
            $table->dropForeign('commission_logs_user_tenant_foreign');
            $table->dropForeign('commission_logs_service_tenant_foreign');

            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('order_item_id')->references('id')->on('order_items')->cascadeOnDelete();
            $table->foreign('production_log_id')->references('id')->on('production_logs')->cascadeOnDelete();
            $table->foreign('service_id')->references('id')->on('services')->nullOnDelete();
        });

        Schema::table('production_logs', function (Blueprint $table): void {
            $table->dropForeign('production_logs_order_tenant_foreign');
            $table->dropForeign('production_logs_worker_tenant_foreign');

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('worker_id')->references('id')->on('users')->nullOnDelete();

            $table->dropUnique('production_logs_id_tenant_unique');
            $table->dropUnique('production_logs_id_order_unique');
            $table->dropUnique('production_logs_id_worker_unique');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropUnique('order_items_id_order_unique');
            $table->dropUnique('order_items_id_service_unique');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_id_tenant_unique');
        });
    }
};
