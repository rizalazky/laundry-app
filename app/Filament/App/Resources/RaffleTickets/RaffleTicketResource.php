<?php

namespace App\Filament\App\Resources\RaffleTickets;

use App\Filament\App\Resources\RaffleTickets\Pages\ListRaffleTickets;
use App\Filament\App\Resources\RaffleTickets\Tables\RaffleTicketsTable;
use App\Models\RaffleTicket;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class RaffleTicketResource extends Resource
{
    protected static ?string $model = RaffleTicket::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-ticket';
    protected static string|UnitEnum|null $navigationGroup = 'Pelanggan & CRM';
    protected static ?int $navigationSort = 36;
    protected static ?string $navigationLabel = 'Tiket Undian';
    protected static ?string $modelLabel = 'Tiket Undian';
    protected static ?string $pluralModelLabel = 'Tiket Undian';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->is_active && $user->tenant_id !== null
            && in_array($user->role, ['tenant_owner', 'branch_manager'], true)
            && $user->hasPermissionTo('raffles.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tenant_id', Auth::user()?->tenant_id)
            ->with(['raffle', 'customer']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return RaffleTicketsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListRaffleTickets::route('/')];
    }
}
