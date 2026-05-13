<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Wm\WmPackage\Http\Controllers\Api\Abstracts\UgcController;
use Wm\WmPackage\Http\Controllers\Api\UgcPoiController;
use Wm\WmPackage\Jobs\UpdateModelWithGeometryTaxonomyWhere;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\User;

beforeEach(function () {
    // Evita che eventuali observer dispatchino job reali durante il setup dei modelli
    Bus::fake();

    $fakeS3 = [
        'driver' => 's3',
        'key' => 'testing',
        'secret' => 'testing',
        'region' => 'eu-west-1',
        'bucket' => 'test',
        'url' => 'http://127.0.0.1:9000',
        'endpoint' => 'http://127.0.0.1:9000',
        'use_path_style_endpoint' => true,
    ];
    config([
        'wm-package.shard_name' => 'webmapp',
        'filesystems.disks.s3' => $fakeS3,
        'filesystems.disks.wmfe' => array_merge($fakeS3, ['bucket' => 'wmfe']),
        'filesystems.disks.wmdumps' => array_merge($fakeS3, ['bucket' => 'wmdumps']),
        'filesystems.disks.s3-osfmedia' => array_merge($fakeS3, ['bucket' => 'osfmedia']),
    ]);
});

afterEach(function () {
    \Mockery::close();
});

it('dispatches UpdateModelWithGeometryTaxonomyWhere async when dispatchSync fails', function () {
    $user = User::factory()->create();
    $app = App::factory()->create();

    /** @var UgcPoi $poi */
    $poi = UgcPoi::factory()->create([
        'user_id' => $user->id,
        'app_id' => $app->id,
    ]);

    // Mock del Dispatcher così possiamo distinguere dispatchSync (che fallisce) da dispatch (async)
    $dispatcher = \Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatchSync')
        ->once()
        ->with(\Mockery::type(UpdateModelWithGeometryTaxonomyWhere::class))
        ->andThrow(new RuntimeException('simulated dispatchSync failure'));
    $dispatcher->shouldReceive('dispatch')
        ->once()
        ->with(\Mockery::type(UpdateModelWithGeometryTaxonomyWhere::class));

    app()->instance(Dispatcher::class, $dispatcher);

    $controller = app(UgcPoiController::class);
    $method = new \ReflectionMethod(UgcController::class, 'enrichUgcWithTaxonomyWhere');
    $method->setAccessible(true);
    $method->invoke($controller, $poi);

    // Le assertion Mockery vengono validate al teardown via shouldReceive('...')->once()
    expect(true)->toBeTrue();
});

it('does not push async job when dispatchSync completes without throwing', function () {
    $user = User::factory()->create();
    $app = App::factory()->create();

    /** @var UgcPoi $poi */
    $poi = UgcPoi::factory()->create([
        'user_id' => $user->id,
        'app_id' => $app->id,
    ]);

    $dispatcher = \Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatchSync')
        ->once()
        ->with(\Mockery::type(UpdateModelWithGeometryTaxonomyWhere::class));
    $dispatcher->shouldNotReceive('dispatch');

    app()->instance(Dispatcher::class, $dispatcher);

    $controller = app(UgcPoiController::class);
    $method = new \ReflectionMethod(UgcController::class, 'enrichUgcWithTaxonomyWhere');
    $method->setAccessible(true);
    $method->invoke($controller, $poi);

    expect(true)->toBeTrue();
});
