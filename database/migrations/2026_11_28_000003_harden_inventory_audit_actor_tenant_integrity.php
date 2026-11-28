<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasInvalidMovementActors = DB::table('stock_movements as movements')
            ->leftJoin('users as actors', 'actors.id', '=', 'movements.created_by')
            ->whereNotNull('movements.created_by')
            ->where(function ($query): void {
                $query
                    ->whereNull('actors.id')
                    ->orWhereColumn('actors.tenant_id', '!=', 'movements.tenant_id');
            })
            ->exists();

        $hasInvalidOpnameActors = DB::table('stock_opnames as opnames')
            ->leftJoin('users as creators', 'creators.id', '=', 'opnames.created_by')
            ->leftJoin('users as finalizers', 'finalizers.id', '=', 'opnames.finalized_by')
            ->where(function ($query): void {
                $query
                    ->where(function ($query): void {
                        $query->whereNotNull('opnames.created_by')
                            ->where(function ($query): void {
                                $query->whereNull('creators.id')
                                    ->orWhereColumn('creators.tenant_id', '!=', 'opnames.tenant_id');
                            });
                    })
                    ->orWhere(function ($query): void {
                        $query->whereNotNull('opnames.finalized_by')
                            ->where(function ($query): void {
                                $query->whereNull('finalizers.id')
                                    ->orWhereColumn('finalizers.tenant_id', '!=', 'opnames.tenant_id');
                            });
                    });
            })
            ->exists();

        $hasInvalidTransferActors = DB::table('stock_transfers as transfers')
            ->leftJoin('users as requesters', 'requesters.id', '=', 'transfers.requested_by')
            ->leftJoin('users as shippers', 'shippers.id', '=', 'transfers.shipped_by')
            ->leftJoin('users as receivers', 'receivers.id', '=', 'transfers.received_by')
            ->where(function ($query): void {
                $query
                    ->whereNull('requesters.id')
                    ->orWhereColumn('requesters.tenant_id', '!=', 'transfers.tenant_id')
                    ->orWhere(function ($query): void {
                        $query->whereNotNull('transfers.shipped_by')
                            ->where(function ($query): void {
                                $query->whereNull('shippers.id')
                                    ->orWhereColumn('shippers.tenant_id', '!=', 'transfers.tenant_id');
                            });
                    })
                    ->orWhere(function ($query): void {
                        $query->whereNotNull('transfers.received_by')
                            ->where(function ($query): void {
                                $query->whereNull('receivers.id')
                                    ->orWhereColumn('receivers.tenant_id', '!=', 'transfers.tenant_id');
                            });
                    });
            })
            ->exists();

        if ($hasInvalidMovementActors || $hasInvalidOpnameActors || $hasInvalidTransferActors) {
            throw new RuntimeException(
                'Cannot enforce inventory audit actor tenant integrity: existing records reference users from another tenant.'
            );
        }

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropForeign(['created_by']);

            $table->foreign(['created_by', 'tenant_id'], 'stock_movements_creator_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
        });

        Schema::table('stock_opnames', function (Blueprint $table): void {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['finalized_by']);

            $table->foreign(['created_by', 'tenant_id'], 'stock_opnames_creator_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
            $table->foreign(['finalized_by', 'tenant_id'], 'stock_opnames_finalizer_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
        });

        Schema::table('stock_transfers', function (Blueprint $table): void {
            $table->dropForeign(['requested_by']);
            $table->dropForeign(['shipped_by']);
            $table->dropForeign(['received_by']);

            $table->foreign(['requested_by', 'tenant_id'], 'stock_transfers_requester_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
            $table->foreign(['shipped_by', 'tenant_id'], 'stock_transfers_shipper_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
            $table->foreign(['received_by', 'tenant_id'], 'stock_transfers_receiver_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table): void {
            $table->dropForeign('stock_transfers_requester_tenant_foreign');
            $table->dropForeign('stock_transfers_shipper_tenant_foreign');
            $table->dropForeign('stock_transfers_receiver_tenant_foreign');

            $table->foreign('requested_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('shipped_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('received_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('stock_opnames', function (Blueprint $table): void {
            $table->dropForeign('stock_opnames_creator_tenant_foreign');
            $table->dropForeign('stock_opnames_finalizer_tenant_foreign');

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('finalized_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropForeign('stock_movements_creator_tenant_foreign');

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }
};
