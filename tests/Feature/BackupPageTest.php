<?php

namespace Tests\Feature;

use App\Filament\Pages\Backups;
use App\Jobs\RunBackup;
use App\Models\User;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class BackupPageTest extends TestCase
{
    use RefreshDatabase;

    protected string $disk;

    protected string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disk = config('backup.backup.destination.disks')[0];
        $this->folder = config('backup.backup.name');

        Storage::fake($this->disk);
    }

    protected function archive(string $filename): string
    {
        $path = "{$this->folder}/{$filename}";

        Storage::disk($this->disk)->put($path, 'arsip-palsu');

        return $path;
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
     * `kelola-backup` is separate from `akses-panel-admin` on purpose: this
     * page shows when the database was last captured and is one click from
     * handing all of it over, so panel access alone must not grant it.
     */
    public function test_panel_access_alone_does_not_open_the_backup_page(): void
    {
        $this->actingAs(
            User::factory()->withPermissions(['Access:AdminPanel'])->create(),
        );

        $this->get(Backups::getUrl())->assertForbidden();
    }

    public function test_the_permission_opens_the_backup_page(): void
    {
        $this->admin();

        $this->get(Backups::getUrl())->assertSuccessful();
    }

    public function test_the_page_lists_the_archives_on_the_disk(): void
    {
        $this->admin();

        $this->archive('2026-08-01-01-30-00.zip');
        $this->archive('2026-08-09-01-30-00.zip');

        Livewire::test(Backups::class)
            ->assertSuccessful()
            ->assertSee('2026-08-01-01-30-00.zip')
            ->assertSee('2026-08-09-01-30-00.zip');
    }

    public function test_the_backup_button_queues_the_job_and_records_it(): void
    {
        Queue::fake();

        $admin = $this->admin();

        Livewire::test(Backups::class)->callAction('backupSekarang');

        Queue::assertPushed(RunBackup::class);

        $activity = Activity::query()->where('log_name', 'backup')->sole();

        $this->assertSame('backup-dijalankan', $activity->event);
        $this->assertTrue($activity->causer->is($admin));
    }

    public function test_downloading_an_archive_is_recorded(): void
    {
        $admin = $this->admin();

        $this->archive('2026-08-09-01-30-00.zip');

        Livewire::test(Backups::class)
            ->callTableAction('unduh', "{$this->folder}/2026-08-09-01-30-00.zip")
            ->assertFileDownloaded('2026-08-09-01-30-00.zip');

        $activity = Activity::query()->where('log_name', 'backup')->sole();

        $this->assertSame('backup-diunduh', $activity->event);
        $this->assertSame('2026-08-09-01-30-00.zip', $activity->properties['berkas']);
        $this->assertTrue($activity->causer->is($admin));
    }

    /**
     * Calls the button rather than asking the guard, because a Filament action
     * without an explicit guard really does run. `disabled()` is checked
     * server-side in InteractsWithActions::callMountedAction, so a disabled
     * button refusing here is the behaviour that matters.
     */
    public function test_the_newest_archive_cannot_be_deleted(): void
    {
        $this->admin();

        $this->archive('2026-08-01-01-30-00.zip');
        $newest = $this->archive('2026-08-09-01-30-00.zip');

        Livewire::test(Backups::class)->callTableAction('hapus', $newest);

        Storage::disk($this->disk)->assertExists($newest);
    }

    public function test_an_older_archive_can_be_deleted_and_is_recorded(): void
    {
        $this->admin();

        $older = $this->archive('2026-08-01-01-30-00.zip');
        $newest = $this->archive('2026-08-09-01-30-00.zip');

        Livewire::test(Backups::class)->callTableAction('hapus', $older);

        Storage::disk($this->disk)->assertMissing($older);
        Storage::disk($this->disk)->assertExists($newest);

        $activity = Activity::query()->where('log_name', 'backup')->sole();

        $this->assertSame('backup-dihapus', $activity->event);
        $this->assertSame('2026-08-01-01-30-00.zip', $activity->properties['berkas']);
    }

    /**
     * The table's record key is the archive path, and Filament round-trips it
     * through the browser — so it comes back as user input on every action.
     *
     * If the page used that key as a path, this is where the credentials the
     * backup config works to keep out of the archives would walk straight out
     * anyway.
     *
     * Two independent layers stop it, and this test pins both. Filament
     * resolves the key against the rendered record set first and throws
     * ActionNotResolvableException when it matches nothing — which is why the
     * assertion below is on the exception rather than on a refused download.
     * Behind that, resolveBackup() compares the key against the paths the disk
     * actually reports, so the action stays safe even if it is ever reached by
     * another route.
     */
    public function test_a_forged_record_key_cannot_reach_a_file_outside_the_backup_folder(): void
    {
        $this->admin();

        $this->archive('2026-08-09-01-30-00.zip');

        Storage::disk($this->disk)->put('rahasia.zip', 'BUKAN-ARSIP-BACKUP');

        $forgedKeys = [
            'rahasia.zip',
            '../rahasia.zip',
            "../{$this->folder}/../rahasia.zip",
            '../../../.env',
        ];

        foreach ($forgedKeys as $forged) {
            foreach (['unduh', 'hapus'] as $action) {
                try {
                    Livewire::test(Backups::class)->callTableAction($action, $forged);

                    $this->fail("Aksi [{$action}] menerima kunci palsu [{$forged}].");
                } catch (ActionNotResolvableException) {
                    // Expected: the key matches no archive on the disk.
                }
            }
        }

        Storage::disk($this->disk)->assertExists('rahasia.zip');
        $this->assertSame('BUKAN-ARSIP-BACKUP', Storage::disk($this->disk)->get('rahasia.zip'));

        // Nothing was downloaded or deleted, so nothing should have been logged.
        $this->assertSame(0, Activity::query()->where('log_name', 'backup')->count());
    }
}
