<?php

namespace App\Filament\App\Resources\PointRewards\Pages;

use App\Filament\App\Resources\PointRewards\PointRewardResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPointReward extends EditRecord
{
    protected static string $resource = PointRewardResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
