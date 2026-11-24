<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subscription_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('invoice_number', 60)->unique();
            $table->string('type', 20);
            $table->string('plan_code_snapshot', 50);
            $table->string('plan_name_snapshot');
            $table->string('billing_interval', 20);
            $table->decimal('unit_price_snapshot', 15, 2);
            $table->decimal('subtotal', 15, 2);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2);
            $table->char('currency', 3)->default('IDR');
            $table->string('status', 20)->default('issued');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['subscription_id', 'type', 'period_start', 'period_end']);
        });

        Schema::create('subscription_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_invoice_id')->constrained('subscription_invoices')->cascadeOnDelete();
            $table->string('provider', 50);
            $table->string('provider_reference', 150)->nullable()->unique();
            $table->string('payment_method', 50)->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('status', 20)->default('pending');
            $table->text('payment_url')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'subscription_invoice_id', 'created_at']);
            $table->index(['provider', 'status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('subscription_invoices');
    }
};
