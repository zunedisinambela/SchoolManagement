<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * opcodesio/log-viewer serves /log-viewer from its own routes, outside the
 * Filament panel. Nothing in the panel's authorization reaches it, so every
 * guard it has is one this repo installed:
 *
 *   - the `viewLogViewer` gate in AppServiceProvider, without which the page
 *     is anonymous-readable on any environment that is not production;
 *   - AuthorizeLogViewerWrites, which splits the package's single gate so that
 *     reading a log does not also mean erasing it.
 *
 * Both are invisible in the sense that matters: removing either leaves the
 * page working perfectly, only for more people than intended.
 */
class LogViewerAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function path(string $suffix = ''): string
    {
        return '/'.trim((string) config('log-viewer.route_path'), '/').$suffix;
    }

    public function test_the_log_viewer_is_closed_to_anonymous_visitors(): void
    {
        // The package only aborts on its own when the app is in production.
        // Everywhere else the gate is the only thing standing there.
        $this->assertFalse(app()->isProduction());

        $this->get($this->path())->assertForbidden();
    }

    public function test_panel_access_alone_does_not_open_the_log_viewer(): void
    {
        $user = User::factory()->withPermissions(['Access:AdminPanel'])->create();

        $this->actingAs($user)->get($this->path())->assertForbidden();
    }

    public function test_the_permission_opens_the_log_viewer(): void
    {
        $user = User::factory()->withPermissions(['View:LogViewer'])->create();

        $this->actingAs($user)->get($this->path())->assertSuccessful();
    }

    public function test_super_admin_passes_through_the_gate(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)->get($this->path())->assertSuccessful();
    }

    public function test_reading_the_logs_does_not_also_grant_erasing_them(): void
    {
        $user = User::factory()->withPermissions(['View:LogViewer'])->create();

        // Reading is fine.
        $this->actingAs($user)->getJson($this->path('/api/folders'))->assertSuccessful();

        // Erasing is not. storage/logs is in no backup archive, so this one is
        // the request with no way back.
        $this->actingAs($user)->postJson($this->path('/api/clear-cache-all'))->assertForbidden();
        $this->actingAs($user)->deleteJson($this->path('/api/files/whatever'))->assertForbidden();
    }

    public function test_the_second_permission_grants_erasing(): void
    {
        $user = User::factory()
            ->withPermissions(['View:LogViewer', 'Delete:LogFile'])
            ->create();

        $this->actingAs($user)
            ->postJson($this->path('/api/clear-cache-all'))
            ->assertSuccessful();
    }

    public function test_the_write_guard_never_runs_before_the_read_guard(): void
    {
        // Delete:LogFile on its own is not a way in: someone holding only the
        // write permission must still fail at the package's own gate.
        $user = User::factory()->withPermissions(['Delete:LogFile'])->create();

        $this->actingAs($user)
            ->postJson($this->path('/api/clear-cache-all'))
            ->assertForbidden();
    }

    public function test_both_permissions_are_declared_as_custom_permissions(): void
    {
        // They are not derived from any resource, page, or widget, so
        // shield:generate only produces them because they are listed by hand.
        $custom = config('filament-shield.custom_permissions');

        $this->assertContains('view:log_viewer', $custom);
        $this->assertContains('delete:log_file', $custom);
    }

    public function test_no_baseline_role_but_developer_receives_the_log_permissions(): void
    {
        // Logs carry request payloads, e-mail addresses, and stack traces
        // holding whatever was being processed. Same reasoning that keeps
        // View:Backups away from admin.
        foreach ([RoleEnum::Admin, RoleEnum::Guru, RoleEnum::Karyawan, RoleEnum::Murid] as $role) {
            $this->assertNotContains('View:LogViewer', $role->permissions(), $role->value);
            $this->assertNotContains('Delete:LogFile', $role->permissions(), $role->value);
        }
    }
}
