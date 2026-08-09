<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Models\Role;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * The page itself is already closed to super-admin by canEdit(), so this
     * guard is belt and braces -- but Filament never applies canDelete() to an
     * action, and an unguarded delete button here would remove the role.
     */
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->disabled(fn (Role $record) => ! RoleResource::canDelete($record)),
        ];
    }
}
