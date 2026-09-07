<?php

namespace Wm\WmPackage\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Intervention\Image\ImageServiceProvider;
use Maatwebsite\Excel\ExcelServiceProvider;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionMethod;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;
use Tymon\JWTAuth\Providers\LaravelServiceProvider;
use Wm\WmPackage\WmPackageServiceProvider;

use function Orchestra\Testbench\artisan;

#[WithMigration]
class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function callPrivateMethod(object $object, string $method, array $args = []): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $args);
    }

    protected function getPackageProviders($app)
    {
        return [
            LaravelServiceProvider::class,
            WmPackageServiceProvider::class,
            ExcelServiceProvider::class,
            // Testbench does not auto-discover installed packages the way a full Laravel
            // app does: registering explicitly here (oc:8183) so `Image::make()`/`Image::
            // canvas()` (used by StoryShareImageService and, previously untested, by
            // MediaService) resolve in tests exactly like they already do in the real app.
            ImageServiceProvider::class,
            // Same reason: without this, `media-library.*` config (e.g. `file_namer`) is
            // never merged, so `addMedia()->toMediaCollection()` fails with
            // "Illuminate\Foundation\Application::originalFileName does not exist" —
            // `app(config('media-library.file_namer'))` resolves the container itself when
            // that config key is null and calls the missing method on it.
            MediaLibraryServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // set app key for testing routes
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('auth.guards.api', [
            'driver' => 'jwt',
            'provider' => 'users',
        ]);
    }

    protected function defineDatabaseMigrations()
    {
        artisan($this, 'vendor:publish', ['--tag' => 'wm-package-migrations']);
    }
}
