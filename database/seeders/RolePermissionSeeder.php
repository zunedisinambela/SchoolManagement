<?php

namespace Database\Seeders;

use App\Enums\Role as RoleEnum;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Seed the roles and their baseline permissions.
     *
     * Permission rows are no longer written here. filament-shield derives them
     * from the panel's resources, pages and widgets, so this seeder asks the
     * generator for them rather than keeping a second list that would drift.
     *
     * `--option=permissions` matters: the default also (re)writes policy
     * classes into app/Policies. Those are code, they belong in git, and a
     * seeder that writes PHP files is a seeder that fails on a read-only
     * deploy. This run touches data only.
     *
     * Idempotent: shield's generator is findOrCreate-based, and role grants use
     * givePermissionTo, which adds without detaching. So a permission an admin
     * granted through the panel survives a re-run. Syncing instead would make
     * the deploy checklist's "run this seeder" step quietly undo every
     * authorization change made since the last deploy — the kind of regression
     * nobody connects back to a deploy.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Artisan::call('shield:generate', [
            '--all' => true,
            '--panel' => 'admin',
            '--option' => 'permissions',
            '--no-interaction' => true,
        ]);

        // Not redundant with the flush above, and not something the package
        // does for us here. Spatie invalidates its permission cache from a
        // `created` model event, and DatabaseSeeder wraps every seeder in
        // Model::withoutEvents() -- so on a fresh database the rows just
        // written stay invisible to the registrar, which cached an empty
        // collection during the very first lookup. givePermissionTo reads that
        // cache, not the table, and fails with PermissionDoesNotExist for a
        // permission sitting right there in the database.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (RoleEnum::cases() as $roleEnum) {
            $role = Role::findOrCreate($roleEnum->value, 'web');

            $permissions = $roleEnum->permissions();

            if ($permissions !== []) {
                $role->givePermissionTo($permissions);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
