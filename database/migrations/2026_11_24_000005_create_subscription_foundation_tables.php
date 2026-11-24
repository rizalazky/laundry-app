<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('monthly_price', 15, 2)->default(0);
            $table->decimal('yearly_price', 15, 2)->nullable();
            $table->char('currency', 3)->default('IDR');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('plan_features', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('feature_code', 100);
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'feature_code']);
        });

        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('status', 32)->default('trialing');
            $table->timestamp('trial_starts_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->foreignId('scheduled_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->timestamp('scheduled_change_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'trial_ends_at']);
            $table->index(['status', 'current_period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('plan_features');
        Schema::dropIfExists('plans');
    }
};
