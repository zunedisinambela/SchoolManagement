<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

/**
 * Runs `backup:run` on the queue for the "Backup Sekarang" button.
 *
 * Dumping the database and zipping it takes seconds today and will take
 * longer as the school fills up. Doing that inside the web request risks a
 * PHP timeout mid-archive, which leaves a half-written file the scheduler
 * would later have to clean up. The queue has no such deadline.
 *
 * Failures are not silent: spatie/laravel-backup sends its own
 * BackupHasFailedNotification, and Laravel records the job in `failed_jobs`.
 */
class RunBackup implements ShouldQueue
{
    use Queueable;

    /**
     * Long enough for a large dump, short enough that a wedged `sqlite3`
     * process cannot occupy a worker forever.
     */
    public int $timeout = 600;

    /**
     * Deliberately no retries. A failed backup is nearly always a
     * configuration problem — a missing `sqlite3` binary, an unwritable disk —
     * and retrying just repeats the same failure while hiding it behind
     * duplicate notifications.
     */
    public int $tries = 1;

    public function handle(): void
    {
        Artisan::call('backup:run');
    }
}
