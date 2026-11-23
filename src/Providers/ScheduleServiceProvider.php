<?php

namespace Wm\WmPackage\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\App;
use Illuminate\Support\ServiceProvider;
use Wm\WmPackage\Services\FeaturesService;

class ScheduleServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);

            // Pulizia dei temporanei della stampa PDF (ZIP e mappe di insieme), solo a dominio acceso.
            if (FeaturesService::isEnabled('print')) {
                $schedule->command('wm:print-cleanup')->daily();
            }

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
                $schedule->command('wm:download-db-backup --latest')
                    ->daily()
                    ->at('20:10');
            } else {
                // In non-production environments, download from production
                // Restore is available via Nova action or API endpoint
                $schedule->command('wm:download-db-backup --latest --s3')
                    ->daily()
                    ->at('20:10');
            }
        });
    }
}
