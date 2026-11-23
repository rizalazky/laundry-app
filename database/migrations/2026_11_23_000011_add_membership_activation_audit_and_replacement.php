<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_memberships', function (Blueprint $table): void {
            $table->foreignId('activated_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable()->after('activated_by');
            $table->foreignId('activated_at_branch_id')->nullable()->after('activated_at')->constrained('branches')->nullOnDelete();
            $table->foreignId('replaced_by_membership_id')->nullable()->after('activated_at_branch_id')
                ->constrained('customer_memberships')->nullOnDelete();
            $table->index(['tenant_id', 'activated_by']);
        });
    }

    public function down(): void
    {
        Schema::table('customer_memberships', function (Blueprint $table): void {
            $table->dropForeign(['replaced_by_membership_id']);
            $table->dropForeign(['activated_at_branch_id']);
            $table->dropForeign(['activated_by']);
            $table->dropIndex(['tenant_id', 'activated_by']);
            $table->dropColumn([
                'replaced_by_membership_id',
                'activated_at_branch_id',
                'activated_at',
                'activated_by',
            ]);
        });
    }
};
