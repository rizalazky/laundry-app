<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) { $table->unique(['id', 'tenant_id'], 'customers_id_tenant_unique'); });
        Schema::table('orders', function (Blueprint $table) { $table->unique(['id', 'tenant_id'], 'orders_id_tenant_unique'); });

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
            $table->unique(['id', 'tenant_id'], 'member_tiers_id_tenant_unique');
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('customer_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('member_tier_id');
            $table->timestamp('started_at');
            $table->timestamp('expired_at');
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['tenant_id', 'customer_id', 'status']);
            $table->index(['tenant_id', 'expired_at']);
            $table->foreign(['customer_id', 'tenant_id'], 'customer_memberships_customer_tenant_foreign')->references(['id', 'tenant_id'])->on('customers')->cascadeOnDelete();
            $table->foreign(['member_tier_id', 'tenant_id'], 'customer_memberships_tier_tenant_foreign')->references(['id', 'tenant_id'])->on('member_tiers')->restrictOnDelete();
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
            $table->unique(['id', 'tenant_id'], 'promos_id_tenant_unique');
        });

        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('promo_id')->nullable();
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
            $table->foreign(['promo_id', 'tenant_id'], 'vouchers_promo_tenant_foreign')->references(['id', 'tenant_id'])->on('promos')->restrictOnDelete();
        });

        Schema::create('point_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->string('type', 20);
            $table->integer('points');
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'customer_id', 'created_at']);
            $table->index(['tenant_id', 'transaction_id']);
            $table->foreign(['customer_id', 'tenant_id'], 'point_logs_customer_tenant_foreign')->references(['id', 'tenant_id'])->on('customers')->cascadeOnDelete();
            $table->foreign(['transaction_id', 'tenant_id'], 'point_logs_order_tenant_foreign')->references(['id', 'tenant_id'])->on('orders')->restrictOnDelete();
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
            $table->unique(['id', 'tenant_id'], 'raffles_id_tenant_unique');
        });

        Schema::create('raffle_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('raffle_id');
            $table->unsignedBigInteger('customer_id');
            $table->string('ticket_number', 50);
            $table->timestamp('issued_at');
            $table->boolean('is_winner')->default(false);
            $table->timestamps();

            $table->unique(['raffle_id', 'ticket_number']);
            $table->index(['tenant_id', 'customer_id', 'raffle_id']);
            $table->foreign(['raffle_id', 'tenant_id'], 'raffle_tickets_raffle_tenant_foreign')->references(['id', 'tenant_id'])->on('raffles')->cascadeOnDelete();
            $table->foreign(['customer_id', 'tenant_id'], 'raffle_tickets_customer_tenant_foreign')->references(['id', 'tenant_id'])->on('customers')->cascadeOnDelete();
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('inventory_item_id');
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
            $table->foreign(['inventory_item_id', 'tenant_id'], 'stock_movements_item_tenant_foreign')->references(['id', 'tenant_id'])->on('inventory_items')->restrictOnDelete();
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
            $table->unique(['id', 'tenant_id'], 'stock_opnames_id_tenant_unique');
        });

        Schema::create('stock_opname_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('stock_opname_id');
            $table->unsignedBigInteger('inventory_item_id');
            $table->decimal('system_qty', 18, 4);
            $table->decimal('physical_qty', 18, 4);
            $table->decimal('difference_qty', 18, 4);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['stock_opname_id', 'inventory_item_id']);
            $table->foreign(['stock_opname_id', 'tenant_id'], 'stock_opname_items_opname_tenant_foreign')->references(['id', 'tenant_id'])->on('stock_opnames')->cascadeOnDelete();
            $table->foreign(['inventory_item_id', 'tenant_id'], 'stock_opname_items_item_tenant_foreign')->references(['id', 'tenant_id'])->on('inventory_items')->restrictOnDelete();
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

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_id_tenant_unique');
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('customers_id_tenant_unique');
        });
    }
};
