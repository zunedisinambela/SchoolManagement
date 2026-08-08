<?php

namespace App\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Records every grant and revoke of a role or permission to the activity log.
 *
 * Without this, an admin can raise their own privileges through the panel and
 * leave no trace, which is exactly the change an audit trail exists to catch.
 *
 * Requires `events_enabled` in config/permission.php.
 */
class LogAuthorizationChanges
{
    public function recordRoleAttached(RoleAttachedEvent $event): void
    {
        $this->record($event->model, 'role-diberikan', __('Role diberikan'), [
            'role' => $this->roleNames($event->rolesOrIds),
        ]);
    }

    public function recordRoleDetached(RoleDetachedEvent $event): void
    {
        $this->record($event->model, 'role-dicabut', __('Role dicabut'), [
            'role' => $this->roleNames($event->rolesOrIds),
        ]);
    }

    public function recordPermissionAttached(PermissionAttachedEvent $event): void
    {
        $this->record($event->model, 'izin-diberikan', __('Izin diberikan'), [
            'izin' => $this->permissionNames($event->permissionsOrIds),
        ]);
    }

    public function recordPermissionDetached(PermissionDetachedEvent $event): void
    {
        $this->record($event->model, 'izin-dicabut', __('Izin dicabut'), [
            'izin' => $this->permissionNames($event->permissionsOrIds),
        ]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    protected function record(Model $subject, string $event, string $description, array $properties): void
    {
        activity('otorisasi')
            ->performedOn($subject)
            ->event($event)
            ->withProperties($properties)
            ->log($description);
    }

    /**
     * The events hand over ids, model instances or a collection depending on
     * the call site, so every shape is resolved back to plain names.
     *
     * @return array<int, string>
     */
    protected function roleNames(mixed $rolesOrIds): array
    {
        return $this->names($rolesOrIds, Role::class);
    }

    /**
     * @return array<int, string>
     */
    protected function permissionNames(mixed $permissionsOrIds): array
    {
        return $this->names($permissionsOrIds, Permission::class);
    }

    /**
     * @param  class-string<Model>  $model
     * @return array<int, string>
     */
    protected function names(mixed $value, string $model): array
    {
        $items = match (true) {
            $value instanceof Collection => $value->all(),
            $value instanceof Model => [$value],
            is_array($value) => $value,
            default => [$value],
        };

        $names = [];
        $ids = [];

        foreach ($items as $item) {
            if ($item instanceof Model) {
                $names[] = (string) $item->name;

                continue;
            }

            $ids[] = $item;
        }

        if ($ids !== []) {
            $names = [
                ...$names,
                ...$model::whereKey($ids)->pluck('name')->all(),
            ];
        }

        sort($names);

        return $names;
    }
}
