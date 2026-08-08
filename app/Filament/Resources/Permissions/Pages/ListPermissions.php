<?php

namespace App\Filament\Resources\Permissions\Pages;

use App\Filament\Resources\Permissions\PermissionResource;
use Filament\Resources\Pages\ListRecords;

class ListPermissions extends ListRecords
{
    protected static string $resource = PermissionResource::class;

    /**
     * Permissions come from App\Enums\Permission via RolePermissionSeeder,
     * never from this page.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getSubheading(): ?string
    {
        return __('Daftar izin yang dikenali kode. Untuk menambah izin baru, tambahkan case di App\Enums\Permission lalu jalankan RolePermissionSeeder.');
    }
}
