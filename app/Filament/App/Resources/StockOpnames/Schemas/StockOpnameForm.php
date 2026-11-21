<?php

namespace App\Filament\App\Resources\StockOpnames\Schemas;

use App\Models\Branch;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class StockOpnameForm
{
    public static function configure(Schema $schema): Schema
    {
        $tenantId = Auth::user()?->tenant_id;

        return $schema->components([
            Section::make('Informasi Stok Opname')
                ->columns(2)
                ->schema([
                    Select::make('branch_id')
                        ->label('Outlet / Workshop')
                        ->options(fn (): array => Branch::query()
                            ->where('tenant_id', $tenantId)
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->required(),

                    DateTimePicker::make('opname_date')
                        ->label('Tanggal Opname')
                        ->default(now())
                        ->required(),

                    Textarea::make('notes')
                        ->label('Catatan')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
