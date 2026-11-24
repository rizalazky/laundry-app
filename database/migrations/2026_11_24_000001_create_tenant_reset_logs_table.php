<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_reset_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('executed_by')->constrained('users')->restrictOnDelete();
            $table->string('reset_scope', 32);
            $table->unsignedInteger('records_purged_count')->default(0);
            $table->timestamp('executed_at');
            $table->timestamps();

            $table->index(['tenant_id', 'executed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_reset_logs');
    }
};
