<?php

namespace Tests\Feature;

use App\Enums\BackupFrequency;
use App\Filament\Pages\Backups;
use App\Models\BackupSchedule;
use App\Models\User;
use App\Support\Backup\MaximumAgeMatchingSchedule;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class BackupScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('backup.backup.destination.disks')[0]);
    }

    protected function admin(): User
    {
        $user = User::factory()
            ->withPermissions(['Access:AdminPanel', 'View:Backups'])
            ->create();

        $this->actingAs($user);

        return $user;
    }

    /**
     * Created on first read rather than seeded, so a fresh install and an
     * existing one take the same path.
     */
    public function test_the_default_schedule_is_weekly(): void
    {
        $schedule = BackupSchedule::current();

        $this->assertSame(BackupFrequency::Mingguan, $schedule->frequency);
        $this->assertTrue($schedule->is_enabled);
        $this->assertSame('30 1 * * 0', $schedule->cronExpression());
        $this->assertSame(1, BackupSchedule::count());
    }

    public function test_reading_the_schedule_twice_does_not_create_a_second_row(): void
    {
        BackupSchedule::current();
        BackupSchedule::current();

        $this->assertSame(1, BackupSchedule::count());
    }

    /**
     * @return array<string, array{BackupFrequency, array<string, mixed>, string}>
     */
    public static function cronProvider(): array
    {
        return [
            'harian' => [BackupFrequency::Harian, ['hour' => 3, 'minute' => 15], '15 3 * * *'],
            'mingguan' => [BackupFrequency::Mingguan, ['hour' => 1, 'minute' => 30, 'day_of_week' => 5], '30 1 * * 5'],
            'bulanan' => [BackupFrequency::Bulanan, ['hour' => 23, 'minute' => 0, 'day_of_month' => 28], '0 23 28 * *'],
        ];
    }

    #[DataProvider('cronProvider')]
    public function test_each_frequency_builds_its_cron_expression(BackupFrequency $frequency, array $attributes, string $expected): void
    {
        $schedule = BackupSchedule::current();
        $schedule->update(['frequency' => $frequency, ...$attributes]);

        $this->assertSame($expected, $schedule->refresh()->cronExpression());
    }

    /**
     * The whole point of storing the schedule: `routes/console.php` has to
     * register the command with whatever the user picked, not a fixed time.
     */
    public function test_the_scheduler_registers_the_configured_expression(): void
    {
        BackupSchedule::current()->update([
            'frequency' => BackupFrequency::Harian,
            'hour' => 4,
            'minute' => 45,
            'day_of_week' => null,
        ]);

        $this->assertSame('45 4 * * *', $this->scheduledExpressions()->get('backup:run'));
    }

    public function test_disabling_the_schedule_unregisters_the_command(): void
    {
        BackupSchedule::current()->update(['is_enabled' => false]);

        $scheduled = $this->scheduledExpressions();

        // Asserted against a schedule that is demonstrably populated, so the
        // absence below means "not registered" rather than "nothing loaded".
        $this->assertNotEmpty($scheduled);
        $this->assertFalse($scheduled->has('backup:run'));

        // Retention and monitoring are not user-editable and must keep running
        // regardless -- archives already on disk still age.
        $this->assertSame('0 1 * * *', $scheduled->get('backup:clean'));
        $this->assertSame('0 7 * * *', $scheduled->get('backup:monitor'));
    }

    /**
     * routes/console.php runs on every artisan invocation, including `migrate`
     * on a database where this table does not exist yet. Without the guard,
     * reading the schedule would fail before the migration that creates it
     * could run -- a fresh install could not boot far enough to fix itself.
     */
    public function test_a_missing_table_does_not_break_the_console_routes(): void
    {
        Schema::drop('backup_schedules');

        $scheduled = $this->scheduledExpressions();

        $this->assertFalse($scheduled->has('backup:run'));

        // The rest of the file still registered, so the failure was contained
        // to the guarded block rather than aborting the whole file.
        $this->assertSame('0 1 * * *', $scheduled->get('backup:clean'));
        $this->assertSame('0 7 * * *', $scheduled->get('backup:monitor'));
    }

    public function test_the_form_saves_a_new_schedule_and_records_it(): void
    {
        $admin = $this->admin();

        Livewire::test(Backups::class)
            ->callAction('ubahJadwal', [
                'is_enabled' => true,
                'frequency' => BackupFrequency::Harian->value,
                'day_of_week' => 0,
                'day_of_month' => 1,
                'time' => '05:00',
            ]);

        $schedule = BackupSchedule::current()->refresh();

        $this->assertSame(BackupFrequency::Harian, $schedule->frequency);
        $this->assertSame('0 5 * * *', $schedule->cronExpression());

        $activity = Activity::query()->where('log_name', 'backup')->latest('id')->first();

        $this->assertSame('updated', $activity->event);
        $this->assertTrue($activity->causer->is($admin));
    }

    /**
     * A day_of_week left over from a weekly schedule would show the wrong
     * value the next time the form is opened on a monthly one.
     */
    public function test_switching_frequency_clears_the_unused_day_fields(): void
    {
        $this->admin();

        BackupSchedule::current()->update([
            'frequency' => BackupFrequency::Mingguan,
            'day_of_week' => 3,
        ]);

        Livewire::test(Backups::class)
            ->callAction('ubahJadwal', [
                'is_enabled' => true,
                'frequency' => BackupFrequency::Bulanan->value,
                'day_of_week' => 3,
                'day_of_month' => 15,
                'time' => '02:00',
            ]);

        $schedule = BackupSchedule::current()->refresh();

        $this->assertNull($schedule->day_of_week);
        $this->assertSame(15, $schedule->day_of_month);
        $this->assertSame('0 2 15 * *', $schedule->cronExpression());
    }

    /**
     * The package default of 1 day is only correct while backups run daily.
     * Left alone, a weekly schedule would report "unhealthy" every day from
     * day two -- daily false alarms, which is what trains people to stop
     * reading backup notifications at all.
     */
    public function test_the_health_check_threshold_follows_the_frequency(): void
    {
        $schedule = BackupSchedule::current();

        $schedule->update(['frequency' => BackupFrequency::Harian]);
        $this->assertSame(2, $this->allowedAgeInDays());

        $schedule->update(['frequency' => BackupFrequency::Mingguan]);
        $this->assertSame(8, $this->allowedAgeInDays());

        $schedule->update(['frequency' => BackupFrequency::Bulanan]);
        $this->assertSame(32, $this->allowedAgeInDays());
    }

    public function test_the_health_check_stops_complaining_when_backups_are_disabled(): void
    {
        BackupSchedule::current()->update(['is_enabled' => false]);

        $this->assertGreaterThan(365, $this->allowedAgeInDays());
    }

    /**
     * config/backup.php must point at the schedule-aware check. Falling back to
     * the package's MaximumAgeInDays would restore the false-alarm behaviour
     * without any test failing elsewhere.
     */
    public function test_the_monitor_uses_the_schedule_aware_health_check(): void
    {
        $this->assertContains(
            MaximumAgeMatchingSchedule::class,
            config('backup.monitor_backups.0.health_checks'),
        );
    }

    protected function allowedAgeInDays(): int
    {
        $check = new MaximumAgeMatchingSchedule;

        return (new \ReflectionProperty($check, 'days'))->getValue($check);
    }

    /**
     * Re-run routes/console.php against the current database and return every
     * scheduled command, keyed by name.
     *
     * Two things make this necessary rather than just reading the Schedule.
     *
     * First, routes/console.php is evaluated during boot -- before
     * RefreshDatabase has migrated -- so `backup_schedules` does not exist yet
     * and the QueryException guard skips `backup:run` entirely. Reading the
     * boot-time Schedule would show it missing no matter what the test set,
     * making every assertion pass or fail for the wrong reason.
     *
     * Second, forgetting the container binding is not enough: the Schedule
     * facade caches its own instance, so `Schedule::command()` inside the file
     * would keep writing to the old object while `app(Schedule::class)`
     * returned a new, empty one. Both caches have to be cleared.
     *
     * @return Collection<string, string>
     */
    protected function scheduledExpressions(): Collection
    {
        $this->app->forgetInstance(Schedule::class);
        Facade::clearResolvedInstance(Schedule::class);

        require base_path('routes/console.php');

        return collect(app(Schedule::class)->events())
            ->mapWithKeys(fn ($event): array => [
                str($event->command)->after('artisan\' ')->trim("'")->value() => $event->expression,
            ]);
    }
}
