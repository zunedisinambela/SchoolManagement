<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\User;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Identitas'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name')->label(__('Nama')),
                        TextEntry::make('email')->label(__('Email'))->copyable(),
                        TextEntry::make('created_at')
                            ->label(__('Dibuat'))
                            ->formatStateUsing(fn ($state) => $state?->translatedFormat('d F Y H:i')),
                    ]),

                Section::make(__('Hak Akses'))
                    ->schema([
                        TextEntry::make('roles.name')
                            ->label(__('Role'))
                            ->badge()
                            ->placeholder(__('Tanpa role'))
                            ->color(fn (string $state) => $state === RoleEnum::SuperAdmin->value ? 'danger' : 'gray'),
                        TextEntry::make('izin')
                            ->label(__('Izin efektif'))
                            ->badge()
                            ->placeholder(__('Tidak ada'))
                            ->state(fn (User $record) => static::effectivePermissions($record)),
                    ]),
            ]);
    }

    /**
     * What this user can actually do, rather than what is stored against them.
     *
     * super-admin holds no permission rows -- it passes through Gate::before --
     * so listing the stored grants would show an empty set for the one account
     * that can do everything.
     *
     * @return array<int, string>
     */
    protected static function effectivePermissions(User $record): array
    {
        if ($record->hasRole(RoleEnum::SuperAdmin->value)) {
            return [__('Semua (super-admin)')];
        }

        return $record->getAllPermissions()
            ->pluck('name')
            ->map(fn (string $name) => PermissionEnum::tryFrom($name)?->label() ?? $name)
            ->sort()
            ->values()
            ->all();
    }
}
