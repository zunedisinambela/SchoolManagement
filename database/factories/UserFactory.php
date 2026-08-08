<?php

namespace Database\Factories;

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Give the user the super-admin role, creating it if the seeders have not
     * run. Gate::before grants this role every permission.
     */
    public function superAdmin(): static
    {
        return $this->afterCreating(function (User $user) {
            $user->assignRole(Role::findOrCreate(RoleEnum::SuperAdmin->value, 'web'));
        });
    }

    /**
     * Give the user exactly the listed permissions and no role.
     *
     * @param  PermissionEnum|array<int, PermissionEnum>  $permissions
     */
    public function withPermissions(PermissionEnum|array $permissions): static
    {
        $permissions = is_array($permissions) ? $permissions : [$permissions];

        return $this->afterCreating(function (User $user) use ($permissions) {
            foreach ($permissions as $permission) {
                $user->givePermissionTo(Permission::findOrCreate($permission->value, 'web'));
            }
        });
    }
}
