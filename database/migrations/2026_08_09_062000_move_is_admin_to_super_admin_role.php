<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Replaces the users.is_admin flag with the `super-admin` role.
 *
 * Table names are read from config rather than hardcoded, and rows are
 * written with the query builder rather than through Eloquent, so that a
 * later change to the User model or to Spatie's models cannot break a
 * migration that has already run elsewhere.
 */
return new class extends Migration
{
    protected const ROLE = 'super-admin';

    protected const GUARD = 'web';

    public function up(): void
    {
        $roleId = $this->ensureRoleExists();

        $adminIds = DB::table('users')->where('is_admin', true)->pluck('id');

        foreach ($adminIds as $userId) {
            DB::table($this->table('model_has_roles'))->insertOrIgnore([
                'role_id' => $roleId,
                'model_type' => 'App\Models\User',
                $this->morphKey() => $userId,
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });

        $this->flushPermissionCache();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
        });

        $roleId = DB::table($this->table('roles'))
            ->where('name', self::ROLE)
            ->where('guard_name', self::GUARD)
            ->value('id');

        if ($roleId === null) {
            return;
        }

        $userIds = DB::table($this->table('model_has_roles'))
            ->where('role_id', $roleId)
            ->where('model_type', 'App\Models\User')
            ->pluck($this->morphKey());

        DB::table('users')->whereIn('id', $userIds)->update(['is_admin' => true]);

        // The role itself is left in place: dropping it would silently revoke
        // access for anyone who was granted it after this migration ran.
    }

    protected function ensureRoleExists(): int
    {
        $roles = $this->table('roles');

        $existing = DB::table($roles)
            ->where('name', self::ROLE)
            ->where('guard_name', self::GUARD)
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table($roles)->insertGetId([
            'name' => self::ROLE,
            'guard_name' => self::GUARD,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function table(string $key): string
    {
        return config("permission.table_names.{$key}");
    }

    protected function morphKey(): string
    {
        return config('permission.column_names.model_morph_key');
    }

    protected function flushPermissionCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
