<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER orders_prevent_financial_snapshot_update
                BEFORE UPDATE ON orders
                WHEN OLD.invoice_no IS NOT NEW.invoice_no
                  OR OLD.subtotal IS NOT NEW.subtotal
                  OR OLD.discount IS NOT NEW.discount
                  OR OLD.total IS NOT NEW.total
                BEGIN
                    SELECT RAISE(ABORT, 'Order financial snapshots cannot be modified.');
                END;
            SQL);

            // SQLite REPLACE may silently delete a conflicting row without firing
            // DELETE triggers when recursive_triggers is disabled. Reject conflicts
            // on the primary key and the tenant-scoped invoice number before insert.
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER orders_prevent_replace
                BEFORE INSERT ON orders
                WHEN EXISTS (
                    SELECT 1 FROM orders WHERE id = NEW.id
                ) OR EXISTS (
                    SELECT 1 FROM orders
                    WHERE tenant_id = NEW.tenant_id
                      AND invoice_no = NEW.invoice_no
                )
                BEGIN
                    SELECT RAISE(ABORT, 'Order financial snapshots cannot be replaced.');
                END;
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER branches_prevent_delete_with_orders
                BEFORE DELETE ON branches
                WHEN EXISTS (SELECT 1 FROM orders WHERE branch_id = OLD.id)
                BEGIN
                    SELECT RAISE(ABORT, 'Branches with historical orders cannot be deleted.');
                END;
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER tenants_prevent_delete_with_orders
                BEFORE DELETE ON tenants
                WHEN EXISTS (SELECT 1 FROM orders WHERE tenant_id = OLD.id)
                BEGIN
                    SELECT RAISE(ABORT, 'Tenants with historical orders cannot be deleted.');
                END;
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER orders_prevent_delete
                BEFORE DELETE ON orders
                BEGIN
                    SELECT RAISE(ABORT, 'Orders with historical snapshots cannot be deleted.');
                END;
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER order_items_prevent_update
                BEFORE UPDATE ON order_items
                BEGIN
                    SELECT RAISE(ABORT, 'Order items cannot be modified.');
                END;
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER order_items_prevent_delete
                BEFORE DELETE ON order_items
                BEGIN
                    SELECT RAISE(ABORT, 'Order items cannot be deleted.');
                END;
            SQL);

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER orders_prevent_financial_snapshot_update
                BEFORE UPDATE ON orders
                FOR EACH ROW
                BEGIN
                    IF NOT (OLD.invoice_no <=> NEW.invoice_no)
                        OR NOT (OLD.subtotal <=> NEW.subtotal)
                        OR NOT (OLD.discount <=> NEW.discount)
                        OR NOT (OLD.total <=> NEW.total)
                    THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Order financial snapshots cannot be modified.';
                    END IF;
                END
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER branches_prevent_delete_with_orders
                BEFORE DELETE ON branches
                FOR EACH ROW
                BEGIN
                    IF EXISTS (SELECT 1 FROM orders WHERE branch_id = OLD.id) THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Branches with historical orders cannot be deleted.';
                    END IF;
                END
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER tenants_prevent_delete_with_orders
                BEFORE DELETE ON tenants
                FOR EACH ROW
                BEGIN
                    IF EXISTS (SELECT 1 FROM orders WHERE tenant_id = OLD.id) THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Tenants with historical orders cannot be deleted.';
                    END IF;
                END
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER orders_prevent_delete
                BEFORE DELETE ON orders
                FOR EACH ROW
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Orders with historical snapshots cannot be deleted.'
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER order_items_prevent_update
                BEFORE UPDATE ON order_items
                FOR EACH ROW
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Order items cannot be modified.'
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER order_items_prevent_delete
                BEFORE DELETE ON order_items
                FOR EACH ROW
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Order items cannot be deleted.'
            SQL);

            return;
        }

        throw new RuntimeException("Order snapshot guards are not implemented for database driver [{$driver}].");
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite' || $driver === 'mysql' || $driver === 'mariadb') {
            if ($driver === 'sqlite') {
                DB::unprepared('DROP TRIGGER IF EXISTS orders_prevent_replace');
            }
            DB::unprepared('DROP TRIGGER IF EXISTS branches_prevent_delete_with_orders');
            DB::unprepared('DROP TRIGGER IF EXISTS tenants_prevent_delete_with_orders');
            DB::unprepared('DROP TRIGGER IF EXISTS orders_prevent_financial_snapshot_update');
            DB::unprepared('DROP TRIGGER IF EXISTS orders_prevent_delete');
            DB::unprepared('DROP TRIGGER IF EXISTS order_items_prevent_update');
            DB::unprepared('DROP TRIGGER IF EXISTS order_items_prevent_delete');
        }
    }
};
