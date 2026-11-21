<?php

namespace App\Filament\App\Resources\StockTransfers\Pages;

use App\Actions\Inventory\ManageStockTransfer;
use App\Filament\App\Resources\StockTransfers\StockTransferResource;
use App\Models\Branch;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CreateStockTransfer extends CreateRecord
{
    protected static string $resource = StockTransferResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw ValidationException::withMessages(['source_branch_id' => 'Pengguna tidak valid.']);
        }

        $source = Branch::query()->whereKey($data['source_branch_id'])->first();
        $destination = Branch::query()->whereKey($data['destination_branch_id'])->first();

        if (! $source || ! $destination) {
            throw ValidationException::withMessages(['destination_branch_id' => 'Cabang asal atau tujuan tidak valid.']);
        }

        return app(ManageStockTransfer::class)->request(
            user: $user,
            source: $source,
            destination: $destination,
            items: $data['items'] ?? [],
            notes: $data['notes'] ?? null,
            type: $data['transfer_type'] ?? 'distribution',
        );
    }
}
