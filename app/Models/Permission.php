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
 * Not to be confused with App\Enums\Permission, which holds the permission
 * *names*. Import one of the two under an alias wherever both are needed.
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
