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
            WmPackageServiceProvider::class,
            ExcelServiceProvider::class,
        ];
    }

    protected function defineRoutes($router)
    {
        require __DIR__ . '/../routes/web.php';
    }

    protected function getEnvironmentSetUp($app)
    {
        // set app key for testing routes
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        // set public filesystem for testing
        $app['config']->set('filesystems.disks.public', [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL') . '/storage',
            'visibility' => 'public',
        ]);
    }
}
