<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('price', 15, 2)->default(0);
            $table->unsignedInteger('duration_days');
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->json('rules')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('customer_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_tier_id')->constrained()->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('expired_at');
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['tenant_id', 'customer_id', 'status']);
            $table->index(['tenant_id', 'expired_at']);
        });

        Schema::create('promos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('category', 20);
            $table->string('reward_type', 30);
            $table->decimal('reward_value', 15, 2)->default(0);
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('rules')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'category', 'is_active']);
            $table->index(['tenant_id', 'start_at', 'end_at']);
        });

        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('promo_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 60);
            $table->string('voucher_type', 30);
            $table->unsignedInteger('quota')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active', 'expires_at']);
        });

        Schema::create('point_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('type', 20);
            $table->integer('points');
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'customer_id', 'created_at']);
            $table->index(['tenant_id', 'transaction_id']);
        });

        Schema::create('point_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('reward_name');
            $table->unsignedInteger('points_required');
            $table->decimal('stock', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('raffles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('status', 20)->default('draft');
            $table->json('prizes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'starts_at']);
        });

        Schema::create('raffle_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('raffle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('ticket_number', 50);
            $table->timestamp('issued_at');
            $table->boolean('is_winner')->default(false);
            $table->timestamps();

            $table->unique(['raffle_id', 'ticket_number']);
            $table->index(['tenant_id', 'customer_id', 'raffle_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->string('from_location_type', 20)->nullable();
            $table->unsignedBigInteger('from_location_id')->nullable();
            $table->string('to_location_type', 20)->nullable();
            $table->unsignedBigInteger('to_location_id')->nullable();
            $table->string('movement_type', 20);
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'inventory_item_id', 'created_at'], 'stock_movements_tenant_item_created_index');
            $table->index(['tenant_id', 'movement_type', 'created_at'], 'stock_movements_tenant_type_created_index');
        });

        Schema::create('stock_opnames', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('location_type', 20);
            $table->unsignedBigInteger('location_id')->nullable();
            $table->timestamp('opname_date');
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'location_type', 'location_id', 'opname_date'], 'stock_opnames_tenant_location_date_index');
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('stock_opname_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_opname_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->decimal('system_qty', 18, 4);
            $table->decimal('physical_qty', 18, 4);
            $table->decimal('difference_qty', 18, 4);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['stock_opname_id', 'inventory_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opname_items');
        Schema::dropIfExists('stock_opnames');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('raffle_tickets');
        Schema::dropIfExists('raffles');
        Schema::dropIfExists('point_rewards');
        Schema::dropIfExists('point_logs');
        Schema::dropIfExists('vouchers');
        Schema::dropIfExists('promos');
        Schema::dropIfExists('customer_memberships');
        Schema::dropIfExists('member_tiers');
    }
};
