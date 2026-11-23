<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_point_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->string('target_type', 30);
            $table->unsignedBigInteger('member_tier_id')->nullable();
            $table->decimal('amount_step', 15, 2);
            $table->unsignedInteger('points_per_step');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

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
    }

    public function down(): void
    {
        Schema::table('point_logs', function (Blueprint $table) {
            $table->dropForeign('point_logs_rule_foreign');
            $table->dropUnique('point_logs_order_rule_unique');
            $table->dropIndex('point_logs_expiry_index');
            $table->dropColumn(['loyalty_point_rule_id', 'expires_at', 'expired_at']);
        });

        Schema::dropIfExists('loyalty_point_rules');
    }
};
