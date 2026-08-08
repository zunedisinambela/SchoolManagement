<?php

namespace App\Filament\Resources\Permissions;

use App\Enums\Permission as PermissionEnum;
use App\Filament\Resources\Permissions\Pages\ListPermissions;
use App\Filament\Resources\Permissions\Tables\PermissionsTable;
use App\Models\Permission;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only catalogue of the permissions the code actually checks.
 *
 * Permission names are not free-form data: each one is a case in
 * App\Enums\Permission and is referenced from a canAccess() or a can() call.
 * A permission invented here would match no check anywhere and grant nothing,
 * while deleting one would silently revoke access. Both are code changes, so
 * they belong in the enum plus RolePermissionSeeder, not in this table.
 */
class PermissionResource extends Resource
{
    protected static ?string $model = Permission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|\UnitEnum|null $navigationGroup = 'Manajemen Akses';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Izin');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Izin');
    }

    public static function table(Table $table): Table
    {
        return PermissionsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('roles')->withCount('users');
    }

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can(PermissionEnum::KelolaRole->value);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPermissions::route('/'),
        ];
    }
}
