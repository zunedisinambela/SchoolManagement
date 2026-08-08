<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\Role as RoleEnum;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Nama'))
                    ->description(fn (User $record) => $record->is(Filament::auth()->user())
                        ? __('Akun Anda')
                        : null)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('Email'))
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('roles.name')
                    ->label(__('Role'))
                    ->badge()
                    ->placeholder(__('Tanpa role'))
                    ->color(fn (string $state) => $state === RoleEnum::SuperAdmin->value ? 'danger' : 'gray'),
                TextColumn::make('created_at')
                    ->label(__('Dibuat'))
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('roles')
                    ->label(__('Role'))
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make()
                    // canDelete already hides this, but an explicit reason beats
                    // a button that silently disappears.
                    ->tooltip(fn (User $record) => match (true) {
                        $record->is(Filament::auth()->user()) => __('Tidak bisa menghapus akun sendiri.'),
                        UserResource::isLastSuperAdmin($record) => __('Ini super-admin terakhir.'),
                        default => null,
                    }),
            ]);
    }
}
