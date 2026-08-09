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
     *
     * Role grants follow the same rule. givePermissionTo adds without
     * detaching, so a permission an admin granted through the panel survives a
     * re-run. Syncing instead would make the deploy checklist's "run this
     * seeder" step quietly undo every authorization change made since the last
     * deploy — the kind of regression nobody connects back to a deploy.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionEnum::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        // Not redundant with the flush above, and not something the package
        // does for us here. Spatie invalidates its permission cache from a
        // `created` model event, and DatabaseSeeder wraps every seeder in
        // Model::withoutEvents() -- so on a fresh database the rows just
        // written stay invisible to the registrar, which cached an empty
        // collection during the very first findOrCreate lookup. givePermissionTo
        // reads that cache, not the table, and fails with PermissionDoesNotExist
        // for a permission sitting right there in the database.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (RoleEnum::cases() as $roleEnum) {
            $role = Role::findOrCreate($roleEnum->value, 'web');

            $permissions = array_column($roleEnum->permissions(), 'value');

            if ($permissions !== []) {
                $role->givePermissionTo($permissions);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
