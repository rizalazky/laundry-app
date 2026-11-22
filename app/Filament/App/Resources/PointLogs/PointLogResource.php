<?php

namespace App\Filament\App\Resources\PointLogs;

use App\Filament\App\Resources\PointLogs\Pages\ListPointLogs;
use App\Filament\App\Resources\PointLogs\Tables\PointLogsTable;
use App\Models\PointLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class PointLogResource extends Resource
{
    protected static ?string $model = PointLog::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static string|UnitEnum|null $navigationGroup = 'Pelanggan & CRM';
    protected static ?int $navigationSort = 34;
    protected static ?string $navigationLabel = 'Riwayat Poin';
    protected static ?string $modelLabel = 'Riwayat Poin';
    protected static ?string $pluralModelLabel = 'Riwayat Poin';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->is_active && $user->tenant_id !== null
            && in_array($user->role, ['tenant_owner', 'branch_manager'], true)
            && $user->hasPermissionTo('loyalty.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tenant_id', Auth::user()?->tenant_id)->with('customer');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return PointLogsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListPointLogs::route('/')];
    }
}
