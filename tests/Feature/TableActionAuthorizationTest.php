<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\Pages\ViewRole;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Filament actions carry no automatic policy check -- see the note in
 * Filament\Actions\Concerns\CanBeAuthorized: "Actions do not have automatic
 * policy-based authorization. Authorization defaults to null (allowed for all
 * users)."
 *
 * A resource's canDelete() therefore guards the record *page* but not the row
 * button, so every guarded action has to be gated on the action itself. These
 * tests call the buttons rather than the can* methods, which is the only way
 * to tell the difference.
 */
class TableActionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin);

        return $admin;
    }

    public function test_the_delete_button_refuses_to_delete_your_own_account(): void
    {
        $admin = $this->admin();

        Livewire::test(ListUsers::class)
            ->callTableAction('delete', $admin);

        $this->assertModelExists($admin);
    }

    public function test_the_delete_button_refuses_to_delete_the_last_super_admin(): void
    {
        $this->admin();

        $other = User::factory()->superAdmin()->create();
        $this->actingAs($other);

        // Two super-admins: deleting one is allowed.
        $first = User::role(RoleEnum::SuperAdmin->value)->whereKeyNot($other->getKey())->firstOrFail();

        Livewire::test(ListUsers::class)->callTableAction('delete', $first);

        $this->assertModelMissing($first);
        $this->assertSame(1, User::role(RoleEnum::SuperAdmin->value)->count());

        // Now $other is the last one and must survive its own delete button.
        Livewire::test(ListUsers::class)->callTableAction('delete', $other);

        $this->assertModelExists($other);
    }

    public function test_an_ordinary_user_can_still_be_deleted(): void
    {
        $this->admin();
        $victim = User::factory()->create();

        Livewire::test(ListUsers::class)->callTableAction('delete', $victim);

        $this->assertModelMissing($victim);
    }

    public function test_the_delete_button_refuses_to_delete_the_super_admin_role(): void
    {
        $this->admin();
        $role = Role::findOrCreate(RoleEnum::SuperAdmin->value, 'web');

        Livewire::test(ListRoles::class)->callTableAction('delete', $role);

        $this->assertModelExists($role);
    }

    public function test_an_ordinary_role_can_still_be_deleted(): void
    {
        $this->admin();
        $role = Role::findOrCreate('guru', 'web');

        Livewire::test(ListRoles::class)->callTableAction('delete', $role);

        $this->assertModelMissing($role);
    }

    /**
     * Shown but greyed out, with a tooltip saying why -- a button that simply
     * vanishes reads as a bug, and this one used to lead to a bare 403.
     */
    public function test_the_locked_buttons_are_disabled_rather_than_missing(): void
    {
        $admin = $this->admin();
        $superAdminRole = Role::findOrCreate(RoleEnum::SuperAdmin->value, 'web');

        Livewire::test(ListUsers::class)
            ->assertTableActionVisible('delete', $admin)
            ->assertTableActionDisabled('delete', $admin);

        Livewire::test(ListRoles::class)
            ->assertTableActionDisabled('edit', $superAdminRole)
            ->assertTableActionDisabled('delete', $superAdminRole);
    }

    public function test_the_buttons_stay_enabled_where_the_action_is_allowed(): void
    {
        $this->admin();
        $other = User::factory()->create();
        $guru = Role::findOrCreate('guru', 'web');

        Livewire::test(ListUsers::class)->assertTableActionEnabled('delete', $other);

        Livewire::test(ListRoles::class)
            ->assertTableActionEnabled('edit', $guru)
            ->assertTableActionEnabled('delete', $guru);
    }

    /**
     * `shield:publish` writes the role pages' header actions as `getActions()`,
     * a hook Filament 5 no longer calls, so the Delete and Edit buttons render
     * nowhere at all -- no error, just a page with no actions. Re-running
     * `shield:publish` silently reverts the rename.
     *
     * Asserted on an ordinary role on purpose. For super-admin these pages
     * never open in the first place: canEdit() is false, so Filament aborts
     * before any action exists. The `disabled()` guards on those buttons are
     * therefore a second line behind a page guard that already holds -- kept
     * because canEdit() is one edit away from being relaxed, but there is no
     * state in which they can be exercised today, and a test pretending
     * otherwise would assert nothing.
     */
    public function test_the_role_pages_still_render_their_header_actions(): void
    {
        $this->admin();
        $guru = Role::findOrCreate('guru', 'web');

        Livewire::test(EditRole::class, ['record' => $guru->getKey()])
            ->assertActionVisible('delete');

        Livewire::test(ViewRole::class, ['record' => $guru->getKey()])
            ->assertActionVisible('edit');
    }
}
