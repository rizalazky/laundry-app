<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->decimal('remaining_amount', 15, 2);
            $table->enum('status', ['active', 'paid'])->default('active');
            $table->timestamp('issued_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'user_id', 'status']);
        });

        Schema::create('employee_loan_repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('employee_loan_id');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamp('paid_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'employee_loan_id'])
                ->references(['tenant_id', 'id'])
                ->on('employee_loans')
                ->restrictOnDelete();

            $table->index(['tenant_id', 'employee_loan_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_loan_repayments');
        Schema::dropIfExists('employee_loans');
    }
};
