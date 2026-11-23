<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->string('commission_method', 20)->default('fixed')->after('estimated_hours');
            $table->decimal('commission_value', 12, 2)->default(0)->after('commission_method');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->decimal('commission_snapshot', 12, 2)->default(0)->after('subtotal');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('commission_snapshot');
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn(['commission_method', 'commission_value']);
        });
    }
};
