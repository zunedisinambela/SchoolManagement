<?php

namespace App\Providers;

use App\Enums\Role;
use App\Listeners\LogAuthenticationActivity;
use App\Listeners\LogAuthorizationChanges;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Carbon::setLocale(config('app.locale'));
        CarbonImmutable::setLocale(config('app.locale'));

        // Registered explicitly so the event-to-listener map is readable in
        // one place. The methods are named recordX rather than handleX on
        // purpose: Laravel's auto-discovery matches any `handle*` method, and
        // would otherwise register each of these a second time, writing every
        // activity row twice.
        Event::listen(Login::class, [LogAuthenticationActivity::class, 'recordLogin']);
        Event::listen(Logout::class, [LogAuthenticationActivity::class, 'recordLogout']);
        Event::listen(Failed::class, [LogAuthenticationActivity::class, 'recordFailed']);
        Event::listen(Lockout::class, [LogAuthenticationActivity::class, 'recordLockout']);

        Event::listen(RoleAttachedEvent::class, [LogAuthorizationChanges::class, 'recordRoleAttached']);
        Event::listen(RoleDetachedEvent::class, [LogAuthorizationChanges::class, 'recordRoleDetached']);
        Event::listen(PermissionAttachedEvent::class, [LogAuthorizationChanges::class, 'recordPermissionAttached']);
        Event::listen(PermissionDetachedEvent::class, [LogAuthorizationChanges::class, 'recordPermissionDetached']);

        // super-admin passes every ability check. Returning null rather than
        // false for everyone else is required: false here would short-circuit
        // the gate and deny permissions the user legitimately holds.
        Gate::before(fn (User $user) => $user->hasRole(Role::SuperAdmin->value) ? true : null);
    }
}
