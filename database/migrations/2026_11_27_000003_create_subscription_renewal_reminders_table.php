<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subscription_renewal_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->timestamp('period_end');
            $table->unsignedTinyInteger('days_before_expiry');
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(
                ['subscription_id', 'period_end', 'days_before_expiry'],
                'sub_renewal_reminders_unique'
            );
            $table->index(['tenant_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_renewal_reminders');
    }
};
