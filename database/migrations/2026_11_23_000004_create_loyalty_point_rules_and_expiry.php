<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->unique(['id', 'tenant_id'], 'branches_id_tenant_unique');
        });

        Schema::create('loyalty_point_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->string('name');
            $table->string('target_type', 30);
            $table->unsignedBigInteger('member_tier_id')->nullable();
            $table->decimal('amount_step', 15, 2);
            $table->unsignedInteger('points_per_step');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['id', 'tenant_id'], 'loyalty_point_rules_id_tenant_unique');
            $table->index(['tenant_id', 'branch_id', 'is_active', 'starts_at', 'ends_at'], 'loyalty_rules_branch_period_index');
            $table->index(['tenant_id', 'target_type', 'member_tier_id'], 'loyalty_rules_target_index');
            $table->foreign(['branch_id', 'tenant_id'], 'loyalty_rules_branch_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('branches')->cascadeOnDelete();
            $table->foreign(['member_tier_id', 'tenant_id'], 'loyalty_rules_tier_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('member_tiers')->restrictOnDelete();
        });

        Schema::table('point_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('loyalty_point_rule_id')->nullable()->after('transaction_id');
            $table->timestamp('expires_at')->nullable()->after('description');
            $table->timestamp('expired_at')->nullable()->after('expires_at');
            $table->index(['type', 'expires_at', 'expired_at'], 'point_logs_expiry_index');
            $table->unique(['transaction_id', 'loyalty_point_rule_id'], 'point_logs_order_rule_unique');
            $table->foreign('loyalty_point_rule_id', 'point_logs_rule_foreign')
                ->references('id')->on('loyalty_point_rules')->nullOnDelete();
        });

        Schema::create('loyalty_point_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('loyalty_point_rule_id');
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedInteger('points_awarded');
            $table->unsignedInteger('points_remaining');
            $table->timestamp('expires_at');
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();

            $table->unique(['transaction_id', 'loyalty_point_rule_id'], 'loyalty_point_lots_order_rule_unique');
            $table->index(['tenant_id', 'customer_id', 'expires_at', 'points_remaining'], 'loyalty_point_lots_expiry_index');
            $table->foreign(['customer_id', 'tenant_id'], 'loyalty_point_lots_customer_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('customers')->cascadeOnDelete();
            $table->foreign(['loyalty_point_rule_id', 'tenant_id'], 'loyalty_point_lots_rule_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('loyalty_point_rules')->restrictOnDelete();
            $table->foreign(['transaction_id', 'tenant_id'], 'loyalty_point_lots_order_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('orders')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_point_lots');

        Schema::table('point_logs', function (Blueprint $table) {
            $table->dropForeign('point_logs_rule_foreign');
            $table->dropUnique('point_logs_order_rule_unique');
            $table->dropIndex('point_logs_expiry_index');
            $table->dropColumn(['loyalty_point_rule_id', 'expires_at', 'expired_at']);
        });

        Schema::dropIfExists('loyalty_point_rules');

        Schema::table('branches', function (Blueprint $table) {
            $table->dropUnique('branches_id_tenant_unique');
        });
    }
};
