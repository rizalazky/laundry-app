<?php

namespace App\Filament\App\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Filament\App\Resources\Orders\OrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $tabs = [
            'all' => Tab::make('Semua Order'),
        ];

        foreach ([
            OrderStatus::Received,
            OrderStatus::Washing,
            OrderStatus::Drying,
            OrderStatus::Ironing,
            OrderStatus::Packing,
            OrderStatus::Ready,
        ] as $status) {
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
