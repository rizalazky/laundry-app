<?php

namespace App\Filament\App\Resources\Users\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\CheckboxList;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Akun Staff')
                        ->columns(2)
                        ->schema([
                            TextInput::make('name')
                                ->label('Nama Lengkap')
                                ->required()
                                ->maxLength(255),

                            TextInput::make('email')
                                ->label('Email Login')
                                ->email()
                                ->required()
                                ->unique(ignoreRecord: true),

                            TextInput::make('password')
                                ->label('Password')
                                ->password()
                                ->revealable()
                                ->dehydrated(fn (?string $state): bool => filled($state))
                                ->required(fn (string $operation): bool => $operation === 'create')
                                ->minLength(8)
                                ->helperText('Kosongkan saat edit jika tidak ingin mengubah password.'),

                            Select::make('role')
                                ->label('Jabatan / Role')
                                ->options(fn () => Auth::user()?->role === 'tenant_owner'
                                    ? [
                                        'tenant_owner'   => 'Tenant Owner (Semua Cabang)',
                                        'branch_manager' => 'Branch Manager (Kepala Cabang)',
                                        'cashier'        => 'Kasir (POS & Pembayaran)',
                                        'operator'       => 'Operator Produksi (Cuci & Setrika)',
                                    ]
                                    : [
                                        'cashier'  => 'Kasir (POS & Pembayaran)',
                                        'operator' => 'Operator Produksi (Cuci & Setrika)',
                                    ])
                                ->default('cashier')
                                ->required()
                                ->live(),

                            Toggle::make('is_active')
                                ->label('Akun Aktif')
                                ->default(true)
                                ->inline(false),
                        ]),

                    Section::make('Penugasan Cabang')
                        ->visible(fn ($get): bool => $get('role') !== 'tenant_owner')
                        ->schema([
                            CheckboxList::make('branches')
                                ->label('Cabang yang Dapat Diakses')
                                ->relationship(
                                    name: 'branches',
                                    titleAttribute: 'name',
                                    modifyQueryUsing: function (Builder $query) {
                                        $user = Auth::user();
                                        $query->where('tenant_id', $user->tenant_id);

                                        if ($user->role !== 'tenant_owner') {
                                            $query->whereIn('branches.id', $user->branches->pluck('id'));
                                        }
                                    }
                                )
                                ->columns(2)
                                ->required(fn ($get): bool => $get('role') !== 'tenant_owner'),
                        ]),
            ]);
    }
}
