<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use BezhanSalleh\FilamentShield\Support\Utils;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Override;

class EditRole extends EditRecord
{
    public Collection $permissions;

    protected static string $resource = RoleResource::class;

    /**
     * Hook-nya `getHeaderActions()`, bukan `getActions()`.
     *
     * Stub hasil `shield:publish` memakai nama lama yang sudah tidak dipanggil
     * Filament 5, jadi tombolnya tidak pernah muncul sama sekali — bukan error,
     * cuma halaman tanpa aksi. Kalau `shield:publish` dijalankan ulang, berkas
     * ini kembali ke bentuk lamanya dan tombolnya hilang lagi.
     *
     * Guard-nya wajib: action Filament tidak ikut canDelete().
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->disabled(fn (Model $record): bool => ! RoleResource::canDelete($record))
                ->tooltip(fn (Model $record): ?string => RoleResource::isSuperAdmin($record)
                    ? __('Role super-admin tidak bisa dihapus.')
                    : null),
        ];
    }

    #[Override]
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->permissions = collect($data)
            ->filter(fn (mixed $permission, string $key): bool => ! in_array($key, ['name', 'guard_name', 'select_all', Utils::getTenantModelForeignKey()], true))
            ->values()
            ->flatten()
            ->unique();

        if (Utils::isTenancyEnabled() && Arr::has($data, Utils::getTenantModelForeignKey()) && filled($data[Utils::getTenantModelForeignKey()])) {
            return Arr::only($data, ['name', 'guard_name', Utils::getTenantModelForeignKey()]);
        }

        return Arr::only($data, ['name', 'guard_name']);
    }

    protected function afterSave(): void
    {
        $permissionModels = collect();
        $this->permissions->each(function (string $permission) use ($permissionModels): void {
            $permissionModels->push(Utils::getPermissionModel()::firstOrCreate([
                'name' => $permission,
                'guard_name' => $this->data['guard_name'],
            ]));
        });

        // @phpstan-ignore-next-line
        $this->record->syncPermissions($permissionModels);
    }
}
