<?php

namespace App\Filament\App\Resources\PointRewards\Pages;

use App\Filament\App\Resources\PointRewards\PointRewardResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPointRewards extends ListRecords
{
    protected static string $resource = PointRewardResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
