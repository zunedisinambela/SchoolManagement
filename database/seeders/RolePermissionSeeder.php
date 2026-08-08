<?php

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Seed the roles and permissions.
     *
     * Idempotent: every write is a findOrCreate, so running this against an
     * existing database adds what is missing without touching what is there.
     * Permissions are never deleted here — a permission dropped from the enum
     * has to be removed deliberately, so a rename cannot silently revoke
     * access that was granted by hand.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionEnum::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        // super-admin is granted everything by the Gate::before hook in
        // AppServiceProvider, so it deliberately holds no explicit permissions.
        Role::findOrCreate(RoleEnum::SuperAdmin->value, 'web');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
