<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Order matters: AdminUserSeeder assigns the role that
        // RolePermissionSeeder creates.
        $this->call([
            RolePermissionSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
