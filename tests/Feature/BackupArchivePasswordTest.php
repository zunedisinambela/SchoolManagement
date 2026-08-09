<?php

namespace Tests\Feature;

use App\Enums\Permission as PermissionEnum;
use App\Filament\Pages\Backups;
use App\Jobs\RunBackup;
use App\Models\BackupSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The archive password moved from `.env` into the settings row so the person
 * who has to open an archive can find out what unlocks it.
 *
 * What these tests guard is mostly invisible from the code: the password has to
 * reach *both* archive-producing paths, it must never leak into the audit log
 * that a wider group can read, and the fallback to the env value has to hold so
 * an install predating the column keeps producing archives its operator can open.
 */
class BackupArchivePasswordTest extends TestCase
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
            ->withPermissions([PermissionEnum::AksesPanelAdmin, PermissionEnum::KelolaBackup])
            ->create();

        $this->actingAs($user);

        return $user;
    }

    public function test_the_panel_password_wins_over_the_env_password(): void
    {
        config(['backup.backup.password' => 'dari-env']);

        BackupSchedule::current()->update(['archive_password' => 'dari-panel']);

        BackupSchedule::current()->applyArchivePassword();

        $this->assertSame('dari-panel', config('backup.backup.password'));
    }

    /**
     * An install created before this column exists has archives locked with
     * BACKUP_ARCHIVE_PASSWORD. Dropping that fallback would silently start
     * writing unencrypted archives on every one of them.
     */
    public function test_it_falls_back_to_the_env_password_when_none_is_set(): void
    {
        config(['backup.backup.password' => 'dari-env']);

        $schedule = BackupSchedule::current();

        $this->assertNull($schedule->archive_password);
        $this->assertSame('dari-env', $schedule->archivePassword());

        $schedule->applyArchivePassword();

        $this->assertSame('dari-env', config('backup.backup.password'));
    }

    /**
     * The queue worker boots once and keeps running, so whatever
     * routes/console.php applied at boot is stale by the time a button click
     * arrives. Without this the panel button and the scheduler would lock
     * archives with different passwords -- a difference that only surfaces
     * during a restore, which is the worst possible moment.
     */
    public function test_the_queued_job_applies_the_password_before_running_backup(): void
    {
        BackupSchedule::current()->update(['archive_password' => 'dipakai-job']);

        config(['backup.backup.password' => 'basi']);

        $seen = null;

        // Stands in for the real backup:run, which would shell out to sqlite3.
        Artisan::command('backup:run', function () use (&$seen): void {
            $seen = config('backup.backup.password');
        });

        (new RunBackup)->handle();

        $this->assertSame('dipakai-job', $seen);
    }

    public function test_the_password_is_encrypted_at_rest(): void
    {
        BackupSchedule::current()->update(['archive_password' => 'rahasia-sekali']);

        $stored = DB::table('backup_schedules')->value('archive_password');

        $this->assertNotSame('rahasia-sekali', $stored);
        $this->assertStringNotContainsString('rahasia-sekali', (string) $stored);
        $this->assertSame('rahasia-sekali', BackupSchedule::current()->archive_password);
    }

    public function test_the_form_shows_the_password_in_force(): void
    {
        $this->admin();

        BackupSchedule::current()->update(['archive_password' => 'boleh-dilihat']);

        Livewire::test(Backups::class)
            ->mountAction('passwordArsip')
            ->assertActionDataSet(['archive_password' => 'boleh-dilihat']);
    }

    public function test_it_saves_the_password_from_the_panel(): void
    {
        $this->admin();

        Livewire::test(Backups::class)
            ->callAction('passwordArsip', ['archive_password' => 'password-baru'])
            ->assertHasNoActionErrors();

        $this->assertSame('password-baru', BackupSchedule::current()->refresh()->archive_password);
    }

    public function test_a_short_password_is_rejected(): void
    {
        $this->admin();

        Livewire::test(Backups::class)
            ->callAction('passwordArsip', ['archive_password' => 'pendek'])
            ->assertHasActionErrors(['archive_password']);

        $this->assertNull(BackupSchedule::current()->refresh()->archive_password);
    }

    /**
     * activity_log is readable at /admin/activities by anyone holding
     * `lihat-log-aktivitas`, a much wider group than `kelola-backup`. Logging
     * the value there would hand the key to every archive to people who were
     * deliberately not given the backup page.
     */
    public function test_the_password_never_reaches_the_activity_log(): void
    {
        $this->admin();

        Livewire::test(Backups::class)
            ->callAction('passwordArsip', ['archive_password' => 'jangan-bocor']);

        $this->assertStringNotContainsString(
            'jangan-bocor',
            Activity::query()->get()->toJson(),
        );
    }

    public function test_changing_the_password_is_recorded(): void
    {
        $user = $this->admin();

        Livewire::test(Backups::class)
            ->callAction('passwordArsip', ['archive_password' => 'password-baru']);

        $activities = Activity::query()->where('event', 'password-arsip-diubah')->get();

        $this->assertCount(1, $activities);
        $this->assertSame('backup', $activities->first()->log_name);
        $this->assertTrue($activities->first()->causer->is($user));
    }
}
