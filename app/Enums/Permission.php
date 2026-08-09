<?php

namespace App\Enums;

/**
 * The permission names seeded into Spatie's `permissions` table.
 *
 * Kept as an enum so a typo in a permission string fails at the call site
 * instead of silently evaluating to "not allowed". Adding a case here is not
 * enough on its own — it must also be seeded by RolePermissionSeeder.
 */
enum Permission: string
{
    case AksesPanelAdmin = 'akses-panel-admin';

    case LihatLogAktivitas = 'lihat-log-aktivitas';

    case KelolaPengguna = 'kelola-pengguna';

    case KelolaRole = 'kelola-role';

    case KelolaBackup = 'kelola-backup';

    /**
     * Deliberately separate from KelolaBackup.
     *
     * Restoring is not a read: it replaces the `users` table with the archive's
     * version, which resurrects accounts whose passwords the restorer may know
     * and undoes any role revoked since the archive was taken. That is a
     * different power from "may download an archive", so it is a different
     * permission.
     */
    case PulihkanBackup = 'pulihkan-backup';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::AksesPanelAdmin => __('Akses panel admin'),
            self::LihatLogAktivitas => __('Lihat log aktivitas'),
            self::KelolaPengguna => __('Kelola pengguna'),
            self::KelolaRole => __('Kelola role & permission'),
            self::KelolaBackup => __('Kelola backup'),
            self::PulihkanBackup => __('Pulihkan backup'),
        };
    }
}
