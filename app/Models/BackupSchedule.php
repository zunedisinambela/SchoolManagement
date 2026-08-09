<?php

namespace App\Models;

use App\Enums\BackupFrequency;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The user-editable schedule for `backup:run`, stored as a single row.
 *
 * Only the backup itself is configurable. `backup:clean` and `backup:monitor`
 * stay pinned in routes/console.php — they are maintenance, and letting them
 * drift apart invites the setup where cleanup runs hourly while backups run
 * monthly, quietly eating every archive.
 *
 * @property BackupFrequency $frequency
 */
class BackupSchedule extends Model
{
    use LogsActivity;

    protected $fillable = [
        'frequency',
        'day_of_week',
        'day_of_month',
        'hour',
        'minute',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'frequency' => BackupFrequency::class,
            'day_of_week' => 'integer',
            'day_of_month' => 'integer',
            'hour' => 'integer',
            'minute' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * The one and only row, created with the weekly default on first read.
     *
     * Created here rather than in a seeder so a fresh install and an existing
     * one behave identically — there is no state where the panel loads but the
     * schedule row is missing.
     */
    public static function current(): self
    {
        // Wrapped in withoutEvents so creating the default row is not audited.
        // Nobody chose these values, and this runs on the first page load --
        // logged normally it would record a `created` entry attributed to
        // whoever happened to open the page, as if they had set the schedule.
        // Real edits go through update() outside this method and are logged.
        return static::withoutEvents(fn (): self => static::query()->firstOrCreate([], [
            'frequency' => BackupFrequency::Mingguan,
            'day_of_week' => 0,
            'day_of_month' => null,
            'hour' => 1,
            'minute' => 30,
            'is_enabled' => true,
        ]));
    }

    public function cronExpression(): string
    {
        return $this->frequency->toCronExpression(
            $this->hour,
            $this->minute,
            $this->day_of_week,
            $this->day_of_month,
        );
    }

    public function time(): string
    {
        return sprintf('%02d:%02d', $this->hour, $this->minute);
    }

    /**
     * @return array<int, string>
     */
    public static function dayOfWeekOptions(): array
    {
        return [
            0 => __('Minggu'),
            1 => __('Senin'),
            2 => __('Selasa'),
            3 => __('Rabu'),
            4 => __('Kamis'),
            5 => __('Jumat'),
            6 => __('Sabtu'),
        ];
    }

    public function describe(): string
    {
        if (! $this->is_enabled) {
            return __('Backup otomatis dimatikan.');
        }

        return match ($this->frequency) {
            BackupFrequency::Harian => __('Setiap hari pukul :jam.', ['jam' => $this->time()]),
            BackupFrequency::Mingguan => __('Setiap :hari pukul :jam.', [
                'hari' => static::dayOfWeekOptions()[$this->day_of_week] ?? __('Minggu'),
                'jam' => $this->time(),
            ]),
            BackupFrequency::Bulanan => __('Setiap tanggal :tanggal pukul :jam.', [
                'tanggal' => $this->day_of_month ?? 1,
                'jam' => $this->time(),
            ]),
        };
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('backup');
    }
}
