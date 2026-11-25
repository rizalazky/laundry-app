<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 50);
            $table->char('provider_event_id', 64);
            $table->string('provider_reference', 150)->nullable();
            $table->string('event_type', 100)->nullable();
            $table->json('payload');
            $table->string('status', 24)->default('received');
            $table->string('processing_result', 64)->nullable();
            $table->unsignedSmallInteger('processing_attempts')->default(0);
            $table->timestamp('signature_verified_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_event_id'], 'payment_webhook_provider_event_unique');
            $table->index(['provider', 'provider_reference', 'status'], 'payment_webhook_reference_status_idx');
            $table->index(['status', 'processing_started_at'], 'payment_webhook_processing_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};
