<?php

namespace App\Filament\App\Resources\StockMovements\Pages;

use App\Actions\Inventory\RecordStockMovement;
use App\Enums\StockMovementType;
use App\Filament\App\Resources\StockMovements\StockMovementResource;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
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

        $type = StockMovementType::tryFrom((string) $data['movement_type']);
        if ($type === null) {
            throw ValidationException::withMessages([
                'movement_type' => 'Jenis mutasi stok tidak valid.',
            ]);
        }

        $quantity = (string) $data['quantity'];
        $unitCost = (string) ($data['unit_cost'] ?? '0');
        $purchaseDetails = [];

        if ($type === StockMovementType::Purchase) {
            $item = InventoryItem::query()
                ->where('tenant_id', $user->tenant_id)
                ->with(['baseUnit', 'purchaseUnit'])
                ->find($data['inventory_item_id']);

            if (! $item || ! $item->baseUnit || ! $item->purchaseUnit) {
                throw ValidationException::withMessages([
                    'inventory_item_id' => 'Barang belum memiliki satuan pembelian dan satuan dasar yang valid.',
                ]);
            }

            $factor = BigDecimal::of((string) $item->purchase_unit_conversion_factor);
            $quantity = (string) BigDecimal::of($quantity)->multipliedBy($factor)->toScale(4);
            $unitCost = (string) BigDecimal::of($unitCost)->dividedBy($factor, 2, RoundingMode::HalfUp);

            $purchaseDetails = [
                'supplierId' => (int) ($data['supplier_id'] ?? 0),
                'purchaseQuantity' => (string) $data['quantity'],
                'purchaseUnitId' => (int) $item->purchase_unit_id,
                'purchaseUnitNameSnapshot' => $item->purchaseUnit->name,
                'purchaseConversionFactor' => (string) $item->purchase_unit_conversion_factor,
            ];
        }

        return app(RecordStockMovement::class)->handle(
            branch: $branch,
            user: $user,
            inventoryItemId: (int) $data['inventory_item_id'],
            movementType: $type,
            quantity: $quantity,
            notes: $data['notes'] ?? null,
            unitCost: $unitCost,
            supplierId: $purchaseDetails['supplierId'] ?? null,
            purchaseQuantity: $purchaseDetails['purchaseQuantity'] ?? null,
            purchaseUnitId: $purchaseDetails['purchaseUnitId'] ?? null,
            purchaseUnitNameSnapshot: $purchaseDetails['purchaseUnitNameSnapshot'] ?? null,
            purchaseConversionFactor: $purchaseDetails['purchaseConversionFactor'] ?? null,
        );
    }
}
