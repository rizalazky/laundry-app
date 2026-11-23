<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('workshop_id')
                ->nullable()
                ->after('branch_id');

            $table->index(['workshop_id', 'status']);
        });

        // SQLite rebuilds the table to add a foreign key. That rebuild is
        // incompatible with the existing order-protection triggers.
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('orders', function (Blueprint $table) {
                $table->foreign('workshop_id')
                    ->references('id')
                    ->on('branches')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['workshop_id', 'status']);
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE orders DROP COLUMN workshop_id');

            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['workshop_id']);
            $table->dropColumn('workshop_id');
        });
    }
};
