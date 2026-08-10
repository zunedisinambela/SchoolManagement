<?php

namespace Tests\Feature;

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

        $this->assertTrue($user->can('Access:AdminPanel'));
        $this->assertTrue($user->can('ViewAny:Activity'));
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
            ->withPermissions('Access:AdminPanel')
            ->create();

        $this->actingAs($user);

        $this->get('/admin')->assertSuccessful();
        $this->assertFalse(ActivityResource::canAccess());
        $this->get(ActivityResource::getUrl('index'))->assertForbidden();
    }

    public function test_granting_the_log_permission_opens_the_resource(): void
    {
        $user = User::factory()
            ->withPermissions(['Access:AdminPanel', 'ViewAny:Activity'])
            ->create();

        $this->actingAs($user);

        $this->assertTrue(ActivityResource::canAccess());
        $this->get(ActivityResource::getUrl('index'))->assertSuccessful();
    }

    /**
     * The gap left behind by dropping App\Enums\Permission.
     *
     * Baseline permissions are now plain strings in App\Enums\Role, unchecked
     * by the type system. A typo, or a permission renamed by a later
     * `shield:generate`, grants nothing at all — givePermissionTo would throw
     * during seeding, but a name that merely stops matching any check fails
     * silently. This asserts every baseline string is a row shield actually
     * generated.
     */
    public function test_every_baseline_permission_exists(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $generated = Permission::where('guard_name', 'web')->pluck('name')->all();

        foreach (RoleEnum::cases() as $roleEnum) {
            foreach ($roleEnum->permissions() as $permission) {
                $this->assertContains(
                    $permission,
                    $generated,
                    "Role {$roleEnum->value} meminta izin '{$permission}' yang tidak pernah digenerate shield.",
                );
            }
        }

        $this->assertDatabaseHas('roles', [
            'name' => RoleEnum::SuperAdmin->value,
            'guard_name' => 'web',
        ]);
    }

    /**
     * Two places name the super admin: App\Enums\Role and shield's config.
     * Shield's gate reads the config; the seeder and the is_admin migration
     * read the enum. If they drift, the role that bypasses everything and the
     * role that gets seeded are two different rows, and nobody holds the gate.
     */
    public function test_the_super_admin_name_matches_shield(): void
    {
        $this->assertSame(
            RoleEnum::SuperAdmin->value,
            config('filament-shield.super_admin.name'),
        );
    }

    /**
     * `define_via_gate` false — the package default — makes shield hand super
     * admin every permission as real rows instead of installing the gate. That
     * turns it into a second `developer` and quietly removes the bypass.
     */
    public function test_shield_grants_super_admin_through_the_gate(): void
    {
        $this->assertTrue(config('filament-shield.super_admin.define_via_gate'));
        $this->assertSame('before', config('filament-shield.super_admin.intercept_gate'));
    }

    /**
     * shield's `panel_user` role would be a second way into the panel that no
     * permission check reveals — canAccessPanel() asks for a permission.
     */
    public function test_the_shield_panel_user_role_is_disabled(): void
    {
        $this->assertFalse(config('filament-shield.panel_user.enabled'));
    }

    public function test_every_enum_role_is_seeded(): void
    {
        $this->seed(RolePermissionSeeder::class);

        foreach (RoleEnum::cases() as $role) {
            $this->assertDatabaseHas('roles', [
                'name' => $role->value,
                'guard_name' => 'web',
            ]);
        }
    }

    /**
     * Checks both directions: the role holds what its map lists, and nothing
     * else. Only asserting the grants would let a stray permission through —
     * and an over-granted role is the failure that matters here.
     */
    public function test_each_role_receives_its_baseline_permissions_and_no_more(): void
    {
        $this->seed(RolePermissionSeeder::class);

        foreach (RoleEnum::cases() as $roleEnum) {
            // Excluded on purpose: Gate::before answers true for super-admin
            // before the permission table is consulted, so every check below
            // would pass regardless of what the role actually holds.
            if ($roleEnum === RoleEnum::SuperAdmin) {
                continue;
            }

            $granted = $roleEnum->permissions();

            $user = User::factory()->create();
            $user->assignRole($roleEnum->value);

            // Iterates the generated rows rather than a hand-written list, so
            // a permission added by a future `shield:generate` is checked
            // against every role the moment it exists.
            foreach (Permission::where('guard_name', 'web')->pluck('name') as $permission) {
                $this->assertSame(
                    in_array($permission, $granted, true),
                    $user->can($permission),
                    "Role {$roleEnum->value} terhadap izin {$permission}.",
                );
            }
        }
    }

    /**
     * The one distinction that makes developer a different role rather than a
     * second super-admin. It holds every permission that exists, but an ability
     * nobody granted is still denied — so a policy written for a future module
     * applies to it, and a new permission has to be handed over on purpose.
     */
    public function test_developer_holds_every_permission_without_bypassing_the_gate(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Developer->value);

        foreach (Permission::where('guard_name', 'web')->pluck('name') as $permission) {
            $this->assertTrue($user->can($permission));
        }

        $this->assertFalse($user->can('kemampuan-yang-belum-pernah-dibuat'));
    }

    /**
     * The empty permission list on super-admin is intentional, not an omission.
     * Anything seeded onto it would be dead weight that reads, in the panel, as
     * if the role were bounded by it.
     */
    public function test_super_admin_is_seeded_without_explicit_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertCount(0, Role::findByName(RoleEnum::SuperAdmin->value)->permissions);
    }

    /**
     * The seeder adds, never syncs. Deploy step 6 runs it on every release, so
     * a sync would quietly undo every authorization change made through the
     * panel since the last deploy.
     */
    public function test_reseeding_does_not_revoke_a_permission_granted_by_hand(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $murid = Role::findByName(RoleEnum::Murid->value);
        $murid->givePermissionTo('Access:AdminPanel');

        $this->seed(RolePermissionSeeder::class);

        $this->assertTrue(
            $murid->fresh()->hasPermissionTo('Access:AdminPanel'),
        );
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
     * Seeding through DatabaseSeeder, the way `migrate:fresh --seed` does.
     *
     * Every other test here calls RolePermissionSeeder directly, and that path
     * cannot reproduce this: DatabaseSeeder wraps its seeders in
     * Model::withoutEvents(), which silences the `created` event Spatie uses to
     * invalidate its permission cache. On an empty database the registrar caches
     * an empty collection during the seeder's first findOrCreate lookup, and
     * every grant afterwards fails with PermissionDoesNotExist for a permission
     * that is sitting in the table. Asserting on Permission::count() would stay
     * green through all of it -- the rows are written either way. Only a grant
     * check fails.
     */
    public function test_seeding_through_the_database_seeder_grants_role_permissions(): void
    {
        $this->seed();

        // guru, not super-admin: it holds exactly one permission as a real row,
        // so a stale cache cannot hide behind Gate::before answering true.
        $this->assertTrue(
            Role::findByName(RoleEnum::Guru->value)
                ->hasPermissionTo('Access:AdminPanel'),
        );

        $this->assertCount(
            Permission::where('guard_name', 'web')->count(),
            Role::findByName(RoleEnum::Developer->value)->permissions,
        );

        $guru = User::factory()->create();
        $guru->assignRole(RoleEnum::Guru->value);

        $this->assertTrue($guru->can('Access:AdminPanel'));
    }

    /**
     * Running the seeders twice must not duplicate rows or drop the role.
     */
    public function test_the_seeders_are_idempotent(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(AdminUserSeeder::class);

        $permissionsAfterFirstRun = Permission::count();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(AdminUserSeeder::class);

        $this->assertSame(1, Role::where('name', RoleEnum::SuperAdmin->value)->count());
        $this->assertSame(count(RoleEnum::cases()), Role::count());

        // Compared against the first run rather than against a literal: shield
        // decides how many permissions exist, and that number changes with
        // every resource added. What must not change is that a second run adds
        // none.
        $this->assertSame($permissionsAfterFirstRun, Permission::count());

        // A duplicated pivot row would not change what the role can do, so it
        // only ever surfaces as a slowly growing table.
        $this->assertCount(
            $permissionsAfterFirstRun,
            Role::findByName(RoleEnum::Developer->value)->permissions,
        );
        $this->assertSame(1, User::where('email', 'admin@admin.com')->count());
        $this->assertTrue(
            User::where('email', 'admin@admin.com')->firstOrFail()->hasRole(RoleEnum::SuperAdmin->value),
        );
    }
}
