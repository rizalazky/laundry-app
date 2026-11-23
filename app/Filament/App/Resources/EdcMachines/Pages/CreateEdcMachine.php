<?php

namespace App\Filament\App\Resources\EdcMachines\Pages;

use App\Actions\Accounting\ManageEdcMachine;
use App\Filament\App\Resources\EdcMachines\EdcMachineResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CreateEdcMachine extends CreateRecord
{
    protected static string $resource = EdcMachineResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw ValidationException::withMessages([
                'data.branch_id' => 'Pengguna tidak valid.',
            ]);
        }

        return app(ManageEdcMachine::class)->create($user, $data);
    }
}
