<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\Models\Concerns\CausesActivity;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use CausesActivity, HasFactory, HasRoles, LogsActivity, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Gate access to the Filament panels.
     *
     * Keyed on a permission rather than on a role, so a future role such as
     * `guru` can be let into the panel by granting it the permission instead
     * of by editing this model. This is also why filament-shield's own
     * `panel_user` role is disabled in config: it would be a second, invisible
     * way in that no permission check would reveal.
     *
     * `Access:AdminPanel` is a custom permission declared in
     * config/filament-shield.php, not one generated from a resource — there is
     * no model behind "the panel itself".
     *
     * Deny by default: an unrecognised panel id returns false rather than
     * inheriting the admin rule, so adding a second panel later cannot
     * silently open it to everyone.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => $this->can('Access:AdminPanel'),
            default => false,
        };
    }

    /**
     * Record create/update/delete of this model to the activity log.
     *
     * Password and remember_token are stripped globally by the
     * `default_except_attributes` key in config/activitylog.php.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('user');
    }
}
