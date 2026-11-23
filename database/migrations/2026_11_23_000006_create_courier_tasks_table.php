<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('task_type', 20);
            $table->text('address');
            $table->string('status', 20)->default('assigned');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'courier_id', 'status'], 'courier_tasks_tenant_courier_status_idx');
            $table->index(['tenant_id', 'order_id', 'task_type'], 'courier_tasks_tenant_order_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_tasks');
    }
};
