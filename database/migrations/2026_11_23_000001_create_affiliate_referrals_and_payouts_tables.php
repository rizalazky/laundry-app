<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('referred_tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->decimal('commission_rate', 5, 2)->default(0);
            $table->string('status', 20)->default('pending');
            $table->timestamp('referred_at');
            $table->timestamps();

            $table->index(['referrer_tenant_id', 'status', 'referred_at']);
            $table->unique(['id', 'referrer_tenant_id'], 'affiliate_referrals_id_referrer_unique');
        });

        Schema::create('affiliate_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('affiliate_referral_id');
            $table->decimal('amount', 15, 2);
            $table->date('payout_date');
            $table->string('status', 20)->default('pending');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'payout_date']);
            $table->foreign(['affiliate_referral_id', 'tenant_id'], 'affiliate_payouts_referral_tenant_foreign')
                ->references(['id', 'referrer_tenant_id'])->on('affiliate_referrals')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_payouts');
        Schema::dropIfExists('affiliate_referrals');
    }
};
