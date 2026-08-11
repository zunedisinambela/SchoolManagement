<?php

use App\Models\BackupSchedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Trim the activity log nightly. The cut-off lives in
// config/activitylog.php (`clean_after_days`, currently 365).
Schedule::command('activitylog:clean --force')
    ->dailyAt('02:00')
    ->onOneServer();

// Trim visit monitoring nightly. The cut-off lives in
// config/user-monitoring.php (`visit_monitoring.delete_days`, currently 90).
//
// Scheduled at 03:00 rather than alongside the other cleanups: it is the only
// one of the three that deletes rows written by ordinary page views, so it has
// the most to do, and it is kept clear of activitylog:clean at 02:00 so the two
// never write to database.sqlite at the same moment.
//
// The command refuses to run when `delete_days` is 0 -- it prints an error and
// returns, so setting the config back to the package default silently turns
// this schedule into a no-op that still shows up in `schedule:list`.
//
// It only touches visits. `actions_monitoring` and
// `authentications_monitoring` have no pruning command in the package at all.
Schedule::command('laravel-user-monitoring:remove-visit-monitoring-records')
    ->dailyAt('03:00')
    ->onOneServer();

// Retention runs daily regardless of how often backups are taken -- it prunes
// by age, so running it on a day with no new archive is a no-op.
//
// It is not coupled to the backup time: DefaultStrategy never deletes the
// newest archive, so the two commands are safe in either order. Kept at 01:00,
// away from activitylog:clean at 02:00, so the two never touch
// database.sqlite at the same moment.
Schedule::command('backup:clean')
    ->dailyAt('01:00')
    ->onOneServer();

// The backup schedule itself is user-editable from /admin/backups, so it is
// read from the database rather than pinned here. Default is weekly.
//
// This file is evaluated on EVERY artisan invocation, including `migrate` on a
// database where `backup_schedules` does not exist yet. Without the catch,
// reading it would fail before the migration that creates it could ever run --
// a fresh install would be unable to boot far enough to fix itself.
//
// A missing table therefore means "no schedule registered", not a crash. Once
// the migration runs, the schedule appears on the next artisan call.
try {
    $backupSchedule = BackupSchedule::current();

    // The scheduler runs `backup:run` as its own artisan process, and this file
    // is evaluated during that process's boot -- which makes it the one place
    // where the panel-configured archive password can reach a scheduled backup.
    // The row is already loaded here, so this costs no extra query.
    //
    // Applied unconditionally, not only when the schedule is enabled: a manual
    // `php artisan backup:run` from the shell has to produce an archive with
    // the same password as the automatic ones.
    $backupSchedule->applyArchivePassword();

    if ($backupSchedule->is_enabled) {
        Schedule::command('backup:run')
            ->cron($backupSchedule->cronExpression())
            ->onOneServer()
            ->withoutOverlapping();
    }
} catch (QueryException) {
    // Table not migrated yet.
}

// Checks the newest archive's age and total size against `monitor_backups` in
// config/backup.php, and notifies when it fails. Without this, a backup that
// silently stopped running looks exactly like one that is working — the only
// difference is a folder nobody opens.
Schedule::command('backup:monitor')
    ->dailyAt('07:00')
    ->onOneServer();
