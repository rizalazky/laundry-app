<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // users(id, tenant_id) is already unique from the production/commission
        // integrity migration. Reuse that key; do not create another index.
        $actorReferences = [
            ['cash_settlements', 'created_by', true],
            ['expenses', 'created_by', true],
            ['capital_movements', 'created_by', true],
            ['journals', 'created_by', true],
            ['bank_transfers', 'created_by', true],
            ['tenant_reset_logs', 'executed_by', false],
        ];

        foreach ($actorReferences as [$table, $column, $nullable]) {
            $invalid = DB::table($table.' as records')
                ->leftJoin('users as actors', 'actors.id', '=', 'records.'.$column)
                ->when($nullable, fn ($query) => $query->whereNotNull('records.'.$column))
                ->where(function ($query): void {
                    $query->whereNull('actors.id')
                        ->orWhereColumn('actors.tenant_id', '!=', 'records.tenant_id');
                })
                ->exists();

            if ($invalid) {
                throw new RuntimeException(
                    "Cannot enforce financial actor tenant integrity: {$table}.{$column} contains a missing or cross-tenant user reference."
                );
            }
        }

        foreach ($actorReferences as [$table, $column]) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $column): void {
                $blueprint->dropForeign([$column]);
                $blueprint->foreign([$column, 'tenant_id'], $table.'_'.($column === 'executed_by' ? 'executor' : 'creator').'_tenant_foreign')
                    ->references(['id', 'tenant_id'])
                    ->on('users')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        $actorReferences = [
            ['cash_settlements', 'created_by', true],
            ['expenses', 'created_by', true],
            ['capital_movements', 'created_by', true],
            ['journals', 'created_by', true],
            ['bank_transfers', 'created_by', true],
            ['tenant_reset_logs', 'executed_by', false],
        ];

        foreach ($actorReferences as [$table, $column, $nullable]) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $column, $nullable): void {
                $name = $table.'_'.($column === 'executed_by' ? 'executor' : 'creator').'_tenant_foreign';
                $blueprint->dropForeign($name);

                $foreign = $blueprint->foreign($column)->references('id')->on('users');
                if ($nullable) {
                    $foreign->nullOnDelete();
                } else {
                    $foreign->restrictOnDelete();
                }
            });
        }
    }
};
