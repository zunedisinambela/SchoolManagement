<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewRole extends ViewRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * `getActions()` di stub hasil `shield:publish` sudah bukan hook Filament 5.
     * Guard-nya wajib: action Filament tidak ikut canEdit().
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->disabled(fn (Model $record): bool => ! RoleResource::canEdit($record))
                ->tooltip(fn (Model $record): ?string => RoleResource::isSuperAdmin($record)
                    ? __('Role super-admin dikunci dari penyuntingan.')
                    : null),
        ];
    }
}
