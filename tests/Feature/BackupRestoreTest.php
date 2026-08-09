<?php

namespace Tests\Feature;

use App\Enums\Permission as PermissionEnum;
use App\Filament\Pages\Backups;
use App\Models\User;
use App\Support\Backup\RestoreArchive;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupDestination;
use Tests\TestCase;
use ZipArchive;

/**
 * Restore is the one action here that cannot be undone by clicking something
 * else, so most of what these tests pin down is what happens when it goes
 * wrong: the live database has to survive every failure path untouched.
 *
 * The test suite runs on `:memory:`, so a fake live database file is pointed at
 * instead -- which also proves the swap follows the connection config rather
 * than assuming database/database.sqlite.
 */
class BackupRestoreTest extends TestCase
{
    use RefreshDatabase;

    protected string $disk;

    protected string $folder;

    protected string $workspace;

    protected string $liveDatabase;

    /** @var array<int, string> */
    protected array $existingSafetyCopies = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->existingSafetyCopies = File::glob(storage_path('app/pre-restore/*.sqlite'));

        $this->disk = config('backup.backup.destination.disks')[0];
        $this->folder = config('backup.backup.name');

        Storage::fake($this->disk);

        $this->workspace = storage_path('framework/testing/restore-'.uniqid());
        File::ensureDirectoryExists($this->workspace);

        // Stands in for database/database.sqlite. Nothing in these tests may
        // touch the real one.
        $this->liveDatabase = $this->workspace.'/live.sqlite';
        File::put($this->liveDatabase, 'database-yang-sedang-jalan');

        config([
            'database.connections.sqlite.database' => $this->liveDatabase,
            'backup.backup.password' => null,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);
        File::deleteDirectory(storage_path('app/restore-temp'));

        // Safety copies are the one thing RestoreArchive deliberately leaves
        // behind, so the tests have to sweep up after themselves or every run
        // drops another file into a directory the application never prunes.
        File::delete(array_diff(
            File::glob(storage_path('app/pre-restore/*.sqlite')),
            $this->existingSafetyCopies,
        ));

        parent::tearDown();
    }

    protected function restorer(): User
    {
        $user = User::factory()
            ->withPermissions([
                PermissionEnum::AksesPanelAdmin,
                PermissionEnum::KelolaBackup,
                PermissionEnum::PulihkanBackup,
            ])
            ->create();

        $this->actingAs($user);

        return $user;
    }

    /**
     * Builds a real archive in the shape spatie/laravel-backup produces.
     */
    protected function archive(string $filename, string $sql, ?string $upload = null): string
    {
        $local = $this->workspace.'/'.$filename;

        $zip = new ZipArchive;
        $zip->open($local, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('db-dumps/sqlite-sqlite-database.sql.gz', gzencode($sql));

        if ($upload !== null) {
            $zip->addFromString('storage/app/public/catatan.txt', $upload);
        }

        $zip->close();

        $path = "{$this->folder}/{$filename}";
        Storage::disk($this->disk)->put($path, File::get($local));

        return $path;
    }

    /**
     * A dump the restorer should accept: both required tables, one user.
     */
    protected function usableDump(): string
    {
        return <<<'SQL'
        CREATE TABLE IF NOT EXISTS "migrations" ("id" integer primary key autoincrement not null, "migration" varchar not null, "batch" integer not null);
        INSERT INTO migrations VALUES(1,'0001_01_01_000000_create_users_table',1);
        CREATE TABLE IF NOT EXISTS "users" ("id" integer primary key autoincrement not null, "name" varchar not null, "email" varchar not null);
        INSERT INTO users VALUES(1,'Dari Arsip','arsip@sekolah.test');
        SQL;
    }

    protected function backupObject(string $path): Backup
    {
        return collect(
            BackupDestination::create($this->disk, $this->folder)->fresh()->backups()->all()
        )->firstOrFail(fn ($backup) => $backup->path() === $path);
    }

    public function test_the_action_is_hidden_without_the_restore_permission(): void
    {
        $user = User::factory()
            ->withPermissions([PermissionEnum::AksesPanelAdmin, PermissionEnum::KelolaBackup])
            ->create();

        $this->actingAs($user);

        $path = $this->archive('2026-08-09-01-30-00.zip', $this->usableDump());

        Livewire::test(Backups::class)
            ->assertTableActionHidden('pulihkan', $path)
            ->assertTableActionVisible('unduh', $path);
    }

    public function test_the_action_is_visible_with_the_restore_permission(): void
    {
        $this->restorer();

        $path = $this->archive('2026-08-09-01-30-00.zip', $this->usableDump());

        Livewire::test(Backups::class)->assertTableActionVisible('pulihkan', $path);
    }

    /**
     * Typing the filename catches the mistake a generic confirmation cannot:
     * clicking restore on the wrong row.
     */
    public function test_a_mistyped_filename_stops_the_restore(): void
    {
        $this->restorer();

        $path = $this->archive('2026-08-09-01-30-00.zip', $this->usableDump());

        Livewire::test(Backups::class)
            ->callTableAction('pulihkan', $path, [
                'konfirmasi' => '2026-08-09-99-99-99.zip',
                'sertakan_unggahan' => false,
            ])
            ->assertHasTableActionErrors(['konfirmasi']);

        $this->assertSame('database-yang-sedang-jalan', File::get($this->liveDatabase));
    }

    public function test_it_restores_the_database_and_logs_the_user_out(): void
    {
        $user = $this->restorer();

        $path = $this->archive('2026-08-09-01-30-00.zip', $this->usableDump(), 'isi-unggahan');

        Livewire::test(Backups::class)
            ->callTableAction('pulihkan', $path, [
                'konfirmasi' => '2026-08-09-01-30-00.zip',
                'sertakan_unggahan' => true,
            ])
            ->assertRedirect(Filament::getLoginUrl());

        // The placeholder contents are gone: the file really was replaced
        // rather than written into.
        $this->assertNotSame('database-yang-sedang-jalan', File::get($this->liveDatabase));

        $pdo = new \PDO('sqlite:'.$this->liveDatabase);
        $this->assertSame(
            'arsip@sekolah.test',
            $pdo->query('SELECT email FROM users')->fetchColumn(),
        );

        $this->assertSame('isi-unggahan', File::get(storage_path('app/public/catatan.txt')));

        $activity = Activity::query()->where('event', 'backup-dipulihkan')->sole();
        $this->assertSame($user->email, $activity->properties['oleh']);
        $this->assertFileExists($activity->properties['salinan_sebelum_restore']);

        File::delete(storage_path('app/public/catatan.txt'));
    }

    /**
     * The safety copy is the only way back, so it has to exist before the swap
     * and hold what was there a moment earlier.
     */
    public function test_it_copies_the_current_database_before_replacing_it(): void
    {
        $this->restorer();

        $path = $this->archive('2026-08-09-01-30-00.zip', $this->usableDump());

        $copy = (new RestoreArchive($this->backupObject($path)))->restore(includeUploads: false);

        $this->assertSame('database-yang-sedang-jalan', File::get($copy));

        File::delete($copy);
    }

    /**
     * Without this the restore succeeds and then nobody can log in to undo it.
     */
    public function test_it_refuses_a_dump_with_no_users(): void
    {
        $this->restorer();

        $path = $this->archive('2026-08-09-01-30-00.zip', <<<'SQL'
        CREATE TABLE IF NOT EXISTS "migrations" ("id" integer primary key autoincrement not null);
        CREATE TABLE IF NOT EXISTS "users" ("id" integer primary key autoincrement not null, "email" varchar not null);
        SQL);

        $this->expectException(RuntimeException::class);

        try {
            (new RestoreArchive($this->backupObject($path)))->restore();
        } finally {
            $this->assertSame('database-yang-sedang-jalan', File::get($this->liveDatabase));
        }
    }

    public function test_it_refuses_an_archive_without_a_database_dump(): void
    {
        $this->restorer();

        $local = $this->workspace.'/kosong.zip';
        $zip = new ZipArchive;
        $zip->open($local, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('storage/app/public/catatan.txt', 'tanpa-dump');
        $zip->close();

        $path = "{$this->folder}/kosong.zip";
        Storage::disk($this->disk)->put($path, File::get($local));

        $this->expectException(RuntimeException::class);

        try {
            (new RestoreArchive($this->backupObject($path)))->restore();
        } finally {
            $this->assertSame('database-yang-sedang-jalan', File::get($this->liveDatabase));
        }
    }

    /**
     * Half-restoring a MySQL install through a SQLite-shaped code path would be
     * worse than not offering the button at all.
     */
    public function test_it_refuses_to_run_on_a_non_sqlite_connection(): void
    {
        $this->restorer();

        $path = $this->archive('2026-08-09-01-30-00.zip', $this->usableDump());

        config(['database.default' => 'mysql']);

        $this->expectException(RuntimeException::class);

        try {
            (new RestoreArchive($this->backupObject($path)))->restore();
        } finally {
            // Put back before tearDown: RefreshDatabase rolls its transaction
            // back on the default connection, and mysql is not reachable here.
            config(['database.default' => 'sqlite']);

            $this->assertSame('database-yang-sedang-jalan', File::get($this->liveDatabase));
        }
    }

    /**
     * Same guard the download and delete buttons carry: the row key comes back
     * from the browser, so it is user input and must never reach the filesystem
     * as a path.
     */
    public function test_a_forged_record_key_cannot_restore_a_file_outside_the_backup_folder(): void
    {
        $this->restorer();

        $this->archive('2026-08-09-01-30-00.zip', $this->usableDump());

        foreach (['../../../.env', '../rahasia.zip', 'rahasia.zip'] as $forged) {
            try {
                Livewire::test(Backups::class)->callTableAction('pulihkan', $forged, [
                    'konfirmasi' => basename($forged),
                    'sertakan_unggahan' => false,
                ]);

                $this->fail("Aksi pulihkan menerima kunci palsu [{$forged}].");
            } catch (ActionNotResolvableException) {
                // Expected: the key matches no archive on the disk.
            }
        }

        $this->assertSame('database-yang-sedang-jalan', File::get($this->liveDatabase));
    }
}
