<?php

namespace App\Enums;

use App\Models\Permission;
use BezhanSalleh\FilamentShield\Support\Utils;

/**
 * The role names seeded into Spatie's `roles` table.
 *
 * Permission *names* no longer live in an enum — filament-shield generates
 * them from the panel's resources, pages and widgets, so a hand-maintained
 * list would drift the moment a resource is added. What stays hand-written is
 * this: which roles exist, and what each one starts with.
 *
 * The strings returned by permissions() are therefore unchecked by the type
 * system. `test_every_baseline_permission_exists` closes that gap — a typo or
 * a permission renamed by a later `shield:generate` turns the suite red
 * instead of silently granting nothing.
 *
 * SuperAdmin is special: filament-shield registers a Gate::before that grants
 * it everything (config `super_admin.intercept_gate`), so it is never listed
 * in a permission map.
 */
enum Role: string
{
    case Developer = 'developer';

    case SuperAdmin = 'super-admin';

    case Admin = 'admin';

    case Guru = 'guru';

    case Karyawan = 'karyawan';

    case Murid = 'murid';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Must stay identical to filament-shield's configured super admin name.
     * The name is referenced from the roles table, from the is_admin
     * migration, and from the gate shield installs. Locked by
     * `test_the_super_admin_name_matches_shield`.
     */
    public static function superAdminName(): string
    {
        return Utils::getSuperAdminName();
    }

    /**
     * The baseline permissions RolePermissionSeeder grants to this role.
     *
     * Baseline, not the full truth: the seeder only adds, so permissions
     * granted by hand through the panel survive a re-run. Removing an entry
     * here therefore does not revoke it from an existing installation — that
     * has to be done deliberately, in the panel or by hand.
     *
     * @return array<int, string>
     */
    public function permissions(): array
    {
        return match ($this) {
            // Holds every permission explicitly rather than passing through
            // Gate::before. The difference matters as modules land: a policy
            // written for a future module still applies to developer, and any
            // permission added later has to be granted on purpose. super-admin
            // stays the only role that bypasses all of it.
            self::Developer => self::allGeneratedPermissions(),

            // Deliberately empty. The gate shield installs answers true for
            // this role before the permission table is ever consulted, so
            // anything listed here would be dead weight.
            self::SuperAdmin => [],

            // Day-to-day account admin. Kept away from the permissions that
            // escalate: the Role permissions let a user mint a role holding
            // anything and hand it to themselves, View:Backups is one click
            // from downloading the whole database, and Restore:Backup swaps
            // the users table for an archived one.
            self::Admin => [
                'Access:AdminPanel',
                'ViewAny:User',
                'View:User',
                'Create:User',
                'Update:User',
                'Delete:User',
                'ViewAny:Activity',
                'View:Activity',
            ],

            // Panel access only. There are no teacher- or staff-facing modules
            // yet, so these two land on an empty panel until one exists.
            self::Guru, self::Karyawan => [
                'Access:AdminPanel',
            ],

            // No panel access at all. Students belong in a separate panel when
            // one is built, not in the admin panel with nothing to show them.
            self::Murid => [],
        };
    }

    /**
     * Every permission row shield has generated.
     *
     * Read from the table rather than from a literal list on purpose: this is
     * the one role that is meant to track whatever `shield:generate` produced,
     * so hard-coding it would mean editing this file after every new resource.
     *
     * @return array<int, string>
     */
    public static function allGeneratedPermissions(): array
    {
        return Permission::query()
            ->where('guard_name', 'web')
            ->pluck('name')
            ->all();
    }
}
