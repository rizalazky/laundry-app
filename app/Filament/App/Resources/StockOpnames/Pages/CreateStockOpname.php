<?php

namespace App\Filament\App\Resources\StockOpnames\Pages;

use App\Actions\Inventory\ManageStockOpname;
use App\Filament\App\Resources\StockOpnames\StockOpnameResource;
use App\Models\Branch;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CreateStockOpname extends CreateRecord
{
    protected static string $resource = StockOpnameResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw ValidationException::withMessages([
                'branch_id' => 'Pengguna tidak valid.',
            ]);
        }

        $branch = Branch::query()
            ->where('tenant_id', $user->tenant_id)
            ->whereKey($data['branch_id'] ?? null)
            ->first();

        if (! $branch) {
            throw ValidationException::withMessages([
                'branch_id' => 'Outlet atau workshop tidak valid.',
            ]);
        }

        return app(ManageStockOpname::class)->create(
            user: $user,
            branch: $branch,
            opnameDate: $data['opname_date'] ?? null,
            notes: $data['notes'] ?? null,
        );
    }
}
