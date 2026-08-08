<?php

namespace Tests\Feature;

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Filament\Resources\Activities\ActivityResource;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_role_grants_its_permissions_to_the_user(): void
    {
        $role = Role::create(['name' => 'guru']);
        $role->givePermissionTo(Permission::create(['name' => 'siswa.lihat']));

        $user = User::factory()->create();
        $user->assignRole($role);

        $this->assertTrue($user->hasRole('guru'));
        $this->assertTrue($user->can('siswa.lihat'));
        $this->assertFalse($user->can('siswa.hapus'));
    }

    /**
     * Roles default to the `web` guard. The admin panel authenticates on that
     * same guard, so a mismatch here would make every permission check on a
     * panel page silently fail.
     */
    public function test_roles_use_the_same_guard_as_the_admin_panel(): void
    {
        $this->assertSame('web', config('auth.defaults.guard'));
        $this->assertSame('web', Role::create(['name' => 'guru'])->guard_name);
    }

    /**
     * The Gate::before hook in AppServiceProvider, not an explicit grant.
     */
    public function test_super_admin_passes_every_ability_check(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->assertTrue($user->can(PermissionEnum::AksesPanelAdmin->value));
        $this->assertTrue($user->can(PermissionEnum::LihatLogAktivitas->value));
        $this->assertTrue($user->can('kemampuan-yang-belum-pernah-dibuat'));
    }

    /**
     * The point of moving off is_admin: panel access and log access are now
     * separable, so a user can be let into the panel without being handed the
     * audit trail.
     */
    public function test_panel_access_and_log_access_are_independent(): void
    {
        $user = User::factory()
            ->withPermissions(PermissionEnum::AksesPanelAdmin)
            ->create();

        $this->actingAs($user);

        $this->get('/admin')->assertSuccessful();
        $this->assertFalse(ActivityResource::canAccess());
        $this->get(ActivityResource::getUrl('index'))->assertForbidden();
    }

    public function test_granting_the_log_permission_opens_the_resource(): void
    {
        $user = User::factory()
            ->withPermissions([PermissionEnum::AksesPanelAdmin, PermissionEnum::LihatLogAktivitas])
            ->create();

        $this->actingAs($user);

        $this->assertTrue(ActivityResource::canAccess());
        $this->get(ActivityResource::getUrl('index'))->assertSuccessful();
    }

    public function test_every_enum_permission_is_seeded(): void
    {
        $this->seed(RolePermissionSeeder::class);

        foreach (PermissionEnum::cases() as $permission) {
            $this->assertDatabaseHas('permissions', [
                'name' => $permission->value,
                'guard_name' => 'web',
            ]);
        }

        $this->assertDatabaseHas('roles', [
            'name' => RoleEnum::SuperAdmin->value,
            'guard_name' => 'web',
        ]);
    }

    public function test_the_seeded_admin_holds_the_super_admin_role(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', 'admin@admin.com')->firstOrFail();

        $this->assertTrue($admin->hasRole(RoleEnum::SuperAdmin->value));
        $this->assertTrue($admin->canAccessPanel(Filament::getPanel('admin')));
    }

    /**
     * Running the seeders twice must not duplicate rows or drop the role.
     */
    public function test_the_seeders_are_idempotent(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(AdminUserSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(AdminUserSeeder::class);

        $this->assertSame(1, Role::where('name', RoleEnum::SuperAdmin->value)->count());
        $this->assertSame(count(PermissionEnum::cases()), Permission::count());
        $this->assertSame(1, User::where('email', 'admin@admin.com')->count());
        $this->assertTrue(
            User::where('email', 'admin@admin.com')->firstOrFail()->hasRole(RoleEnum::SuperAdmin->value),
        );
    }
}
