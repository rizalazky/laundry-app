<?php

namespace App\Filament\App\Resources\Users\Tables;

use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

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
            ->filters([
                SelectFilter::make('role')
                    ->label('Jabatan')
                    ->options(fn (): array => Auth::user()?->role === 'tenant_owner'
                        ? [
                            'branch_manager' => 'Branch Manager',
                            'cashier' => 'Kasir',
                            'operator' => 'Operator Produksi',
                        ]
                        : [
                            'cashier' => 'Kasir',
                            'operator' => 'Operator Produksi',
                        ]),

                TernaryFilter::make('is_active')
                    ->label('Status Akun')
                    ->placeholder('Semua status')
                    ->trueLabel('Aktif')
                    ->falseLabel('Nonaktif'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
