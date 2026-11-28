<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasInvalidEmployeeLoans = DB::table('employee_loans as loans')
            ->leftJoin('users as employees', 'employees.id', '=', 'loans.user_id')
            ->leftJoin('users as creators', 'creators.id', '=', 'loans.created_by')
            ->where(function ($query): void {
                $query
                    ->whereNull('employees.id')
                    ->orWhereColumn('employees.tenant_id', '!=', 'loans.tenant_id')
                    ->orWhereNull('creators.id')
                    ->orWhereColumn('creators.tenant_id', '!=', 'loans.tenant_id');
            })
            ->exists();

        $hasInvalidRepayments = DB::table('employee_loan_repayments as repayments')
            ->leftJoin('users as recorders', 'recorders.id', '=', 'repayments.recorded_by')
            ->where(function ($query): void {
                $query
                    ->whereNull('recorders.id')
                    ->orWhereColumn('recorders.tenant_id', '!=', 'repayments.tenant_id');
            })
            ->exists();

        $hasInvalidPayrolls = DB::table('payrolls as payrolls')
            ->leftJoin('users as employees', 'employees.id', '=', 'payrolls.user_id')
            ->leftJoin('users as creators', 'creators.id', '=', 'payrolls.created_by')
            ->where(function ($query): void {
                $query
                    ->whereNull('employees.id')
                    ->orWhereColumn('employees.tenant_id', '!=', 'payrolls.tenant_id')
                    ->orWhereNull('creators.id')
                    ->orWhereColumn('creators.tenant_id', '!=', 'payrolls.tenant_id');
            })
            ->exists();

        if ($hasInvalidEmployeeLoans || $hasInvalidRepayments || $hasInvalidPayrolls) {
            throw new RuntimeException(
                'Cannot enforce payroll and employee loan tenant integrity: existing records reference users from another tenant.'
            );
        }

        Schema::table('employee_loans', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['created_by']);

            $table->foreign(['user_id', 'tenant_id'], 'employee_loans_employee_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
            $table->foreign(['created_by', 'tenant_id'], 'employee_loans_creator_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
        });

        Schema::table('employee_loan_repayments', function (Blueprint $table): void {
            $table->dropForeign(['recorded_by']);

            $table->foreign(['recorded_by', 'tenant_id'], 'employee_loan_repayments_recorder_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
        });

        Schema::table('payrolls', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['created_by']);

            $table->foreign(['user_id', 'tenant_id'], 'payrolls_employee_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
            $table->foreign(['created_by', 'tenant_id'], 'payrolls_creator_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table): void {
            $table->dropForeign('payrolls_employee_tenant_foreign');
            $table->dropForeign('payrolls_creator_tenant_foreign');

            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('employee_loan_repayments', function (Blueprint $table): void {
            $table->dropForeign('employee_loan_repayments_recorder_tenant_foreign');

            $table->foreign('recorded_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('employee_loans', function (Blueprint $table): void {
            $table->dropForeign('employee_loans_employee_tenant_foreign');
            $table->dropForeign('employee_loans_creator_tenant_foreign');

            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });
    }
};
