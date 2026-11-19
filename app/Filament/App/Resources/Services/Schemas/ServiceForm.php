<?php

namespace App\Filament\App\Resources\Services\Schemas;

use App\Enums\ServiceUnit;
use App\Models\Branch;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Unique;

class ServiceForm
{
    public static function configure(Schema $schema): Schema
    {
        $tenantId = Auth::user()?->tenant_id;

        return $schema
            ->components([
                Section::make('Informasi Layanan')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama Layanan')
                            ->required()
                            ->maxLength(255)
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule): Unique => $rule
                                    ->where('tenant_id', $tenantId)
                                    ->whereNull('deleted_at'),
                            ),

                        Select::make('unit')
                            ->label('Satuan')
                            ->options(ServiceUnit::options())
                            ->default(ServiceUnit::Kilogram->value)
                            ->required()
                            ->native(false),

                        TextInput::make('price')
                            ->label('Harga Standar')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(9999999999)
                            ->prefix('Rp')
                            ->required(),

                        TextInput::make('estimated_hours')
                            ->label('Estimasi Pengerjaan')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(2160)
                            ->suffix('jam'),

                        Toggle::make('is_active')
                            ->label('Layanan Aktif')
                            ->default(true)
                            ->inline(false),
                    ]),

                Section::make('Harga Khusus per Cabang')
                    ->description('Kosongkan jika semua cabang memakai harga standar.')
                    ->schema([
                        Repeater::make('branchPrices')
                            ->label('')
                            ->relationship('branchPrices')
                            ->defaultItems(0)
                            ->addActionLabel('Tambah harga cabang')
                            ->columns(2)
                            ->schema([
                                Select::make('branch_id')
                                    ->label('Cabang')
                                    ->options(fn (): array => Branch::query()
                                        ->where('tenant_id', $tenantId)
                                        ->where('is_active', true)
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->all())
                                    ->required()
                                    ->native(false)
                                    ->distinct()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems(),

                                TextInput::make('price')
                                    ->label('Harga di Cabang Ini')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(9999999999)
                                    ->prefix('Rp')
                                    ->required(),
                            ]),
                    ]),
            ]);
    }
}
