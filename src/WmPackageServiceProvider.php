<?php

namespace Wm\WmPackage;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Wm\WmPackage\Commands\AddHoquToken;
use Wm\WmPackage\Commands\WmPackageCommand;

class WmPackageServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('wm-package')
            //->hasConfigFile()
            ->hasRoute('api')
            //->hasViews()
            ->hasMigration('create_jobs_table')
            ->hasCommands([WmPackageCommand::class, AddHoquToken::class]);
    }
}
