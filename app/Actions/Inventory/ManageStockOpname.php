<?php

namespace App\Actions\Inventory;

use App\Actions\Inventory\InventoryActionAuthorization;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\LocationStock;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageStockOpname
{
    public function __construct(private InventoryActionAuthorization $authorization) {}

    public function create(User $user, Branch $branch, ?string $opnameDate = null, ?string $notes = null): StockOpname
    {
        $branch = $this->authorization->authorizeBranch($user, $branch, 'stock.manage');

        return DB::transaction(function () use ($user, $branch, $opnameDate, $notes): StockOpname {
            $items = InventoryItem::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $branch->tenant_id)
                ->whereNull('deleted_at')
                ->with('baseUnit')
                ->orderBy('id')
                ->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'branch_id' => 'Belum ada barang inventori aktif untuk dilakukan stok opname.',
                ]);
            }

            $opname = StockOpname::query()->withoutGlobalScope('tenant')->create([
                'tenant_id' => $branch->tenant_id,
                'location_type' => $branch->is_workshop ? 'workshop' : 'outlet',
                'location_id' => $branch->getKey(),
                'opname_date' => $opnameDate ?? now(),
                'status' => 'draft',
                'notes' => $notes,
                'created_by' => $user->getKey(),
            ]);

            foreach ($items as $item) {
                if (! $item->baseUnit) {
                    throw ValidationException::withMessages([
                        'branch_id' => 'Barang '.$item->name.' belum memiliki satuan dasar.',
                    ]);
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

                StockOpnameItem::query()->withoutGlobalScope('tenant')->create([
                    'tenant_id' => $branch->tenant_id,
                    'stock_opname_id' => $opname->getKey(),
                    'inventory_item_id' => $item->getKey(),
                    'item_name_snapshot' => $item->name,
                    'item_sku_snapshot' => $item->sku,
                    'unit_snapshot' => $item->baseUnit->code,
                    'decimal_places_snapshot' => min((int) $item->baseUnit->decimal_places, 4),
                    'system_qty' => (string) BigDecimal::of((string) $balance->quantity)->toScale(4),
                    'physical_qty' => null,
                    'difference_qty' => null,
                ]);
            }

            return $opname->load('branch', 'items');
        });
    }

    /**
     * Save one or more physical counts. Unsubmitted rows remain uncounted until explicitly entered.
     *
     * @param array<int, array{physical_qty: string|int|float, notes?: ?string}> $counts
     */
    public function recordCounts(User $user, StockOpname $opname, array $counts): StockOpname
    {
        return DB::transaction(function () use ($user, $opname, $counts): StockOpname {
            $locked = $this->lockAuthorizedOpname($user, $opname);

            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => 'Stok opname yang sudah difinalisasi tidak dapat diubah.',
                ]);
            }

            foreach ($counts as $lineId => $count) {
                $line = $locked->items()
                    ->whereKey($lineId)
                    ->lockForUpdate()
                    ->first();

                if (! $line) {
                    throw ValidationException::withMessages([
                        'items' => 'Rincian barang tidak valid untuk stok opname ini.',
                    ]);
                }

                $quantity = trim((string) ($count['physical_qty'] ?? ''));

                if (! preg_match('/^\d+(?:\.(\d+))?$/D', $quantity, $matches)) {
                    throw ValidationException::withMessages([
                        'items.'.$lineId.'.physical_qty' => 'Jumlah fisik harus berupa angka nol atau lebih.',
                    ]);
                }

                $fraction = rtrim($matches[1] ?? '', '0');

                if (strlen($fraction) > (int) $line->decimal_places_snapshot) {
                    throw ValidationException::withMessages([
                        'items.'.$lineId.'.physical_qty' => 'Jumlah fisik melebihi presisi satuan barang.',
                    ]);
                }

                $physical = BigDecimal::of($quantity)->toScale(4);
                $system = BigDecimal::of((string) $line->system_qty)->toScale(4);

                $line->update([
                    'physical_qty' => (string) $physical,
                    'difference_qty' => (string) $physical->minus($system)->toScale(4),
                    'counted_at' => now(),
                    'notes' => $count['notes'] ?? null,
                ]);
            }

            return $locked->fresh(['branch', 'items']);
        });
    }

    public function finalize(User $user, StockOpname $opname): StockOpname
    {
        return DB::transaction(function () use ($user, $opname): StockOpname {
            $locked = $this->lockAuthorizedOpname($user, $opname);

            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => 'Stok opname sudah difinalisasi atau tidak dapat diproses.',
                ]);
            }

            $lines = $locked->items()->orderBy('inventory_item_id')->get();

            if ($lines->isEmpty() || $lines->contains(fn (StockOpnameItem $line): bool => $line->physical_qty === null || $line->counted_at === null)) {
                throw ValidationException::withMessages([
                    'items' => 'Semua barang harus dihitung sebelum stok opname difinalisasi.',
                ]);
            }

            $branch = Branch::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $locked->tenant_id)
                ->whereKey($locked->location_id)
                ->firstOrFail();

            foreach ($lines as $line) {
                $balance = LocationStock::query()
                    ->withoutGlobalScope('tenant')
                    ->where('tenant_id', $locked->tenant_id)
                    ->where('branch_id', $branch->getKey())
                    ->where('inventory_item_id', $line->inventory_item_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $before = BigDecimal::of((string) $balance->quantity)->toScale(4);
                $snapshot = BigDecimal::of((string) $line->system_qty)->toScale(4);

                // Refuse to overwrite intervening stock movements. A fresh count is safer than
                // applying a stale physical count to a balance that has changed meanwhile.
                if (! $before->isEqualTo($snapshot)) {
                    throw ValidationException::withMessages([
                        'items' => 'Stok '.$line->item_name_snapshot.' berubah sejak opname dimulai. Buat opname baru agar hasil hitung tetap akurat.',
                    ]);
                }

                $physical = BigDecimal::of((string) $line->physical_qty)->toScale(4);
                $difference = $physical->minus($before)->toScale(4);

                if ($difference->isZero()) {
                    continue;
                }

                $balance->update(['quantity' => (string) $physical]);

                StockMovement::query()->withoutGlobalScope('tenant')->create([
                    'tenant_id' => $locked->tenant_id,
                    'branch_id' => $branch->getKey(),
                    'inventory_item_id' => $line->inventory_item_id,
                    'movement_type' => StockMovementType::Adjustment,
                    'quantity' => (string) $difference,
                    'stock_before' => (string) $before,
                    'stock_after' => (string) $physical,
                    'unit_cost' => '0.00',
                    'notes' => 'Stok opname #'.$locked->getKey().' — '.$line->item_name_snapshot,
                    'created_by' => $user->getKey(),
                    'created_at' => now(),
                ]);
            }

            $locked->update([
                'status' => 'completed',
                'finalized_by' => $user->getKey(),
                'finalized_at' => now(),
            ]);

            return $locked->fresh(['branch', 'items', 'createdBy', 'finalizedBy']);
        });
    }

    private function lockAuthorizedOpname(User $user, StockOpname $opname): StockOpname
    {
        $locked = StockOpname::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $user->tenant_id)
            ->whereKey($opname->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $branch = Branch::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $locked->tenant_id)
            ->whereKey($locked->location_id)
            ->firstOrFail();

        $this->authorization->authorizeBranch($user, $branch, ['tenant_owner']);

        return $locked;
    }
}
