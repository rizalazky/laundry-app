<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mass_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mass_invoice_schedule_id')->constrained('mass_invoice_schedules')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('invoice_no', 50);
            $table->date('billing_period_start');
            $table->date('billing_period_end');
            $table->decimal('total_amount', 15, 2);
            $table->string('status', 20)->default('issued');
            $table->string('trigger_type', 20);
            $table->timestamp('generated_at');
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'invoice_no']);
            $table->index(['tenant_id', 'customer_id', 'generated_at']);
        });

        Schema::create('mass_invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mass_invoice_id')->constrained('mass_invoices')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->decimal('balance_snapshot', 15, 2);
            $table->timestamps();

            $table->unique('order_id');
            $table->index(['tenant_id', 'mass_invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mass_invoice_items');
        Schema::dropIfExists('mass_invoices');
    }
};
