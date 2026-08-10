<?php

namespace App\Providers;

use App\Listeners\LogAuthenticationActivity;
use App\Listeners\LogAuthorizationChanges;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
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

        // The super-admin bypass used to live here as a Gate::before. It now
        // belongs to filament-shield, which installs an equivalent hook driven
        // by config `super_admin.intercept_gate`. Registering a second one
        // here would run the same check twice on every ability call.
        //
        // The subtle part of the old implementation still applies to shield's:
        // the callback must return null, not false, for everyone else —
        // returning false short-circuits the gate and denies permissions the
        // user legitimately holds. Locked by
        // `test_a_non_super_admin_keeps_its_own_permissions`.

        // Laravel discovers policies by naming convention: App\Models\Foo ->
        // App\Policies\FooPolicy. ActivityPolicy breaks that convention
        // because its model lives in the activitylog package, not in
        // App\Models, so nothing would ever consult it. This registers the
        // generated policies explicitly. `shield:generate` prints
        // "requires registration" next to exactly the ones that need it.
        FilamentShield::enforcePolicies();
    }
}
