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
        };
    }
}
