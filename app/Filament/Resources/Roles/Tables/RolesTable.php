<?php

namespace App\Filament\Resources\Roles\Tables;

use App\Filament\Resources\Roles\RoleResource;
use App\Models\Role;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Nama'))
                    ->badge()
                    ->color(fn (Role $record) => RoleResource::isSuperAdmin($record) ? 'danger' : 'gray')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('permissions_count')
                    ->label(__('Jumlah izin'))
                    ->badge()
                    ->formatStateUsing(fn (int $state, Role $record) => RoleResource::isSuperAdmin($record)
                        ? __('Semua')
                        : $state)
                    ->sortable(),
                TextColumn::make('users_count')
                    ->label(__('Jumlah pengguna'))
                    ->badge()
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
