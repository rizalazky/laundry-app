<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unique(['id', 'tenant_id'], 'orders_id_tenant_unique');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique(['id', 'tenant_id'], 'users_id_tenant_unique');
        });

        Schema::create('production_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('order_id');
            $table->string('stage', 20);
            $table->unsignedBigInteger('worker_id')->nullable();
            $table->string('machine_code', 50)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['order_id', 'tenant_id'], 'production_logs_order_tenant_foreign')
                ->references(['id', 'tenant_id'])
                ->on('orders')
                ->cascadeOnDelete();
            $table->foreign(['worker_id', 'tenant_id'], 'production_logs_worker_tenant_foreign')
                ->references(['id', 'tenant_id'])
                ->on('users')
                ->restrictOnDelete();

            $table->index(
                ['tenant_id', 'order_id', 'started_at'],
                'production_logs_tenant_order_started_idx',
            );
            $table->index(
                ['tenant_id', 'stage', 'started_at'],
                'production_logs_tenant_stage_started_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_logs');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_id_tenant_unique');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_id_tenant_unique');
        });
    }
};
