<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', [
                'super_admin',
                'tenant_owner',
                'branch_manager',
                'cashier',
                'operator',
                'courier',
            ])->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', [
                'super_admin',
                'tenant_owner',
                'branch_manager',
                'cashier',
                'operator',
            ])->default('cashier')->change();
        });
    }
};
