<?php

namespace App\Filament\Resources\Roles;

use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\Pages\ViewRole;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use App\Filament\Resources\Roles\Schemas\RoleInfolist;
use App\Filament\Resources\Roles\Tables\RolesTable;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Manajemen Akses';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Role');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Role');
    }

    public static function form(Schema $schema): Schema
    {
        return RoleForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return RoleInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RolesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount(['permissions', 'users']);
    }

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can(Permission::KelolaRole->value);
    }

    /**
     * super-admin is referenced by name from App\Enums\Role, from the
     * Gate::before hook, and from a migration. Renaming it through the UI
     * would break all three, and its permission list is meaningless anyway
     * because the gate grants everything. So it is locked.
     */
    public static function canEdit(Model $record): bool
    {
        return ! static::isSuperAdmin($record);
    }

    public static function canDelete(Model $record): bool
    {
        return ! static::isSuperAdmin($record);
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function isSuperAdmin(Model $record): bool
    {
        return $record->name === RoleEnum::SuperAdmin->value;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'view' => ViewRole::route('/{record}'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
