<?php

namespace App\Filament\App\Resources\Customers\Schemas;

use App\Models\Customer;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Unique;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        $tenantId = Auth::user()?->tenant_id;

        return $schema
            ->components([
                Section::make('Informasi Pelanggan')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama Pelanggan')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('phone')
                            ->label('Nomor Telepon / WhatsApp')
                            ->tel()
                            ->maxLength(25)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn ($set, ?string $state) => $set('phone', Customer::normalizePhone($state)))
                            ->dehydrateStateUsing(fn (?string $state): ?string => Customer::normalizePhone($state))
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule): Unique => $rule
                                    ->where('tenant_id', $tenantId)
                                    ->whereNull('deleted_at'),
                            ),

                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255)
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule): Unique => $rule
                                    ->where('tenant_id', $tenantId)
                                    ->whereNull('deleted_at'),
                            ),

                        Textarea::make('address')
                            ->label('Alamat')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
