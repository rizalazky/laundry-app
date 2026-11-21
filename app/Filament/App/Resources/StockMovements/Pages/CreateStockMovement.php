<?php

namespace App\Filament\App\Resources\StockMovements\Pages;

use App\Actions\Inventory\RecordStockMovement;
use App\Filament\App\Resources\StockMovements\StockMovementResource;
use App\Models\Branch;
use App\Models\StockMovement;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CreateStockMovement extends CreateRecord
{
    protected static string $resource = StockMovementResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $branch = Filament::getTenant();
        $user = Auth::user();

        if (! $branch instanceof Branch || ! $user instanceof User) {
            throw ValidationException::withMessages([
                'inventory_item_id' => 'Cabang atau pengguna aktif tidak valid.',
            ]);
        }

        return app(RecordStockMovement::class)->handle(
            branch: $branch,
            user: $user,
            inventoryItemId: (int) $data['inventory_item_id'],
            movementType: $data['movement_type'],
            quantity: $data['quantity'],
            notes: $data['notes'] ?? null,
        );
    }
}
