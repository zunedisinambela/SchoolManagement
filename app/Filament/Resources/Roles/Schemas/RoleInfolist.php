<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Enums\Permission as PermissionEnum;
use App\Filament\Resources\Roles\RoleResource;
use App\Models\Role;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RoleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Role'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name')->label(__('Nama'))->badge(),
                        TextEntry::make('users_count')
                            ->label(__('Jumlah pengguna'))
                            ->state(fn (Role $record) => $record->users()->count()),
                    ]),

                Section::make(__('Izin'))
                    ->schema([
                        TextEntry::make('izin')
                            ->hiddenLabel()
                            ->badge()
                            ->placeholder(__('Belum ada izin'))
                            ->state(fn (Role $record) => RoleResource::isSuperAdmin($record)
                                ? [__('Semua (lewat Gate::before)')]
                                : $record->permissions
                                    ->pluck('name')
                                    ->map(fn (string $name) => PermissionEnum::tryFrom($name)?->label() ?? $name)
                                    ->sort()
                                    ->values()
                                    ->all()),
                    ]),
            ]);
    }
}
