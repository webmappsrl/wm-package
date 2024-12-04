<?php

namespace Wm\WmPackage\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;
use Wm\WmPackage\WmPackageServiceProvider;
use Orchestra\Testbench\Attributes\WithMigration;

#[WithMigration]
class TestCase extends Orchestra
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn(string $modelName) => 'Wm\\WmPackage\\Database\\Factories\\' . class_basename($modelName) . 'Factory'
        );
    }

    protected function getPackageProviders($app)
    {
        return [
            WmPackageServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'testing');
    }
}
