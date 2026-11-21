<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('stage', 20);
            $table->foreignId('worker_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('machine_code', 50)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

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
    }
};
