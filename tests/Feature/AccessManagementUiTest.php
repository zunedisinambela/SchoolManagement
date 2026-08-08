<?php

namespace Tests\Feature;

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Filament\Resources\Permissions\Pages\ListPermissions;
use App\Filament\Resources\Permissions\PermissionResource;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AccessManagementUiTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin);

        return $admin;
    }

    // -----------------------------------------------------------------
    // Access control on the pages themselves
    // -----------------------------------------------------------------

    public function test_the_pages_are_closed_to_a_user_without_the_permissions(): void
    {
        $this->actingAs(User::factory()->withPermissions(PermissionEnum::AksesPanelAdmin)->create());

        $this->assertFalse(UserResource::canAccess());
        $this->assertFalse(RoleResource::canAccess());
        $this->assertFalse(PermissionResource::canAccess());

        $this->get(UserResource::getUrl('index'))->assertForbidden();
        $this->get(RoleResource::getUrl('index'))->assertForbidden();
        $this->get(PermissionResource::getUrl('index'))->assertForbidden();
    }

    public function test_the_two_permissions_open_their_own_pages_only(): void
    {
        $this->actingAs(User::factory()->withPermissions([
            PermissionEnum::AksesPanelAdmin,
            PermissionEnum::KelolaPengguna,
        ])->create());

        $this->assertTrue(UserResource::canAccess());
        $this->assertFalse(RoleResource::canAccess());

        $this->get(UserResource::getUrl('index'))->assertSuccessful();
        $this->get(RoleResource::getUrl('index'))->assertForbidden();
    }

    public function test_all_pages_render_for_a_super_admin(): void
    {
        $this->admin();
        $this->seed(RolePermissionSeeder::class);

        Livewire::test(ListUsers::class)->assertSuccessful();
        Livewire::test(ListRoles::class)->assertSuccessful();
        Livewire::test(ListPermissions::class)->assertSuccessful();
        Livewire::test(CreateUser::class)->assertSuccessful();
        Livewire::test(CreateRole::class)->assertSuccessful();
    }

    // -----------------------------------------------------------------
    // Managing users
    // -----------------------------------------------------------------

    public function test_creating_a_user_hashes_the_password_and_assigns_roles(): void
    {
        $this->admin();
        $role = Role::findOrCreate('guru', 'web');

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Budi',
                'email' => 'budi@sekolah.test',
                'password' => 'rahasia-panjang',
                'password_confirmation' => 'rahasia-panjang',
                'roles' => [$role->getKey()],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $budi = User::where('email', 'budi@sekolah.test')->firstOrFail();

        $this->assertTrue($budi->hasRole('guru'));
        $this->assertNotSame('rahasia-panjang', $budi->password);
        $this->assertTrue(Hash::check('rahasia-panjang', $budi->password));
    }

    public function test_an_empty_password_on_edit_leaves_the_existing_one_alone(): void
    {
        $this->admin();
        $user = User::factory()->create();
        $original = $user->password;

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm(['name' => 'Nama Baru', 'password' => '', 'password_confirmation' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();

        $this->assertSame('Nama Baru', $user->name);
        $this->assertSame($original, $user->password);
    }

    public function test_the_last_super_admin_cannot_have_the_role_removed(): void
    {
        $admin = $this->admin();

        $this->assertSame(1, User::role(RoleEnum::SuperAdmin->value)->count());

        Livewire::test(EditUser::class, ['record' => $admin->getKey()])
            ->fillForm(['roles' => []])
            ->call('save')
            ->assertHasFormErrors(['roles']);

        $this->assertTrue($admin->fresh()->hasRole(RoleEnum::SuperAdmin->value));
    }

    public function test_the_role_can_be_removed_once_another_super_admin_exists(): void
    {
        $admin = $this->admin();
        User::factory()->superAdmin()->create();

        Livewire::test(EditUser::class, ['record' => $admin->getKey()])
            ->fillForm(['roles' => []])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($admin->fresh()->hasRole(RoleEnum::SuperAdmin->value));
    }

    public function test_you_cannot_delete_yourself_or_the_last_super_admin(): void
    {
        $admin = $this->admin();

        $this->assertFalse(UserResource::canDelete($admin));
        $this->assertFalse(UserResource::canDeleteAny());

        $other = User::factory()->superAdmin()->create();
        $this->assertTrue(UserResource::canDelete($other), 'A second super-admin should be deletable.');

        $other->removeRole(RoleEnum::SuperAdmin->value);
        $this->assertFalse(UserResource::canDelete($admin), 'The remaining super-admin must stay.');
    }

    // -----------------------------------------------------------------
    // Managing roles
    // -----------------------------------------------------------------

    public function test_creating_a_role_attaches_the_ticked_permissions(): void
    {
        $this->admin();
        $this->seed(RolePermissionSeeder::class);

        $permission = Permission::where('name', PermissionEnum::LihatLogAktivitas->value)->firstOrFail();

        Livewire::test(CreateRole::class)
            ->fillForm([
                'name' => 'Wali Kelas',
                'permissions' => [$permission->getKey()],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        // The name is slugged so it stays usable as a stable identifier.
        $role = Role::where('name', 'wali-kelas')->firstOrFail();

        $this->assertSame('web', $role->guard_name);
        $this->assertTrue($role->hasPermissionTo(PermissionEnum::LihatLogAktivitas->value));
    }

    public function test_the_super_admin_role_is_locked(): void
    {
        $this->admin();
        $role = Role::findOrCreate(RoleEnum::SuperAdmin->value, 'web');

        $this->assertFalse(RoleResource::canEdit($role));
        $this->assertFalse(RoleResource::canDelete($role));

        $this->get(RoleResource::getUrl('edit', ['record' => $role]))->assertForbidden();
    }

    public function test_an_ordinary_role_can_still_be_edited(): void
    {
        $this->admin();
        $role = Role::findOrCreate('guru', 'web');

        $this->assertTrue(RoleResource::canEdit($role));

        Livewire::test(EditRole::class, ['record' => $role->getKey()])
            ->assertSuccessful();
    }

    // -----------------------------------------------------------------
    // Permissions are a read-only catalogue
    // -----------------------------------------------------------------

    public function test_permissions_cannot_be_created_or_edited_through_the_ui(): void
    {
        $this->admin();
        $permission = Permission::findOrCreate(PermissionEnum::KelolaRole->value, 'web');

        $this->assertFalse(PermissionResource::canCreate());
        $this->assertFalse(PermissionResource::canEdit($permission));
        $this->assertFalse(PermissionResource::canDelete($permission));
        $this->assertSame(['index'], array_keys(PermissionResource::getPages()));
    }

    // -----------------------------------------------------------------
    // Authorisation changes reach the audit log
    // -----------------------------------------------------------------

    public function test_granting_a_role_is_recorded_in_the_activity_log(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        // Created before the log is cleared: creating a role is itself an
        // `otorisasi` record now, and this test is about the grant.
        $role = Role::findOrCreate('guru', 'web');

        Activity::query()->delete();

        $user->assignRole($role);

        $records = Activity::query()->inLog('otorisasi')->get();

        // One row, not two. See the note in ActivityLogTest about `handle*`
        // methods being picked up by listener auto-discovery.
        $this->assertCount(1, $records, 'Assigning a role should produce exactly one activity record.');

        $activity = $records->first();
        $this->assertSame('role-diberikan', $activity->event);
        $this->assertSame(['guru'], $activity->getProperty('role'));
        $this->assertTrue($activity->subject->is($user));
        $this->assertTrue($activity->causer->is($admin));
    }

    public function test_revoking_a_role_is_recorded_in_the_activity_log(): void
    {
        $this->admin();
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('guru', 'web'));

        Activity::query()->delete();

        $user->removeRole('guru');

        $records = Activity::query()->inLog('otorisasi')->get();

        $this->assertCount(1, $records, 'Removing a role should produce exactly one activity record.');

        $activity = $records->first();
        $this->assertSame('role-dicabut', $activity->event);
        $this->assertSame(['guru'], $activity->getProperty('role'));
    }

    public function test_creating_renaming_and_deleting_a_role_is_recorded(): void
    {
        $this->admin();

        Activity::query()->delete();

        $role = Role::create(['name' => 'wali-kelas', 'guard_name' => 'web']);
        $role->update(['name' => 'wali-murid']);
        $role->delete();

        $events = Activity::query()->inLog('otorisasi')->orderBy('id')->get();

        $this->assertSame(['created', 'updated', 'deleted'], $events->pluck('event')->all());

        $rename = $events->firstWhere('event', 'updated');
        $this->assertSame('wali-kelas', $rename->attribute_changes['old']['name']);
        $this->assertSame('wali-murid', $rename->attribute_changes['attributes']['name']);
    }

    public function test_deleting_a_permission_is_recorded(): void
    {
        $this->admin();
        $permission = Permission::findOrCreate('coba.lihat', 'web');

        Activity::query()->delete();

        $permission->delete();

        $activity = Activity::query()->inLog('otorisasi')->latest('id')->first();

        $this->assertNotNull($activity, 'Deleting a permission produced no activity record.');
        $this->assertSame('deleted', $activity->event);

        // A delete records what was there under `old`; there is no new side.
        $this->assertSame('coba.lihat', $activity->attribute_changes['old']['name']);
    }

    /**
     * The package must resolve the subclasses, otherwise role CRUD is written
     * by Spatie's own models and never reaches the log.
     */
    public function test_the_package_resolves_the_app_models(): void
    {
        $this->assertSame(Role::class, config('permission.models.role'));
        $this->assertSame(Permission::class, config('permission.models.permission'));

        $user = User::factory()->superAdmin()->create();

        $this->assertInstanceOf(Role::class, $user->roles->first());
    }

    public function test_granting_a_permission_to_a_role_is_recorded(): void
    {
        $this->admin();
        $this->seed(RolePermissionSeeder::class);

        $role = Role::findOrCreate('guru', 'web');

        Activity::query()->delete();

        $role->givePermissionTo(PermissionEnum::LihatLogAktivitas->value);

        $records = Activity::query()->inLog('otorisasi')->get();

        $this->assertCount(1, $records, 'Granting a permission should produce exactly one activity record.');

        $activity = $records->first();
        $this->assertSame('izin-diberikan', $activity->event);
        $this->assertSame([PermissionEnum::LihatLogAktivitas->value], $activity->getProperty('izin'));
    }
}
