<?php

namespace App\Support\Backup;

use App\Enums\BackupFrequency;
use App\Models\BackupSchedule;
use Illuminate\Database\QueryException;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;

/**
 * "Is the newest archive too old?", with the threshold derived from the
 * schedule instead of hard-coded.
 *
 * The package's MaximumAgeInDays defaults to 1 day, which is correct only
 * while backups run daily. The schedule is user-editable now, so a hard-coded
 * threshold would go wrong the moment someone picks weekly: `backup:monitor`
 * would report "unhealthy" every single day from day two onward. Daily false
 * alarms are precisely what trains people to ignore backup notifications --
 * the same reasoning that silenced the success notifications in
 * config/backup.php.
 *
 * Thresholds are one interval plus a full grace interval, so a single missed
 * run is tolerated and two in a row are not.
 *
 * Instantiated by BackupDestinationStatusFactory at `backup:monitor` time, not
 * when config is loaded, so reading the database here is safe and survives
 * `php artisan config:cache` -- only the class name is ever cached.
 */
class MaximumAgeMatchingSchedule extends MaximumAgeInDays
{
    /**
     * Effectively "never too old". Used when automatic backups are switched
     * off: the user turned the schedule off deliberately, and nagging daily
     * about an archive they chose to stop refreshing is noise, not a warning.
     */
    protected const NEVER = 3650;

    public function __construct()
    {
        parent::__construct(static::allowedAgeInDays());
    }

    protected static function allowedAgeInDays(): int
    {
        try {
            $schedule = BackupSchedule::current();
        } catch (QueryException) {
            // Table not migrated yet. Fall back to the weekly default rather
            // than the package's 1 day, which would alarm immediately.
            return 8;
        }

        if (! $schedule->is_enabled) {
            return static::NEVER;
        }

        return match ($schedule->frequency) {
            BackupFrequency::Harian => 2,
            BackupFrequency::Mingguan => 8,
            BackupFrequency::Bulanan => 32,
        };
    }
}
