<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Models\Role;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewRole extends ViewRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * Viewing super-admin is allowed, editing and deleting it is not, so both
     * buttons are disabled here rather than leading to a bare 403.
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->disabled(fn (Role $record) => ! RoleResource::canEdit($record))
                ->tooltip(fn (Role $record) => RoleResource::isSuperAdmin($record)
                    ? __('Role super-admin dikunci, namanya dirujuk dari kode.')
                    : null),
            DeleteAction::make()
                ->disabled(fn (Role $record) => ! RoleResource::canDelete($record))
                ->tooltip(fn (Role $record) => RoleResource::isSuperAdmin($record)
                    ? __('Role super-admin dikunci, namanya dirujuk dari kode.')
                    : null),
        ];
    }
}
