<?php

namespace App\Filament\Resources\Users;

use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Schemas\UserInfolist;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|\UnitEnum|null $navigationGroup = 'Manajemen Akses';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Pengguna');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Pengguna');
    }

    public static function getNavigationLabel(): string
    {
        return __('Pengguna');
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return UserInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('roles');
    }

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can(Permission::KelolaPengguna->value);
    }

    /**
     * Deleting yourself, or the last super-admin, locks everyone out of the
     * panel with no way back in short of a tinker session.
     */
    public static function canDelete(Model $record): bool
    {
        if ($record->is(Filament::auth()->user())) {
            return false;
        }

        return ! static::isLastSuperAdmin($record);
    }

    /**
     * Bulk delete bypasses the per-record canDelete check above, so it is not
     * offered at all. There are never enough users here to need it.
     */
    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function isLastSuperAdmin(Model $record): bool
    {
        if (! $record->hasRole(RoleEnum::SuperAdmin->value)) {
            return false;
        }

        return User::role(RoleEnum::SuperAdmin->value)->count() <= 1;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'view' => ViewUser::route('/{record}'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
