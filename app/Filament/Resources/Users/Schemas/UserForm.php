<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\Role as RoleEnum;
use App\Models\User;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Role;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Identitas'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Nama'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label(__('Email'))
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('password')
                            ->label(__('Kata sandi'))
                            ->password()
                            ->revealable()
                            ->confirmed()
                            ->minLength(8)
                            // Required only when creating. On edit an empty box
                            // means "leave the current password alone", so the
                            // field is dropped from the payload rather than
                            // saved as an empty string.
                            ->required(fn (string $operation) => $operation === 'create')
                            ->dehydrated(fn (?string $state) => filled($state))
                            ->helperText(fn (string $operation) => $operation === 'edit'
                                ? __('Kosongkan kalau tidak ingin mengubah kata sandi.')
                                : null),
                        TextInput::make('password_confirmation')
                            ->label(__('Ulangi kata sandi'))
                            ->password()
                            ->revealable()
                            ->minLength(8)
                            ->required(fn (string $operation) => $operation === 'create')
                            ->dehydrated(false),
                    ]),

                Section::make(__('Role'))
                    ->description(__('Menentukan apa saja yang bisa diakses pengguna ini.'))
                    ->schema([
                        CheckboxList::make('roles')
                            ->hiddenLabel()
                            ->relationship('roles', 'name')
                            ->descriptions(fn () => Role::pluck('name', 'id')
                                ->map(fn (string $name) => $name === RoleEnum::SuperAdmin->value
                                    ? __('Akses penuh ke seluruh sistem, melewati semua pengecekan izin.')
                                    : __('Izin ditentukan di menu Role.'))
                                ->all())
                            ->bulkToggleable()
                            ->columns(2)
                            ->rule(static::keepAtLeastOneSuperAdmin()),
                    ]),
            ]);
    }

    /**
     * Refuse a save that would leave the system with no super-admin.
     *
     * Without this an admin can quietly strip the role from the only account
     * that has it -- including their own -- and lock everybody out, since
     * nothing else in the panel can grant it back.
     */
    protected static function keepAtLeastOneSuperAdmin(): Closure
    {
        return static function (?User $record): Closure {
            return static function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                if (! $record?->hasRole(RoleEnum::SuperAdmin->value)) {
                    return;
                }

                $superAdminId = Role::where('name', RoleEnum::SuperAdmin->value)->value('id');
                $keepsRole = in_array((string) $superAdminId, array_map('strval', (array) $value), true);

                if ($keepsRole) {
                    return;
                }

                $remaining = User::role(RoleEnum::SuperAdmin->value)
                    ->whereKeyNot($record->getKey())
                    ->count();

                if ($remaining === 0) {
                    $fail(__('Role super-admin tidak bisa dilepas dari pengguna terakhir yang memilikinya.'));
                }
            };
        };
    }
}
