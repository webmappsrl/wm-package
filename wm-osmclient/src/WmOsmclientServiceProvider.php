<?php

namespace Wm\WmOsmclient;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class WmOsmclientServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('wm-osmclient')
            //->hasConfigFile()
            //->hasViews()
            //->hasMigration('create_wm-osmclient_table')
            //->hasCommand(WmOsmclientCommand::class)
;
    }
}
