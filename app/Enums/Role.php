<?php

namespace App\Enums;

/**
 * The role names seeded into Spatie's `roles` table.
 *
 * SuperAdmin is special: AppServiceProvider registers a Gate::before that
 * grants it every permission, so it is never listed in a permission map.
 * Every other role gets real rows in `role_has_permissions` and is therefore
 * bound by whatever policies later modules introduce.
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
     * The baseline permissions RolePermissionSeeder grants to this role.
     *
     * Baseline, not the full truth: the seeder only adds, so permissions
     * granted by hand through the panel survive a re-run. Removing a case from
     * this list therefore does not revoke it from an existing installation —
     * that has to be done deliberately, in the panel or by hand.
     *
     * @return array<int, Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            // Holds every permission explicitly rather than passing through
            // Gate::before. The difference matters as modules land: a policy
            // written for a future module still applies to developer, and any
            // permission added later has to be granted on purpose. super-admin
            // stays the only role that bypasses all of it.
            self::Developer => Permission::cases(),

            // Deliberately empty. Gate::before in AppServiceProvider answers
            // true for this role before the permission table is ever consulted,
            // so anything listed here would be dead weight.
            self::SuperAdmin => [],

            // Day-to-day account admin. Kept away from the two permissions that
            // escalate: kelola-role lets a user mint a role holding anything
            // and hand it to themselves, and kelola-backup is one click from
            // downloading the whole database.
            self::Admin => [
                Permission::AksesPanelAdmin,
                Permission::KelolaPengguna,
                Permission::LihatLogAktivitas,
            ],

            // Panel access only. There are no teacher- or staff-facing modules
            // yet, so these two land on an empty panel until one exists.
            self::Guru, self::Karyawan => [
                Permission::AksesPanelAdmin,
            ],

            // No panel access at all. Students belong in a separate panel when
            // one is built, not in the admin panel with nothing to show them.
            self::Murid => [],
        };
    }
}
