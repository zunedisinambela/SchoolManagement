<?php

namespace App\Support\Backup;

use App\Models\BackupSchedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use RuntimeException;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Puts an archive back: database first, uploaded files second.
 *
 * The whole design turns on one decision — the live database is never written
 * to. The dump is imported into a throwaway SQLite file, checked, and only then
 * moved into place with a single rename(). Everything slow happens off to the
 * side; the part that touches the running application takes milliseconds.
 *
 * That matters more than it first looks:
 *
 * - Importing straight into `database/database.sqlite` and timing out halfway
 *   leaves a database with some tables restored and some not, and no way back.
 *   Here a timeout only abandons a temp file.
 * - It also rules out running this on the queue, which would otherwise be the
 *   obvious answer for a slow job. `QUEUE_CONNECTION=database`: the worker
 *   tracks the running job in the very table being replaced, and the restored
 *   `jobs` table can carry stale jobs from the archive's era that the worker
 *   would then happily execute. RunBackup belongs on the queue; this does not.
 *
 * Only SQLite is supported. Any other driver aborts up front rather than
 * half-working -- see restore().
 */
class RestoreArchive
{
    /**
     * Entries the archive is allowed to contain. Anything else is ignored
     * rather than extracted: the zip is trusted input today, but an extractor
     * that will happily write wherever the entry name points is the kind of
     * thing that stops being safe the moment someone adds an upload button.
     */
    protected const ALLOWED_PREFIXES = ['db-dumps/', 'storage/app/public/'];

    public function __construct(protected Backup $backup) {}

    /**
     * @return string Path of the safety copy taken before the swap.
     */
    public function restore(bool $includeUploads = true): string
    {
        if (config('database.default') !== 'sqlite') {
            throw new RuntimeException(__('Restore lewat panel hanya mendukung SQLite.'));
        }

        // The import can take a while on a full database, and this runs inside
        // a web request. Safe to lift precisely because a timeout now costs
        // nothing but a temp file.
        set_time_limit(0);

        $workspace = $this->extract();

        try {
            $staged = $this->importToTemporaryDatabase($this->dumpFile($workspace));

            $this->assertUsable($staged);

            $safetyCopy = $this->takeSafetyCopy();

            $this->swapIn($staged);

            if ($includeUploads) {
                $this->restoreUploads($workspace);
            }

            $this->settleApplication();

            return $safetyCopy;
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    /**
     * Unpack the archive into a scratch directory.
     *
     * The archive is streamed to local disk first: it may live on a remote
     * destination disk, and ZipArchive needs a real path.
     */
    protected function extract(): string
    {
        $workspace = storage_path('app/restore-temp/'.uniqid());

        File::ensureDirectoryExists($workspace);

        $local = $workspace.'/arsip.zip';

        $source = $this->backup->stream();
        $target = fopen($local, 'wb');
        stream_copy_to_stream($source, $target);
        fclose($target);

        if (is_resource($source)) {
            fclose($source);
        }

        $zip = new ZipArchive;

        if ($zip->open($local) !== true) {
            throw new RuntimeException(__('Arsip tidak bisa dibuka.'));
        }

        $zip->setPassword((string) BackupSchedule::current()->archivePassword());

        $entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->statIndex($i)['name'];

            if (str_contains($name, '..') || str_starts_with($name, '/')) {
                continue;
            }

            foreach (self::ALLOWED_PREFIXES as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    $entries[] = $name;
                    break;
                }
            }
        }

        // A wrong password does not throw here; extractTo simply returns false.
        // Reported as such rather than as a generic failure, because a wrong
        // password is by far the likeliest reason to land here.
        if ($entries === [] || ! $zip->extractTo($workspace, $entries)) {
            $zip->close();

            throw new RuntimeException(__('Arsip gagal diekstrak. Password enkripsi kemungkinan tidak cocok dengan arsip ini — arsip lama tetap memakai password saat ia dibuat.'));
        }

        $zip->close();

        return $workspace;
    }

    protected function dumpFile(string $workspace): string
    {
        $dump = collect(File::glob($workspace.'/db-dumps/*'))->first();

        if (! $dump) {
            throw new RuntimeException(__('Arsip tidak berisi dump database.'));
        }

        if (! str_ends_with($dump, '.gz')) {
            return $dump;
        }

        // Decompressed in PHP rather than by shelling out to gunzip: one less
        // binary that has to exist on the server, and the pipeline below
        // already depends on sqlite3 being there.
        $plain = $workspace.'/dump.sql';

        $in = gzopen($dump, 'rb');
        $out = fopen($plain, 'wb');

        while (! gzeof($in)) {
            fwrite($out, gzread($in, 262144));
        }

        gzclose($in);
        fclose($out);

        return $plain;
    }

    /**
     * Build the replacement database beside the real one.
     *
     * Beside it on purpose: rename() is only atomic within a filesystem, and
     * `database/` is the one directory guaranteed to be on the same one as the
     * file being replaced.
     */
    protected function importToTemporaryDatabase(string $sqlFile): string
    {
        $staged = dirname($this->livePath()).'/.restore-'.uniqid().'.sqlite';

        $binary = config('database.connections.sqlite.dump.dump_binary_path', '').'sqlite3';

        $process = new Process([$binary, '-bail', $staged]);
        $process->setTimeout(null);
        $process->setInput(fopen($sqlFile, 'rb'));
        $process->run();

        if (! $process->isSuccessful()) {
            File::delete($staged);

            throw new RuntimeException(__('Impor dump gagal: :pesan', [
                'pesan' => trim($process->getErrorOutput()) ?: __('biner sqlite3 tidak ditemukan'),
            ]));
        }

        return $staged;
    }

    /**
     * Refuse to swap in something nobody can log into.
     *
     * A database without `users` rows is not recoverable through the panel --
     * there is no screen left that could create the first account. Catching it
     * here costs one query; catching it after the swap costs shell access.
     */
    protected function assertUsable(string $staged): void
    {
        try {
            $pdo = new PDO('sqlite:'.$staged);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")
                ->fetchAll(PDO::FETCH_COLUMN);

            $missing = array_diff(['users', 'migrations'], $tables);

            if ($missing !== []) {
                throw new RuntimeException(__('Dump tidak berisi tabel :tabel.', [
                    'tabel' => implode(', ', $missing),
                ]));
            }

            if ((int) $pdo->query('SELECT count(*) FROM users')->fetchColumn() === 0) {
                throw new RuntimeException(__('Dump tidak berisi satu pun pengguna — memulihkannya akan mengunci semua orang dari panel.'));
            }
        } catch (RuntimeException $e) {
            File::delete($staged);

            throw $e;
        }
    }

    protected function takeSafetyCopy(): string
    {
        $directory = storage_path('app/pre-restore');

        File::ensureDirectoryExists($directory);

        $copy = $directory.'/'.now()->format('Y-m-d-H-i-s').'.sqlite';

        File::copy($this->livePath(), $copy);

        return $copy;
    }

    /**
     * Read from the connection config rather than assumed to be
     * database/database.sqlite, so DB_DATABASE pointing somewhere else is
     * honoured instead of quietly replacing the wrong file.
     */
    protected function livePath(): string
    {
        return config('database.connections.sqlite.database');
    }

    /**
     * The swap itself. Everything before this point is reversible by doing
     * nothing; everything after it is live.
     */
    protected function swapIn(string $staged): void
    {
        $live = $this->livePath();

        // Only the connection actually pointing at this file is closed. A blunt
        // DB::disconnect() would also drop connections that have nothing to do
        // with the file being replaced -- including an in-memory one, which
        // does not survive being disconnected at all.
        $connected = DB::connection()->getDatabaseName() === $live;

        if ($connected) {
            DB::disconnect();
        }

        rename($staged, $live);

        // WAL and shared-memory files belong to the database that just went
        // away. Left behind, SQLite would try to replay them onto the restored
        // file -- reintroducing the very writes the restore was meant to undo.
        File::delete([$live.'-wal', $live.'-shm']);

        if ($connected) {
            DB::purge();
        }
    }

    protected function restoreUploads(string $workspace): void
    {
        $source = $workspace.'/storage/app/public';

        if (! File::isDirectory($source)) {
            return;
        }

        // Copied over the top rather than replacing the directory: files
        // uploaded since the archive are left alone. Restoring the database
        // already removed the rows that referenced anything else.
        File::copyDirectory($source, storage_path('app/public'));
    }

    /**
     * Bring the running application back in line with what was just restored.
     */
    protected function settleApplication(): void
    {
        // The archive carries its own `migrations` table, so this runs exactly
        // the migrations written after the archive was taken -- and nothing it
        // already contains.
        Artisan::call('migrate', ['--force' => true]);

        // `cache`, `sessions` and `jobs` all live in the database that was just
        // replaced, so the permission cache now holds role and permission ids
        // from the archive's era. Stale entries here are the usual reason a
        // restore that worked still ends with everyone locked out of /admin.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
