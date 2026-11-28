<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $invalidBranch = DB::table('order_adjustments as adjustments')
            ->leftJoin('branches as branches', 'branches.id', '=', 'adjustments.branch_id')
            ->where(function ($query): void {
                $query->whereNull('branches.id')
                    ->orWhereColumn('branches.tenant_id', '!=', 'adjustments.tenant_id');
            })->exists();

        $invalidOrder = DB::table('order_adjustments as adjustments')
            ->leftJoin('orders as orders', 'orders.id', '=', 'adjustments.order_id')
            ->where(function ($query): void {
                $query->whereNull('orders.id')
                    ->orWhereColumn('orders.tenant_id', '!=', 'adjustments.tenant_id')
                    ->orWhereColumn('orders.branch_id', '!=', 'adjustments.branch_id');
            })->exists();

        $invalidActors = DB::table('order_adjustments as adjustments')
            ->leftJoin('users as requesters', 'requesters.id', '=', 'adjustments.requested_by')
            ->leftJoin('users as approvers', 'approvers.id', '=', 'adjustments.approved_by')
            ->leftJoin('users as payers', 'payers.id', '=', 'adjustments.paid_by')
            ->where(function ($query): void {
                $query->whereNull('requesters.id')
                    ->orWhereColumn('requesters.tenant_id', '!=', 'adjustments.tenant_id')
                    ->orWhere(function ($query): void {
                        $query->whereNotNull('adjustments.approved_by')
                            ->where(function ($query): void {
                                $query->whereNull('approvers.id')
                                    ->orWhereColumn('approvers.tenant_id', '!=', 'adjustments.tenant_id');
                            });
                    })
                    ->orWhere(function ($query): void {
                        $query->whereNotNull('adjustments.paid_by')
                            ->where(function ($query): void {
                                $query->whereNull('payers.id')
                                    ->orWhereColumn('payers.tenant_id', '!=', 'adjustments.tenant_id');
                            });
                    });
            })->exists();

        $invalidPayment = DB::table('order_adjustments as adjustments')
            ->leftJoin('payments as payments', 'payments.id', '=', 'adjustments.payment_id')
            ->whereNotNull('adjustments.payment_id')
            ->where(function ($query): void {
                $query->whereNull('payments.id')
                    ->orWhereColumn('payments.tenant_id', '!=', 'adjustments.tenant_id');
            })->exists();

        $invalidExpense = DB::table('order_adjustments as adjustments')
            ->leftJoin('expenses as expenses', 'expenses.id', '=', 'adjustments.expense_id')
            ->whereNotNull('adjustments.expense_id')
            ->where(function ($query): void {
                $query->whereNull('expenses.id')
                    ->orWhereColumn('expenses.tenant_id', '!=', 'adjustments.tenant_id');
            })->exists();

        if ($invalidBranch || $invalidOrder || $invalidActors || $invalidPayment || $invalidExpense) {
            throw new RuntimeException(
                'Cannot enforce order adjustment tenant integrity: existing adjustments contain cross-tenant references or a branch/order mismatch.'
            );
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->unique(['id', 'branch_id', 'tenant_id'], 'orders_id_branch_tenant_unique');
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->unique(['id', 'tenant_id'], 'payments_id_tenant_unique');
        });
        Schema::table('expenses', function (Blueprint $table): void {
            $table->unique(['id', 'tenant_id'], 'expenses_id_tenant_unique');
        });

        Schema::table('order_adjustments', function (Blueprint $table): void {
            $table->dropForeign(['branch_id']);
            $table->dropForeign(['order_id']);
            $table->dropForeign(['requested_by']);
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['paid_by']);
            $table->dropForeign(['payment_id']);
            $table->dropForeign(['expense_id']);

            $table->foreign(['branch_id', 'tenant_id'], 'order_adjustments_branch_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['order_id', 'branch_id', 'tenant_id'], 'order_adjustments_order_branch_tenant_foreign')
                ->references(['id', 'branch_id', 'tenant_id'])->on('orders')->restrictOnDelete();
            $table->foreign(['requested_by', 'tenant_id'], 'order_adjustments_requester_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
            $table->foreign(['approved_by', 'tenant_id'], 'order_adjustments_approver_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
            $table->foreign(['paid_by', 'tenant_id'], 'order_adjustments_payer_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
            $table->foreign(['payment_id', 'tenant_id'], 'order_adjustments_payment_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('payments')->restrictOnDelete();
            $table->foreign(['expense_id', 'tenant_id'], 'order_adjustments_expense_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('expenses')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_adjustments', function (Blueprint $table): void {
            $table->dropForeign('order_adjustments_branch_tenant_foreign');
            $table->dropForeign('order_adjustments_order_branch_tenant_foreign');
            $table->dropForeign('order_adjustments_requester_tenant_foreign');
            $table->dropForeign('order_adjustments_approver_tenant_foreign');
            $table->dropForeign('order_adjustments_payer_tenant_foreign');
            $table->dropForeign('order_adjustments_payment_tenant_foreign');
            $table->dropForeign('order_adjustments_expense_tenant_foreign');

            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('paid_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('payment_id')->references('id')->on('payments')->restrictOnDelete();
            $table->foreign('expense_id')->references('id')->on('expenses')->restrictOnDelete();
        });

        Schema::table('expenses', fn (Blueprint $table) => $table->dropUnique('expenses_id_tenant_unique'));
        Schema::table('payments', fn (Blueprint $table) => $table->dropUnique('payments_id_tenant_unique'));
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique('orders_id_branch_tenant_unique');
        });
    }
};
