<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
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
