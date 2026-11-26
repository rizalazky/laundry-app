<?php

namespace App\\Filament\\Resources\\Plans;

use App\\Filament\\Resources\\Plans\\Pages\\CreatePlan;
use App\\Filament\\Resources\\Plans\\Pages\\EditPlan;
use App\\Filament\\Resources\\Plans\\Pages\\ListPlans;
use App\\Filament\\Resources\\Plans\\Schemas\\PlanForm;
use App\\Filament\\Resources\\Plans\\Tables\\PlansTable;
use App\\Models\\Plan;
use BackedEnum;
use Filament\\Resources\\Resource;
use Filament\\Schemas\\Schema;
use Filament\\Tables\\Table;
use Illuminate\\Support\\Facades\\Auth;
use UnitEnum;

class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|UnitEnum|null $navigationGroup = 'SaaS Platform';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Paket Langganan';

    protected static ?string $modelLabel = 'Paket Langganan';

    protected static ?string $pluralModelLabel = 'Paket Langganan';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user !== null
            && $user->is_active
            && $user->role === 'super_admin'
            && $user->tenant_id === null;
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
    }

    public static function canEdit($record): bool
    {
        return static::canAccess();
    }

    public static function canDelete($record): bool
    {
        return static::canAccess() && ! $record->subscriptions()->exists();
    }

    public static function form(Schema $schema): Schema
    {
        return PlanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PlansTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlans::route('/'),
            'create' => CreatePlan::route('/create'),
            'edit' => EditPlan::route('/{record}/edit'),
        ];
    }
}
