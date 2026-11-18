<?php

namespace App\Filament\App\Resources\Users;

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
    protected static ?string $recordTitleAttribute = 'name';
    protected static ?string $navigationLabel = 'Staff & Hak Akses';
    protected static ?string $modelLabel = 'Staff';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user !== null
            && in_array($user->role, ['tenant_owner', 'branch_manager'], true)
            && $user->is_active
            && $user->tenant_id !== null;
    }

    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();

        return parent::getEloquentQuery()
            ->where('tenant_id', $user?->tenant_id)
            ->where('role', '!=', 'super_admin')
            ->when(
                $user?->role === 'branch_manager',
                fn (Builder $query) => $query->whereHas(
                    'branches',
                    fn (Builder $branches) => $branches->whereIn(
                        'branches.id',
                        $user->branches()->select('branches.id')
                    )
                )
            );
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
