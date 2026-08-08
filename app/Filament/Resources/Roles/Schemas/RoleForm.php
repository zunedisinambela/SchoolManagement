<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Enums\Permission as PermissionEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Role'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Nama'))
                            ->required()
                            ->maxLength(125)
                            ->unique(ignoreRecord: true)
                            ->helperText(__('Huruf kecil dan tanda hubung, contoh: guru, wali-kelas.'))
                            ->dehydrateStateUsing(fn (string $state) => Str::slug($state)),

                        // guard_name is not exposed: everything in this app
                        // authenticates on the `web` guard, and a role saved
                        // under any other guard silently never matches.
                    ]),

                Section::make(__('Izin'))
                    ->description(__('Centang apa saja yang boleh dilakukan pemegang role ini.'))
                    ->schema([
                        CheckboxList::make('permissions')
                            ->hiddenLabel()
                            ->relationship('permissions', 'name')
                            ->getOptionLabelFromRecordUsing(
                                fn (Permission $record) => PermissionEnum::tryFrom($record->name)?->label() ?? $record->name,
                            )
                            ->descriptions(fn () => Permission::pluck('name', 'id')->all())
                            ->bulkToggleable()
                            ->searchable()
                            ->columns(2),
                    ]),
            ]);
    }
}
