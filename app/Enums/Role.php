<?php

namespace App\Enums;

/**
 * The role names seeded into Spatie's `roles` table.
 *
 * SuperAdmin is special: AppServiceProvider registers a Gate::before that
 * grants it every permission, so it is never listed in a permission map.
 */
enum Role: string
{
    case SuperAdmin = 'super-admin';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
