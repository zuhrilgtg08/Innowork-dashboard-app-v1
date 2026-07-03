<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Apply persisted runtime settings (skip during install/migrate when the
        // table may not exist yet, and in console to avoid DB hits on artisan boot).
        if (! $this->app->runningInConsole() && Schema::hasTable('settings')) {
            $setting = Setting::current();
            config(['app.name' => $setting->app_name, 'app.timezone' => $setting->timezone]);
            date_default_timezone_set($setting->timezone);
        }
    }
}
