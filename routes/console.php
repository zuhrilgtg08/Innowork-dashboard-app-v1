<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Probe ML service + MQTT broker every 5 minutes; only logs when unhealthy so
// Logs Sistem isn't flooded (needs `php artisan schedule:work` or a cron entry).
Schedule::command('sortvision:health-check --quiet-ok')->everyFiveMinutes();
