<?php

namespace App\Actions\Inventory;

use App\Actions\Inventory\InventoryActionAuthorization;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\LocationStock;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use DomainException;

class ManageStockTransfer
{
    public function __construct(private InventoryActionAuthorization $authorization) {}

    public function request(User $user, Branch $source, Branch $destination, array $items, ?string $notes = null, string $type = 'distribution'): StockTransfer
    {
        $source = $this->authorization->authorizeBranch($user, $source, 'stock.manage');
        $destination = $this->authorization->authorizeBranch($user, $destination, 'stock.manage');

        if ($source->getKey() === $destination->getKey()) {
            throw ValidationException::withMessages(['destination_branch_id' => 'Lokasi tujuan harus berbeda dari lokasi asal.']);
        }

        if (! in_array($type, ['distribution', 'return'], true)) {
            throw ValidationException::withMessages(['transfer_type' => 'Jenis distribusi tidak valid.']);
        }

        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Tambahkan minimal satu barang.']);
        }

        return DB::transaction(function () use ($user, $source, $destination, $items, $notes, $type): StockTransfer {
            $transfer = StockTransfer::query()->withoutGlobalScope('tenant')->create([
                'tenant_id' => $source->tenant_id,
                'reference' => 'TRF-'.now()->format('YmdHis').'-'.str()->upper(str()->random(4)),
                'transfer_type' => $type,
                'source_branch_id' => $source->getKey(),
                'destination_branch_id' => $destination->getKey(),
                'status' => 'requested',
                'requested_by' => $user->getKey(),
                'requested_at' => now(),
                'notes' => $notes,
            ]);

            $seen = [];
            foreach ($items as $row) {
                $item = InventoryItem::query()->withoutGlobalScope('tenant')
                    ->where('tenant_id', $source->tenant_id)
                    ->whereNull('deleted_at')
                    ->with('baseUnit')
                    ->find($row['inventory_item_id'] ?? null);

                if (! $item || ! $item->baseUnit || isset($seen[$item->id])) {
                    throw ValidationException::withMessages(['items' => 'Barang tidak valid atau dipilih lebih dari sekali.']);
                }

                $quantity = (string) ($row['quantity'] ?? '');
                if (! preg_match('/^\d+(?:\.\d+)?$/D', $quantity) || BigDecimal::of($quantity)->isLessThanOrEqualTo(0)) {
                    throw ValidationException::withMessages(['items' => 'Jumlah barang harus berupa angka positif.']);
                }

                $fraction = rtrim(explode('.', $quantity, 2)[1] ?? '', '0');
                if (strlen($fraction) > min((int) $item->baseUnit->decimal_places, 4)) {
                    throw ValidationException::withMessages(['items' => 'Jumlah melebihi presisi satuan dasar barang.']);
                }

                $unitCost = (string) ($row['unit_cost'] ?? '0');
                if (! preg_match('/^\\d+(?:\\.\\d{1,2})?$/D', $unitCost)) {
                    throw ValidationException::withMessages(['items' => 'Biaya per satuan harus berupa angka non-negatif dengan maksimal dua desimal.']);
                }

                $seen[$item->id] = true;
                StockTransferItem::query()->withoutGlobalScope('tenant')->create([
                    'tenant_id' => $source->tenant_id,
                    'stock_transfer_id' => $transfer->id,
                    'inventory_item_id' => $item->id,
                    'quantity' => (string) BigDecimal::of($quantity)->toScale(4),
                    'unit_cost' => (string) ($row['unit_cost'] ?? 0),
                ]);
            }

            return $transfer->load('items.inventoryItem', 'sourceBranch', 'destinationBranch');
        });
    }

    public function ship(User $user, StockTransfer $transfer): StockTransfer
    {
        return $this->changeStatus($user, $transfer, 'requested', 'shipped', true);
    }

    public function receive(User $user, StockTransfer $transfer): StockTransfer
    {
        return $this->changeStatus($user, $transfer, 'shipped', 'received', false);
    }

    private function changeStatus(User $user, StockTransfer $transfer, string $expected, string $next, bool $deductSource): StockTransfer
    {
        return DB::transaction(function () use ($user, $transfer, $expected, $next, $deductSource): StockTransfer {
            $locked = StockTransfer::query()->withoutGlobalScope('tenant')
                ->whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();

            $branch = Branch::query()->withoutGlobalScope('tenant')
                ->whereKey($deductSource ? $locked->source_branch_id : $locked->destination_branch_id)
                ->firstOrFail();
            $this->authorization->authorizeBranch($user, $branch, 'stock.manage');

            if ($locked->status !== $expected) {
                throw ValidationException::withMessages(['status' => 'Status distribusi sudah berubah atau tindakan tidak sesuai urutan.']);
            }

            foreach ($locked->items()->with('inventoryItem.baseUnit')->orderBy('inventory_item_id')->get() as $line) {
                $locationBranchId = $deductSource ? $locked->source_branch_id : $locked->destination_branch_id;
                DB::table('location_stocks')->insertOrIgnore([
                    'tenant_id' => $locked->tenant_id,
                    'branch_id' => $locationBranchId,
                    'inventory_item_id' => $line->inventory_item_id,
                    'quantity' => '0.0000',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $balance = LocationStock::query()->withoutGlobalScope('tenant')
                    ->where('tenant_id', $locked->tenant_id)
                    ->where('branch_id', $locationBranchId)
                    ->where('inventory_item_id', $line->inventory_item_id)
                    ->lockForUpdate()->firstOrFail();

                $before = BigDecimal::of((string) $balance->quantity)->toScale(4);
                $quantity = BigDecimal::of((string) $line->quantity)->toScale(4);
                $delta = $deductSource ? $quantity->negated() : $quantity;
                $after = $before->plus($delta)->toScale(4);

                if ($after->isLessThan(0)) {
                    throw ValidationException::withMessages(['stock' => 'Stok '.($line->inventoryItem?->name ?? 'Barang #'.$line->inventory_item_id).' tidak mencukupi. Tersedia: '.$before.'.']);
                }

                $balance->update(['quantity' => (string) $after]);

                StockMovement::query()->withoutGlobalScope('tenant')->create([
                    'tenant_id' => $locked->tenant_id,
                    'branch_id' => $locationBranchId,
                    'inventory_item_id' => $line->inventory_item_id,
                    'movement_type' => $deductSource ? StockMovementType::Out : StockMovementType::In,
                    'quantity' => (string) $delta,
                    'stock_before' => (string) $before,
                    'stock_after' => (string) $after,
                    'unit_cost' => (string) $line->unit_cost,
                    'notes' => $locked->reference.' — '.($deductSource ? 'Pengiriman ke ' : 'Penerimaan dari ').($deductSource ? $locked->destinationBranch->name : $locked->sourceBranch->name),
                    'created_by' => $user->getKey(),
                    'created_at' => now(),
                ]);
            }

            $locked->update([
                'status' => $next,
                $deductSource ? 'shipped_by' : 'received_by' => $user->getKey(),
                $deductSource ? 'shipped_at' : 'received_at' => now(),
            ]);

            return $locked->fresh(['items.inventoryItem', 'sourceBranch', 'destinationBranch']);
        });
    }
}
