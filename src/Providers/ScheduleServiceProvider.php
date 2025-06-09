<?php

namespace Wm\WmPackage\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\App;
use Illuminate\Support\ServiceProvider;

class ScheduleServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);

            if (App::environment('production')) {

                $schedule->command('wm:backup-run --only-db')
                    ->daily()
                    ->at('20:00');

                $schedule->command('wm:backup-run --only-files')
                    ->fridays()
                    ->at('18:00');

                $schedule->command('backup:clean')
                    ->sundays()
                    ->at('03:00');
            }
            $schedule->command('wm:download-db-backup --latest')
                ->daily()
                ->at('20:10');
        });
    }
}
