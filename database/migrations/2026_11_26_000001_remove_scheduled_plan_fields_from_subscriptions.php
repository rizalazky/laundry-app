<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropForeign(['scheduled_plan_id']);
            $table->dropColumn(['scheduled_plan_id', 'scheduled_change_at']);
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->foreignId('scheduled_plan_id')->nullable()->after('cancel_at_period_end')->constrained('plans')->nullOnDelete();
            $table->timestamp('scheduled_change_at')->nullable()->after('scheduled_plan_id');
        });
    }
};
