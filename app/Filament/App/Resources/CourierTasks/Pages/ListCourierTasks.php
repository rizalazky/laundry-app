<?php

namespace App\Filament\App\Resources\CourierTasks\Pages;

use App\Enums\CourierTaskStatus;
use App\Filament\App\Resources\CourierTasks\CourierTaskResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListCourierTasks extends ListRecords
{
    protected static string $resource = CourierTaskResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $tabs = [
            'all' => Tab::make('Semua Tugas'),
        ];

        foreach (CourierTaskStatus::cases() as $status) {
            $tabs[$status->value] = Tab::make($status->label())
                ->modifyQueryUsing(
                    fn (Builder $query): Builder => $query->where('status', $status->value),
                );
        }

        return $tabs;
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'all';
    }
}
