<?php

namespace Wm\WmPackage\Tests;

use Maatwebsite\Excel\ExcelServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Wm\WmPackage\WmPackageServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            \Tymon\JWTAuth\Providers\LaravelServiceProvider::class,
            WmPackageServiceProvider::class,
            ExcelServiceProvider::class,
        ];
    }


    protected function getEnvironmentSetUp($app)
    {
        // set app key for testing routes
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('auth.guards.api', [
            'driver' => 'jwt',
            'provider' => 'users',
        ]);
    }
}
