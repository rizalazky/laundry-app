<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('workshop_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('branches')
                ->nullOnDelete();

            $table->index(['workshop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['workshop_id', 'status']);
            $table->dropConstrainedForeignId('workshop_id');
        });
    }
};
