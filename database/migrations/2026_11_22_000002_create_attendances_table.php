<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('clock_in');
            $table->timestamp('clock_out')->nullable();
            $table->string('status', 20)->default('present');
            $table->timestamps();

            $table->index(['tenant_id', 'branch_id', 'clock_in']);
            $table->index(['tenant_id', 'user_id', 'clock_in']);
            $table->index(['tenant_id', 'user_id', 'clock_out']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
