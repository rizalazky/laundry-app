<?php

namespace App\Filament\App\Resources\PointRewards;

use App\Filament\App\Resources\PointRewards\Pages\CreatePointReward;
use App\Filament\App\Resources\PointRewards\Pages\EditPointReward;
use App\Filament\App\Resources\PointRewards\Pages\ListPointRewards;
use App\Filament\App\Resources\PointRewards\Schemas\PointRewardForm;
use App\Filament\App\Resources\PointRewards\Tables\PointRewardsTable;
use App\Models\PointReward;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class PointRewardResource extends Resource
{
    protected static ?string $model = PointReward::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-gift';
    protected static string|UnitEnum|null $navigationGroup = 'Pelanggan & CRM';
    protected static ?int $navigationSort = 33;
    protected static ?string $recordTitleAttribute = 'reward_name';
    protected static ?string $navigationLabel = 'Hadiah Poin';
    protected static ?string $modelLabel = 'Hadiah Poin';
    protected static ?string $pluralModelLabel = 'Hadiah Poin';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->is_active && $user->tenant_id !== null
            && in_array($user->role, ['tenant_owner', 'branch_manager'], true)
            && $user->hasPermissionTo('loyalty.view');
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->hasPermissionTo('loyalty.manage') ?? false;
    }

    public static function canEdit($record): bool
    {
        return static::canCreate();
    }

    public static function canDelete($record): bool
    {
        return static::canCreate();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tenant_id', Auth::user()?->tenant_id);
    }

    public static function form(Schema $schema): Schema
    {
        return PointRewardForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PointRewardsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPointRewards::route('/'),
            'create' => CreatePointReward::route('/create'),
            'edit' => EditPointReward::route('/{record}/edit'),
        ];
    }
}
