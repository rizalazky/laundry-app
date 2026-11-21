<?php

namespace App\Filament\App\Resources\Users\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('Email Login')
                    ->searchable(),

                TextColumn::make('phone_whatsapp')
                    ->label('WhatsApp')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('role')
                    ->label('Role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'tenant_owner' => 'primary',
                        'branch_manager' => 'warning',
                        'cashier' => 'success',
                        'operator' => 'info',
                        default => 'gray',
                    }),

                TextColumn::make('branches.name')
                    ->label('Cabang Tugas')
                    ->badge()
                    ->placeholder('Semua Cabang (Owner)'),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
