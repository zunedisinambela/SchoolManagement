<?php

namespace App\Filament\Resources\Permissions\Tables;

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\Permission;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PermissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('label')
                    ->label(__('Izin'))
                    ->state(fn (Permission $record) => PermissionEnum::tryFrom($record->name)?->label() ?? $record->name)
                    ->description(fn (Permission $record) => $record->name)
                    ->searchable(['name'])
                    ->sortable(query: fn ($query, string $direction) => $query->orderBy('name', $direction)),
                TextColumn::make('roles.name')
                    ->label(__('Dipakai role'))
                    ->badge()
                    ->placeholder(__('Belum dipakai'))
                    ->color(fn (string $state) => $state === RoleEnum::SuperAdmin->value ? 'danger' : 'gray'),
                TextColumn::make('users_count')
                    ->label(__('Diberikan langsung ke'))
                    ->badge()
                    ->suffix(' '.__('pengguna')),
            ])
            ->recordActions([]);
    }
}
