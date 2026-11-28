<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $invalidAttendance = DB::table('attendances as records')
            ->leftJoin('branches', 'branches.id', '=', 'records.branch_id')
            ->leftJoin('users', 'users.id', '=', 'records.user_id')
            ->where(function ($query): void {
                $query->whereNull('branches.id')
                    ->orWhereColumn('branches.tenant_id', '!=', 'records.tenant_id')
                    ->orWhereNull('users.id')
                    ->orWhereColumn('users.tenant_id', '!=', 'records.tenant_id');
            })->exists();

        $invalidActivityLogs = DB::table('activity_logs as records')
            ->leftJoin('users', 'users.id', '=', 'records.user_id')
            ->where(function ($query): void {
                $query->whereNull('users.id')
                    ->orWhereColumn('users.tenant_id', '!=', 'records.tenant_id');
            })->exists();

        $invalidCourierTasks = DB::table('courier_tasks as records')
            ->leftJoin('orders', 'orders.id', '=', 'records.order_id')
            ->leftJoin('users as couriers', 'couriers.id', '=', 'records.courier_id')
            ->leftJoin('users as creators', 'creators.id', '=', 'records.created_by')
            ->where(function ($query): void {
                $query->whereNull('orders.id')
                    ->orWhereColumn('orders.tenant_id', '!=', 'records.tenant_id')
                    ->orWhereNull('couriers.id')
                    ->orWhereColumn('couriers.tenant_id', '!=', 'records.tenant_id')
                    ->orWhere(function ($query): void {
                        $query->whereNotNull('records.created_by')
                            ->where(function ($query): void {
                                $query->whereNull('creators.id')
                                    ->orWhereColumn('creators.tenant_id', '!=', 'records.tenant_id');
                            });
                    });
            })->exists();

        $invalidSchedules = DB::table('mass_invoice_schedules as records')
            ->leftJoin('users as creators', 'creators.id', '=', 'records.created_by')
            ->whereNotNull('records.created_by')
            ->where(function ($query): void {
                $query->whereNull('creators.id')
                    ->orWhereColumn('creators.tenant_id', '!=', 'records.tenant_id');
            })->exists();

        $invalidScheduleCustomers = DB::table('mass_invoice_schedule_customer as records')
            ->leftJoin('mass_invoice_schedules as schedules', 'schedules.id', '=', 'records.mass_invoice_schedule_id')
            ->leftJoin('customers', 'customers.id', '=', 'records.customer_id')
            ->where(function ($query): void {
                $query->whereNull('schedules.id')
                    ->orWhereColumn('schedules.tenant_id', '!=', 'records.tenant_id')
                    ->orWhereNull('customers.id')
                    ->orWhereColumn('customers.tenant_id', '!=', 'records.tenant_id');
            })->exists();

        $invalidMassInvoices = DB::table('mass_invoices as records')
            ->leftJoin('mass_invoice_schedules as schedules', 'schedules.id', '=', 'records.mass_invoice_schedule_id')
            ->leftJoin('customers', 'customers.id', '=', 'records.customer_id')
            ->leftJoin('users as actors', 'actors.id', '=', 'records.triggered_by')
            ->where(function ($query): void {
                $query->whereNull('schedules.id')
                    ->orWhereColumn('schedules.tenant_id', '!=', 'records.tenant_id')
                    ->orWhereNull('customers.id')
                    ->orWhereColumn('customers.tenant_id', '!=', 'records.tenant_id')
                    ->orWhere(function ($query): void {
                        $query->whereNotNull('records.triggered_by')
                            ->where(function ($query): void {
                                $query->whereNull('actors.id')
                                    ->orWhereColumn('actors.tenant_id', '!=', 'records.tenant_id');
                            });
                    });
            })->exists();

        $invalidMassInvoiceItems = DB::table('mass_invoice_items as records')
            ->leftJoin('mass_invoices as invoices', 'invoices.id', '=', 'records.mass_invoice_id')
            ->leftJoin('orders', 'orders.id', '=', 'records.order_id')
            ->where(function ($query): void {
                $query->whereNull('invoices.id')
                    ->orWhereColumn('invoices.tenant_id', '!=', 'records.tenant_id')
                    ->orWhereNull('orders.id')
                    ->orWhereColumn('orders.tenant_id', '!=', 'records.tenant_id');
            })->exists();

        if (
            $invalidAttendance
            || $invalidActivityLogs
            || $invalidCourierTasks
            || $invalidSchedules
            || $invalidScheduleCustomers
            || $invalidMassInvoices
            || $invalidMassInvoiceItems
        ) {
            throw new RuntimeException(
                'Cannot enforce operational tenant integrity: existing records contain missing or cross-tenant references.'
            );
        }

        // These two composite keys are not created by earlier migrations.
        // Existing keys for users, branches, customers, and orders are reused.
        Schema::table('mass_invoice_schedules', function (Blueprint $table): void {
            $table->unique(['id', 'tenant_id'], 'mass_invoice_schedules_id_tenant_unique');
        });

        Schema::table('mass_invoices', function (Blueprint $table): void {
            $table->unique(['id', 'tenant_id'], 'mass_invoices_id_tenant_unique');
        });

        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropForeign(['branch_id']);
            $table->dropForeign(['user_id']);

            $table->foreign(['branch_id', 'tenant_id'], 'attendances_branch_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['user_id', 'tenant_id'], 'attendances_user_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
        });

        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);

            $table->foreign(['user_id', 'tenant_id'], 'activity_logs_user_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
        });

        Schema::table('courier_tasks', function (Blueprint $table): void {
            $table->dropForeign(['order_id']);
            $table->dropForeign(['courier_id']);
            $table->dropForeign(['created_by']);

            $table->foreign(['order_id', 'tenant_id'], 'courier_tasks_order_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('orders')->cascadeOnDelete();
            $table->foreign(['courier_id', 'tenant_id'], 'courier_tasks_courier_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
            $table->foreign(['created_by', 'tenant_id'], 'courier_tasks_creator_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
        });

        Schema::table('mass_invoice_schedules', function (Blueprint $table): void {
            $table->dropForeign(['created_by']);

            $table->foreign(['created_by', 'tenant_id'], 'mass_invoice_schedules_creator_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
        });

        Schema::table('mass_invoice_schedule_customer', function (Blueprint $table): void {
            $table->dropForeign(['mass_invoice_schedule_id']);
            $table->dropForeign(['customer_id']);

            $table->foreign(['mass_invoice_schedule_id', 'tenant_id'], 'mass_sched_customer_schedule_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('mass_invoice_schedules')->cascadeOnDelete();
            $table->foreign(['customer_id', 'tenant_id'], 'mass_sched_customer_customer_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('customers')->cascadeOnDelete();
        });

        Schema::table('mass_invoices', function (Blueprint $table): void {
            $table->dropForeign(['mass_invoice_schedule_id']);
            $table->dropForeign(['customer_id']);
            $table->dropForeign(['triggered_by']);

            $table->foreign(['mass_invoice_schedule_id', 'tenant_id'], 'mass_invoices_schedule_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('mass_invoice_schedules')->restrictOnDelete();
            $table->foreign(['customer_id', 'tenant_id'], 'mass_invoices_customer_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('customers')->restrictOnDelete();
            $table->foreign(['triggered_by', 'tenant_id'], 'mass_invoices_actor_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
        });

        Schema::table('mass_invoice_items', function (Blueprint $table): void {
            $table->dropForeign(['mass_invoice_id']);
            $table->dropForeign(['order_id']);

            $table->foreign(['mass_invoice_id', 'tenant_id'], 'mass_invoice_items_invoice_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('mass_invoices')->cascadeOnDelete();
            $table->foreign(['order_id', 'tenant_id'], 'mass_invoice_items_order_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('orders')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mass_invoice_items', function (Blueprint $table): void {
            $table->dropForeign('mass_invoice_items_invoice_tenant_foreign');
            $table->dropForeign('mass_invoice_items_order_tenant_foreign');

            $table->foreign('mass_invoice_id')->references('id')->on('mass_invoices')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();
        });

        Schema::table('mass_invoices', function (Blueprint $table): void {
            $table->dropForeign('mass_invoices_schedule_tenant_foreign');
            $table->dropForeign('mass_invoices_customer_tenant_foreign');
            $table->dropForeign('mass_invoices_actor_tenant_foreign');

            $table->foreign('mass_invoice_schedule_id')->references('id')->on('mass_invoice_schedules')->restrictOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
            $table->foreign('triggered_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('mass_invoice_schedule_customer', function (Blueprint $table): void {
            $table->dropForeign('mass_sched_customer_schedule_tenant_foreign');
            $table->dropForeign('mass_sched_customer_customer_tenant_foreign');

            $table->foreign('mass_invoice_schedule_id')->references('id')->on('mass_invoice_schedules')->cascadeOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
        });

        Schema::table('mass_invoice_schedules', function (Blueprint $table): void {
            $table->dropForeign('mass_invoice_schedules_creator_tenant_foreign');

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('courier_tasks', function (Blueprint $table): void {
            $table->dropForeign('courier_tasks_order_tenant_foreign');
            $table->dropForeign('courier_tasks_courier_tenant_foreign');
            $table->dropForeign('courier_tasks_creator_tenant_foreign');

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('courier_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->dropForeign('activity_logs_user_tenant_foreign');

            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropForeign('attendances_branch_tenant_foreign');
            $table->dropForeign('attendances_user_tenant_foreign');

            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('mass_invoices', function (Blueprint $table): void {
            $table->dropUnique('mass_invoices_id_tenant_unique');
        });

        Schema::table('mass_invoice_schedules', function (Blueprint $table): void {
            $table->dropUnique('mass_invoice_schedules_id_tenant_unique');
        });
    }
};
