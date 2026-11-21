<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\LocationStock;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordStockMovement
{
    public function __construct(
        private InventoryActionAuthorization $authorization,
    ) {}

    public function handle(
        Branch $branch,
        User $user,
        int $inventoryItemId,
        StockMovementType|string $movementType,
        string|int|float $quantity,
        ?string $notes = null,
        string|int|float $unitCost = 0,
        ?int $supplierId = null,
        string|int|float|null $purchaseQuantity = null,
        ?int $purchaseUnitId = null,
        ?string $purchaseUnitNameSnapshot = null,
        string|int|float|null $purchaseConversionFactor = null,
    ): StockMovement {
        $branch = $this->authorization->authorizeBranch($user, $branch, 'stock.manage');

        $type = $movementType instanceof StockMovementType
            ? $movementType
            : StockMovementType::tryFrom($movementType);

        if ($type === null) {
            throw ValidationException::withMessages([
                'movement_type' => 'Jenis mutasi stok tidak valid.',
            ]);
        }

        return DB::transaction(function () use (
            $branch,
            $user,
            $inventoryItemId,
            $type,
            $quantity,
            $notes,
            $unitCost,
            $supplierId,
            $purchaseQuantity,
            $purchaseUnitId,
            $purchaseUnitNameSnapshot,
            $purchaseConversionFactor,
        ): StockMovement {
            $item = InventoryItem::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $branch->tenant_id)
                ->whereNull('deleted_at')
                ->with(['baseUnit', 'purchaseUnit'])
                ->find($inventoryItemId);

            if ($item === null || $item->baseUnit === null) {
                throw ValidationException::withMessages([
                    'inventory_item_id' => 'Barang inventori tidak ditemukan atau belum memiliki satuan dasar.',
                ]);
            }

            $purchaseData = [];
            if ($type === StockMovementType::Purchase) {
                $supplier = Supplier::query()
                    ->withoutGlobalScope('tenant')
                    ->where('tenant_id', $branch->tenant_id)
                    ->find($supplierId);

                $purchaseUnit = $item->purchaseUnit;
                $purchaseQuantityString = trim((string) ($purchaseQuantity ?? ''));
                $conversionFactorString = trim((string) ($purchaseConversionFactor ?? ''));

                if ($supplier === null) {
                    throw ValidationException::withMessages([
                        'supplier_id' => 'Supplier tidak valid untuk tenant ini.',
                    ]);
                }

                if ($purchaseUnit === null || (int) $purchaseUnit->getKey() !== (int) $purchaseUnitId) {
                    throw ValidationException::withMessages([
                        'purchase_unit_id' => 'Satuan pembelian tidak sesuai dengan pengaturan barang.',
                    ]);
                }

                if (! preg_match('/^\d+(?:\.(\d+))?$/D', $purchaseQuantityString, $purchaseMatches)
                    || BigDecimal::of($purchaseQuantityString)->isLessThanOrEqualTo(0)) {
                    throw ValidationException::withMessages([
                        'purchase_quantity' => 'Jumlah pembelian harus berupa angka positif.',
                    ]);
                }

                $purchaseFraction = rtrim($purchaseMatches[1] ?? '', '0');
                if (strlen($purchaseFraction) > min((int) $purchaseUnit->decimal_places, 4)) {
                    throw ValidationException::withMessages([
                        'purchase_quantity' => 'Jumlah pembelian melebihi presisi satuan pembelian.',
                    ]);
                }

                $expectedFactor = BigDecimal::of((string) $item->purchase_unit_conversion_factor);
                if (! preg_match('/^\d+(?:\.\d{1,6})?$/D', $conversionFactorString)
                    || ! BigDecimal::of($conversionFactorString)->isEqualTo($expectedFactor)
                    || BigDecimal::of($conversionFactorString)->isLessThanOrEqualTo(0)) {
                    throw ValidationException::withMessages([
                        'purchase_conversion_factor' => 'Konversi satuan pembelian tidak valid.',
                    ]);
                }

                $purchaseData = [
                    'supplier_id' => $supplier->getKey(),
                    'supplier_name_snapshot' => $supplier->name,
                    'purchase_quantity' => $purchaseQuantityString,
                    'purchase_unit_id' => $purchaseUnit->getKey(),
                    'purchase_unit_name_snapshot' => $purchaseUnit->name,
                    'purchase_conversion_factor' => $conversionFactorString,
                ];
            } elseif ($supplierId !== null || $purchaseQuantity !== null || $purchaseUnitId !== null) {
                throw ValidationException::withMessages([
                    'movement_type' => 'Informasi supplier hanya dapat disimpan untuk transaksi pembelian.',
                ]);
            }

            $unitCostString = trim((string) $unitCost);
            if (! preg_match('/^\d+(?:\.\d{1,2})?$/D', $unitCostString)) {
                throw ValidationException::withMessages([
                    'unit_cost' => 'Harga per satuan harus berupa angka non-negatif dengan maksimal dua desimal.',
                ]);
            }

            $precision = min((int) $item->baseUnit->decimal_places, 4);
            $quantityString = trim((string) $quantity);

            if (! preg_match('/^-?\d+(?:\.(\d+))?$/D', $quantityString, $matches)) {
                throw ValidationException::withMessages([
                    'quantity' => 'Jumlah mutasi harus berupa angka yang valid.',
                ]);
            }

            $fraction = rtrim($matches[1] ?? '', '0');
            if (strlen($fraction) > $precision) {
                throw ValidationException::withMessages([
                    'quantity' => "Jumlah hanya boleh menggunakan maksimal {$precision} angka desimal.",
                ]);
            }

            $inputQuantity = BigDecimal::of($quantityString);

            if ($type === StockMovementType::Adjustment) {
                if ($inputQuantity->isZero()) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Nilai penyesuaian stok tidak boleh nol.',
                    ]);
                }

                $delta = $inputQuantity;
            } else {
                if ($inputQuantity->isLessThanOrEqualTo(0)) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Jumlah mutasi harus lebih dari nol.',
                    ]);
                }

                $delta = in_array($type, [StockMovementType::In, StockMovementType::Purchase], true)
                    ? $inputQuantity
                    : $inputQuantity->negated();
            }

            DB::table('location_stocks')->insertOrIgnore([
                'tenant_id' => $branch->tenant_id,
                'branch_id' => $branch->getKey(),
                'inventory_item_id' => $item->getKey(),
                'quantity' => '0.0000',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $balance = LocationStock::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $branch->tenant_id)
                ->where('branch_id', $branch->getKey())
                ->where('inventory_item_id', $item->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $before = BigDecimal::of((string) $balance->quantity)->toScale(4);
            $after = $before->plus($delta)->toScale(4);

            if ($after->isLessThan(0)) {
                throw ValidationException::withMessages([
                    'quantity' => 'Stok tidak mencukupi. Stok tersedia: '.$before.'.',
                ]);
            }

            $balance->update([
                'quantity' => (string) $after,
            ]);

            return StockMovement::query()
                ->withoutGlobalScope('tenant')
                ->create(array_merge([
                    'tenant_id' => $branch->tenant_id,
                    'branch_id' => $branch->getKey(),
                    'inventory_item_id' => $item->getKey(),
                    'movement_type' => $type,
                    'quantity' => (string) $delta,
                    'stock_before' => (string) $before,
                    'stock_after' => (string) $after,
                    'unit_cost' => $unitCostString,
                    'notes' => $notes,
                    'created_by' => $user->getKey(),
                    'created_at' => now(),
                ], $purchaseData));
        });
    }
}
