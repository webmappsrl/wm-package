<?php

namespace Wm\WmPackage;


use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Wm\WmPackage\Commands\HoquRegisterUserCommand;
use Wm\WmPackage\Commands\WmPackageCommand;
use Wm\WmPackage\Commands\HoquSendStoreCommand;

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
            ->hasConfigFile()
            ->hasRoute('api')
            //->hasViews()
            ->hasMigrations([
                'create_jobs_table',
                'create_hoqu_caller_jobs_table'
            ])
            ->hasCommands([
                WmPackageCommand::class,
                HoquRegisterUserCommand::class,
                HoquSendStoreCommand::class
            ]);
    }
}
