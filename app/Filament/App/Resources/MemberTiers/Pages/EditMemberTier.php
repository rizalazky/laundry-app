<?php

namespace App\Filament\App\Resources\MemberTiers\Pages;

use App\Filament\App\Resources\MemberTiers\MemberTierResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMemberTier extends EditRecord
{
    protected static string $resource = MemberTierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (): bool => MemberTierResource::canDelete($this->getRecord())),
        ];
    }
}
