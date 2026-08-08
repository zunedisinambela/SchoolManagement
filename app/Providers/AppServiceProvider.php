<?php

namespace App\Providers;

use App\Enums\Role;
use App\Listeners\LogAuthenticationActivity;
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

        // Listener methods are named handleX rather than handle, so Laravel's
        // listener auto-discovery does not pick them up.
        Event::listen(Login::class, [LogAuthenticationActivity::class, 'handleLogin']);
        Event::listen(Logout::class, [LogAuthenticationActivity::class, 'handleLogout']);
        Event::listen(Failed::class, [LogAuthenticationActivity::class, 'handleFailed']);
        Event::listen(Lockout::class, [LogAuthenticationActivity::class, 'handleLockout']);

        // super-admin passes every ability check. Returning null rather than
        // false for everyone else is required: false here would short-circuit
        // the gate and deny permissions the user legitimately holds.
        Gate::before(fn (User $user) => $user->hasRole(Role::SuperAdmin->value) ? true : null);
    }
}
