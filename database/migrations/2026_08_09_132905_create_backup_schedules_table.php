<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Holds the user-editable schedule for `backup:run`.
 *
 * A single-row settings table. It is not seeded here: App\Models\BackupSchedule
 * creates the row on first read with the weekly default, which keeps a fresh
 * install and an existing one on the same path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('frequency')->default('mingguan');

            // 0 = Sunday, matching cron's day-of-week numbering. Null unless
            // the frequency is weekly.
            $table->unsignedTinyInteger('day_of_week')->nullable();

            // Capped at 28 in the model: the 29th-31st do not exist in every
            // month, and a backup that skips February is worse than one a few
            // days early.
            $table->unsignedTinyInteger('day_of_month')->nullable();

            $table->unsignedTinyInteger('hour')->default(1);
            $table->unsignedTinyInteger('minute')->default(30);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_schedules');
    }
};
