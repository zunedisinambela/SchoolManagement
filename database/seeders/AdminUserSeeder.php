<?php

namespace Database\Seeders;

use App\Enums\Role as RoleEnum;
use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Seed the default admin account.
     *
     * Depends on RolePermissionSeeder having created the super-admin role.
     */
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'admin',
                'password' => 'admin',
            ],
        );

        $admin->syncRoles([RoleEnum::SuperAdmin->value]);
    }
}
