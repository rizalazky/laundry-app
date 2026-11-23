<?php

namespace App\Observers;

use App\Models\StockMovement;
use App\Models\User;
use App\Services\ActivityLogger;

class StockMovementObserver
{
    public function created(StockMovement $movement): void
    {
        if ($movement->created_by === null) {
            return;
        }

        $actor = User::query()->find($movement->created_by);

        if ($actor === null) {
            return;
        }

        app(ActivityLogger::class)->recordForAuthorizedActor(
            $actor,
            'inventory.stock_moved',
            $movement,
            [
                'branch_id' => (int) $movement->branch_id,
                'inventory_item_id' => (int) $movement->inventory_item_id,
                'movement_type' => $movement->movement_type->value,
                'quantity' => (string) $movement->quantity,
                'stock_before' => (string) $movement->stock_before,
                'stock_after' => (string) $movement->stock_after,
                'unit_cost' => (string) $movement->unit_cost,
            ],
        );
    }
}
