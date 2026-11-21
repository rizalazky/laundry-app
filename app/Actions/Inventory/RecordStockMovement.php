<?php

namespace App\Actions\Inventory;

use App\Actions\Orders\OrderActionAuthorization;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\LocationStock;
use App\Models\StockMovement;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordStockMovement
{
    public function __construct(
        private OrderActionAuthorization $authorization,
    ) {}

    public function handle(
        Branch $branch,
        User $user,
        int $inventoryItemId,
        StockMovementType|string $movementType,
        string|int|float $quantity,
        ?string $notes = null,
    ): StockMovement {
        $branch = $this->authorization->authorizeBranch($user, $branch, ['tenant_owner']);

        $type = $movementType instanceof StockMovementType
            ? $movementType
            : StockMovementType::tryFrom($movementType);

        if ($type === null) {
            throw ValidationException::withMessages([
                'movement_type' => 'Jenis mutasi stok tidak valid.',
            ]);
        }

        return DB::transaction(function () use ($branch, $user, $inventoryItemId, $type, $quantity, $notes): StockMovement {
            $item = InventoryItem::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $branch->tenant_id)
                ->whereNull('deleted_at')
                ->with('baseUnit')
                ->find($inventoryItemId);

            if ($item === null || $item->baseUnit === null) {
                throw ValidationException::withMessages([
                    'inventory_item_id' => 'Barang inventori tidak ditemukan atau belum memiliki satuan dasar.',
                ]);
            }

            $precision = min((int) $item->baseUnit->decimal_places, 4);

            $quantityString = trim((string) $quantity);

            if (! preg_match('/^-?\\d+(?:\\.(\\d+))?$/D', $quantityString, $matches)) {
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

                $delta = $type === StockMovementType::In
                    ? $inputQuantity
                    : $inputQuantity->negated();
            }

            // Ensure the balance row exists before taking its write lock.
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
                ->create([
                    'tenant_id' => $branch->tenant_id,
                    'branch_id' => $branch->getKey(),
                    'inventory_item_id' => $item->getKey(),
                    'movement_type' => $type,
                    'quantity' => (string) $delta,
                    'stock_before' => (string) $before,
                    'stock_after' => (string) $after,
                    'notes' => $notes,
                    'created_by' => $user->getKey(),
                    'created_at' => now(),
                ]);
        });
    }
}
