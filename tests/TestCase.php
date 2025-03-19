<?php

namespace Wm\WmPackage\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use function Orchestra\Testbench\artisan;
use Wm\WmPackage\WmPackageServiceProvider;
use Maatwebsite\Excel\ExcelServiceProvider;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\TestCase as Orchestra;

#[WithMigration]
class TestCase extends Orchestra
{
    use RefreshDatabase;

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
