<?php

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
