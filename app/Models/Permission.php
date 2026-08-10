<?php

namespace App\Models;

use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Spatie's Permission model, extended only to put permission CRUD in the
 * activity log.
 *
 * Permissions are created by RolePermissionSeeder rather than by hand, so
 * these rows are rare -- but a permission quietly disappearing is exactly the
 * kind of change that needs a trace, since every check against it starts
 * returning false.
 *
 * Registered as `models.permission` in config/permission.php.
 *
 * Permission *names* are no longer hand-written anywhere: filament-shield
 * generates them from the panel's resources, pages and widgets. Rows are
 * created by `shield:generate`, not by this class.
 */
class Permission extends SpatiePermission
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'guard_name'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('otorisasi');
    }
}
