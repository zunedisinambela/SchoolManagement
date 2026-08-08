<?php

namespace App\Models;

use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Spatie's Role model, extended only to put role CRUD in the activity log.
 *
 * Registered as `models.role` in config/permission.php, which is what makes
 * the package resolve this class everywhere -- including relations such as
 * `$user->roles` and calls like `assignRole()`. Referencing Spatie's class
 * directly still works but writes rows without logging, so use this one.
 *
 * Not to be confused with App\Enums\Role, which holds the role *names*. Import
 * one of the two under an alias wherever both are needed.
 */
class Role extends SpatieRole
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
