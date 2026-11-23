<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payrolls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('period_month', 7);
            $table->decimal('base_salary', 15, 2);
            $table->decimal('commission_total', 15, 2);
            $table->decimal('loan_deduction', 15, 2)->default(0);
            $table->decimal('net_salary', 15, 2);
            $table->string('status', 20)->default('draft');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'period_month'], 'payroll_employee_period_unique');
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'period_month', 'status']);
        });

        Schema::table('employee_loan_repayments', function (Blueprint $table): void {
            $table->foreignId('payroll_id')
                ->nullable()
                ->after('employee_loan_id')
                ->constrained('payrolls')
                ->restrictOnDelete();

            $table->index(['tenant_id', 'payroll_id']);
        });
    }

    public function down(): void
    {
        Schema::table('employee_loan_repayments', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'payroll_id']);
            $table->dropConstrainedForeignId('payroll_id');
        });

        Schema::dropIfExists('payrolls');
    }
};
