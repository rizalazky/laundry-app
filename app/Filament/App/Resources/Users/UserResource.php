<?php

namespace App\Filament\App\Resources\Users;

use UnitEnum;

use App\Filament\App\Resources\Users\Pages\CreateUser;
use App\Filament\App\Resources\Users\Pages\EditUser;
use App\Filament\App\Resources\Users\Pages\ListUsers;
use App\Filament\App\Resources\Users\Schemas\UserForm;
use App\Filament\App\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class UserResource extends Resource
{
    protected static ?string $model = User::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';
    protected static string|UnitEnum|null $navigationGroup = 'Karyawan & SDM';
    protected static ?int $navigationSort = 10;
    protected static ?string $recordTitleAttribute = 'name';
    protected static ?string $navigationLabel = 'Daftar Karyawan';
    protected static ?string $modelLabel = 'Staff';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user !== null
            && in_array($user->role, ['tenant_owner', 'branch_manager'], true)
            && $user->is_active
            && $user->tenant_id !== null
            && $user->hasPermissionTo('staff.view');
    }

    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();

        $query = parent::getEloquentQuery()
            ->where('tenant_id', $user?->tenant_id)
            ->where('role', '!=', 'super_admin');

        if ($user?->role === 'branch_manager') {
            $managedBranchIds = $user->branches()
                ->where('branches.tenant_id', $user->tenant_id)
                ->where('branches.is_active', true)
                ->pluck('branches.id');

            $query
                ->whereIn('role', ['cashier', 'operator', 'courier'])
                ->whereHas(
                    'branches',
                    fn (Builder $branches) => $branches->whereIn('branches.id', $managedBranchIds)
                )
                ->whereDoesntHave(
                    'branches',
                    fn (Builder $branches) => $branches->whereNotIn('branches.id', $managedBranchIds)
                );
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
