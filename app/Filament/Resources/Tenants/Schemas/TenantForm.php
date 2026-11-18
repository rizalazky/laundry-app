<?php

namespace App\Filament\Resources\Tenants\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class TenantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Profil Bisnis Laundry')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama Brand Laundry')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn ($state, callable $set) => $set('slug', Str::slug($state))),

                        TextInput::make('slug')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),

                        TextInput::make('owner_email')
                            ->label('Email Owner')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true),

                        TextInput::make('phone')
                            ->label('No. WhatsApp Bisnis')
                            ->tel()
                            ->maxLength(25),
                    ]),
                Section::make('Paket & Langganan')
                    ->columns(2)
                    ->schema([
                        Select::make('plan')
                            ->label('Paket SaaS')
                            ->options([
                                'starter' => 'Starter (1 Cabang)',
                                'pro' => 'Pro (Maks 5 Cabang)',
                                'enterprise' => 'Enterprise (Unlimited)',
                            ])
                            ->default('starter')
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set) {
                                $set('max_branches', match ($state) {
                                    'starter' => 1,
                                    'pro' => 5,
                                    'enterprise' => 999,
                                    default => 1,
                                });
                            }),

                        TextInput::make('max_branches')
                            ->label('Maksimal Cabang')
                            ->numeric()
                            ->default(1)
                            ->required(),

                        DateTimePicker::make('subscription_ends_at')
                            ->label('Masa Aktif Hingga')
                            ->default(now()->addDays(30)),

                        Toggle::make('is_active')
                            ->label('Status Aktif')
                            ->default(true)
                            ->inline(false),
                    ]),

                Section::make('Setup Awal Owner & Cabang Utama')
                    ->columns(2)
                    ->visibleOn('create')
                    ->schema([
                        TextInput::make('owner_name')
                            ->label('Nama Lengkap Owner')
                            ->required(),

                        TextInput::make('owner_password')
                            ->label('Password Login Owner')
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(8),

                        TextInput::make('initial_branch_name')
                            ->label('Nama Cabang Pertama')
                            ->default('Cabang Pusat')
                            ->required(),

                        TextInput::make('initial_branch_code')
                            ->label('Kode Cabang (URL & Invoice)')
                            ->default('PUSAT')
                            ->alphaNum()
                            ->maxLength(15)
                            ->required(),
                    ]),

            ]);
    }
}
