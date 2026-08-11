<?php

namespace Tests\Feature;

use App\Filament\Resources\ActionMonitorings\ActionMonitoringResource;
use App\Filament\Resources\ActionMonitorings\Pages\ListActionMonitorings;
use App\Filament\Resources\AuthenticationMonitorings\AuthenticationMonitoringResource;
use App\Filament\Resources\AuthenticationMonitorings\Pages\ListAuthenticationMonitorings;
use App\Filament\Resources\VisitMonitorings\Pages\ListVisitMonitorings;
use App\Filament\Resources\VisitMonitorings\VisitMonitoringResource;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * binafy/laravel-user-monitoring and the panel UI built on top of it.
 *
 * The package ships its own pages at /user-monitoring/*, and they run on the
 * `web` middleware alone: no authentication, no authorization, and the delete
 * routes are just as open. Everything here exists because that default had to
 * be replaced rather than configured away.
 */
class UserMonitoringAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, array{0: string}>
     */
    public static function packageRoutes(): array
    {
        return [
            ['user-monitoring/visits-monitoring'],
            ['user-monitoring/actions-monitoring'],
            ['user-monitoring/authentications-monitoring'],
        ];
    }

    // Attribute, not the `@dataProvider` annotation: PHPUnit 12 no longer
    // reads the docblock form, and a test whose provider is ignored fails with
    // ArgumentCountError rather than being skipped.
    #[DataProvider('packageRoutes')]
    public function test_the_packages_own_pages_are_not_reachable(string $path): void
    {
        // routes/user-monitoring.php exists and is empty on purpose. Delete it
        // and the package falls back to its own route file, putting every
        // user's IP and browsing history on an anonymous URL.
        $this->get("/{$path}")->assertNotFound();
        $this->delete("/{$path}/1")->assertNotFound();
    }

    public function test_panel_access_alone_does_not_open_the_monitoring_pages(): void
    {
        $user = User::factory()->withPermissions(['Access:AdminPanel'])->create();

        $this->actingAs($user);

        Livewire::test(ListVisitMonitorings::class)->assertForbidden();
        Livewire::test(ListActionMonitorings::class)->assertForbidden();
        Livewire::test(ListAuthenticationMonitorings::class)->assertForbidden();
    }

    public function test_each_page_opens_with_its_own_permission(): void
    {
        $user = User::factory()->withPermissions([
            'Access:AdminPanel',
            'ViewAny:VisitMonitoring',
            'ViewAny:ActionMonitoring',
            'ViewAny:AuthenticationMonitoring',
        ])->create();

        $this->actingAs($user);

        Livewire::test(ListVisitMonitorings::class)->assertSuccessful();
        Livewire::test(ListActionMonitorings::class)->assertSuccessful();
        Livewire::test(ListAuthenticationMonitorings::class)->assertSuccessful();
    }

    public function test_the_monitoring_pages_are_read_only(): void
    {
        // Same reasoning as the activity log: monitoring the monitored party
        // can erase is not monitoring. Pruning happens on a schedule, not from
        // a button.
        foreach ([
            VisitMonitoringResource::class,
            ActionMonitoringResource::class,
            AuthenticationMonitoringResource::class,
        ] as $resource) {
            $this->assertFalse($resource::canCreate(), $resource);
            $this->assertFalse($resource::canDeleteAny(), $resource);
            $this->assertArrayNotHasKey('edit', $resource::getPages(), $resource);
            $this->assertArrayNotHasKey('create', $resource::getPages(), $resource);
        }
    }

    public function test_a_visit_to_a_panel_page_is_recorded(): void
    {
        $user = User::factory()->withPermissions(['Access:AdminPanel'])->create();

        $this->actingAs($user)->get('/admin')->assertSuccessful();

        $visit = DB::table('visits_monitoring')->latest('id')->first();

        $this->assertNotNull($visit, 'The visit middleware is not attached to the panel.');
        $this->assertSame($user->getKey(), $visit->user_id);
    }

    public function test_reading_the_monitoring_pages_does_not_record_visits(): void
    {
        // Without the except_pages entries every refresh of the visit list
        // would add a row to the list being refreshed.
        $user = User::factory()->withPermissions([
            'Access:AdminPanel',
            'ViewAny:VisitMonitoring',
        ])->create();

        $this->actingAs($user)->get('/admin/visit-monitorings')->assertSuccessful();

        $this->assertSame(0, DB::table('visits_monitoring')->count());
    }

    public function test_ajax_requests_are_not_recorded(): void
    {
        // Filament is Livewire: with ajax_requests on, a minute of typing in a
        // search box writes dozens of rows that all point at the same page.
        $this->assertFalse(config('user-monitoring.visit_monitoring.ajax_requests'));

        $user = User::factory()->withPermissions(['Access:AdminPanel'])->create();

        $this->actingAs($user)
            ->getJson('/admin', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertSuccessful();

        $this->assertSame(0, DB::table('visits_monitoring')->count());
    }

    public function test_logging_in_reaches_both_trails(): void
    {
        // The package duplicates the activity log's auth channel. This asserts
        // the overlap deliberately: if a later version stops writing, or the
        // activity listener is dropped, exactly one of these goes to zero and
        // the failure names which trail was lost.
        $user = User::factory()->withPermissions(['Access:AdminPanel'])->create();

        $this->actingAs($user)->get('/admin');
        auth()->logout();

        $this->assertSame(
            1,
            DB::table('authentications_monitoring')->where('action_type', 'logout')->count(),
        );
        $this->assertSame(
            1,
            Activity::query()->where('event', 'logout')->count(),
        );
    }

    public function test_the_visit_retention_command_is_scheduled(): void
    {
        // delete_days on its own deletes nothing -- the command does, and only
        // if cron reaches it.
        $this->assertGreaterThan(0, config('user-monitoring.visit_monitoring.delete_days'));

        $events = collect(app(Schedule::class)->events())
            ->map(fn ($event) => $event->command ?? '');

        $this->assertTrue(
            $events->contains(fn (string $command) => str_contains($command, 'laravel-user-monitoring:remove-visit-monitoring-records')),
            'The visit retention command is not scheduled, so visits_monitoring grows forever.',
        );
    }
}
