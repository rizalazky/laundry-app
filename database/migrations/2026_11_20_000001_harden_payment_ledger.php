<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;
use RuntimeException;

return new class extends Migration
{
    public function up(): void
    {
        // A ledger row must outlive the records and users that reference it.
        // Soft-deleting tenants/branches remains unaffected.
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropForeign(['branch_id']);
            $table->dropForeign(['order_id']);
            $table->dropForeign(['received_by']);

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('received_by')->references('id')->on('users')->restrictOnDelete();
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER payments_prevent_update
                BEFORE UPDATE ON payments
                BEGIN
                    SELECT RAISE(ABORT, 'Payment ledger entries cannot be modified.');
                END;
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER payments_prevent_delete
                BEFORE DELETE ON payments
                BEGIN
                    SELECT RAISE(ABORT, 'Payment ledger entries cannot be deleted.');
                END;
            SQL);

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER payments_prevent_update
                BEFORE UPDATE ON payments
                FOR EACH ROW
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Payment ledger entries cannot be modified.'
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER payments_prevent_delete
                BEFORE DELETE ON payments
                FOR EACH ROW
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Payment ledger entries cannot be deleted.'
            SQL);

            return;
        }

        throw new RuntimeException("Payment ledger guards are not implemented for database driver [{$driver}].");
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite' || $driver === 'mysql' || $driver === 'mariadb') {
            DB::unprepared('DROP TRIGGER IF EXISTS payments_prevent_update');
            DB::unprepared('DROP TRIGGER IF EXISTS payments_prevent_delete');
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropForeign(['branch_id']);
            $table->dropForeign(['order_id']);
            $table->dropForeign(['received_by']);

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('received_by')->references('id')->on('users')->nullOnDelete();
        });
    }
};
