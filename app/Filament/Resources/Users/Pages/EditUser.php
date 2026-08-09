<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * The delete button needs the same explicit guard as the one in the table:
     * Filament does not run canDelete() for actions, only for pages.
     */
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->disabled(fn (User $record) => ! UserResource::canDelete($record))
                ->tooltip(fn (User $record) => match (true) {
                    $record->is(auth()->user()) => __('Tidak bisa menghapus akun sendiri.'),
                    UserResource::isLastSuperAdmin($record) => __('Ini super-admin terakhir, harus ada yang tersisa.'),
                    default => null,
                }),
        ];
    }
}
