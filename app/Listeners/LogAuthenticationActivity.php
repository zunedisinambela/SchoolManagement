<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;

class LogAuthenticationActivity
{
    public function __construct(protected Request $request) {}

    public function recordLogin(Login $event): void
    {
        activity('auth')
            ->causedBy($event->user)
            ->performedOn($event->user)
            ->event('login')
            ->withProperties($this->context())
            ->log('Masuk');
    }

    public function recordLogout(Logout $event): void
    {
        if (! $event->user) {
            return;
        }

        activity('auth')
            ->causedBy($event->user)
            ->performedOn($event->user)
            ->event('logout')
            ->withProperties($this->context())
            ->log('Keluar');
    }

    /**
     * A failed attempt has no authenticated causer, so the attempted
     * identifier is stored as a property instead. The submitted password is
     * never read off $event->credentials.
     */
    public function recordFailed(Failed $event): void
    {
        activity('auth')
            ->causedBy($event->user)
            ->event('failed')
            ->withProperties($this->context([
                'email' => $event->credentials['email'] ?? null,
            ]))
            ->log('Gagal masuk');
    }

    public function recordLockout(Lockout $event): void
    {
        activity('auth')
            ->event('lockout')
            ->withProperties($this->context([
                'email' => $event->request->input('email'),
            ]))
            ->log('Terkunci karena terlalu banyak percobaan');
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function context(array $extra = []): array
    {
        return [
            'ip' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
            ...$extra,
        ];
    }
}
