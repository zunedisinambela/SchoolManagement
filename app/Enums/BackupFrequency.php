<?php

namespace App\Enums;

/**
 * How often `backup:run` is scheduled.
 *
 * Deliberately a closed set rather than a free-text cron field. A cron
 * expression is easy to get subtly wrong in a way nothing reports: `30 1 * * 7`
 * looks like Sunday but never fires on some implementations, and the only
 * symptom is a backup that quietly stops happening. Every case here builds an
 * expression that is valid by construction.
 */
enum BackupFrequency: string
{
    case Harian = 'harian';

    case Mingguan = 'mingguan';

    case Bulanan = 'bulanan';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])
            ->all();
    }

    public function label(): string
    {
        return match ($this) {
            self::Harian => __('Harian'),
            self::Mingguan => __('Mingguan'),
            self::Bulanan => __('Bulanan'),
        };
    }

    public function usesDayOfWeek(): bool
    {
        return $this === self::Mingguan;
    }

    public function usesDayOfMonth(): bool
    {
        return $this === self::Bulanan;
    }

    /**
     * Build the cron expression for this frequency.
     *
     * `$dayOfMonth` is capped at 28 by the caller: a backup set for the 30th
     * would silently skip February every year.
     */
    public function toCronExpression(int $hour, int $minute, ?int $dayOfWeek, ?int $dayOfMonth): string
    {
        return match ($this) {
            self::Harian => "{$minute} {$hour} * * *",
            self::Mingguan => "{$minute} {$hour} * * ".($dayOfWeek ?? 0),
            self::Bulanan => "{$minute} {$hour} ".($dayOfMonth ?? 1).' * *',
        };
    }
}
