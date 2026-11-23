<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Exceptions\PrintCompilationException;
use Wm\WmPackage\Services\Print\Contracts\MapRenderer;
use Wm\WmPackage\Services\Print\FakeMapRenderer;
use Wm\WmPackage\Services\Print\HttpMapRenderer;
use Wm\WmPackage\Services\Print\MapRenderRequest;

uses(Tests\TestCase::class);

function printFeatureCollection(int $n): array
{
    $features = [];
    for ($i = 1; $i <= $n; $i++) {
        $features[] = [
            'type' => 'Feature',
            'properties' => ['id' => $i, 'ref' => "R$i", 'name' => "Sentiero $i"],
            'geometry' => ['type' => 'LineString', 'coordinates' => [[9, 40], [9.1, 40.1]]],
        ];
    }

    return ['type' => 'FeatureCollection', 'features' => $features];
}

it('builds the request with the shard from config and one feature per item', function () {
    config(['wm-package.shard_name' => 'forestas-test']);

    $one = MapRenderRequest::forShard(printFeatureCollection(1), 180, 120);
    $three = MapRenderRequest::forShard(printFeatureCollection(3), 180, 120);

    expect($one->shard)->toBe('forestas-test')
        ->and($one->featureCollection['features'])->toHaveCount(1)
        ->and($three->featureCollection['features'])->toHaveCount(3)
        ->and($one->toArray())->toBe([
            'shard' => 'forestas-test',
            'feature_collection' => printFeatureCollection(1),
            'width_mm' => 180.0,
            'height_mm' => 120.0,
        ]);
});

it('binds the renderer according to the driver', function () {
    config(['wm-package.features.print.map_service.driver' => 'fake']);
    expect(app(MapRenderer::class))->toBeInstanceOf(FakeMapRenderer::class);

    config(['wm-package.features.print.map_service.driver' => 'http']);
    expect(app(MapRenderer::class))->toBeInstanceOf(HttpMapRenderer::class);
});

it('fake driver logs the request, sends nothing and returns the placeholder png', function () {
    Http::fake();
    config(['wm-package.features.print.map_service.driver' => 'fake']);
    $request = MapRenderRequest::forShard(printFeatureCollection(2), 100, 80);

    Log::shouldReceive('channel')->once()->with('print')->andReturnSelf();
    Log::shouldReceive('info')->once()->with(Mockery::type('string'), $request->toArray());

    $png = app(MapRenderer::class)->render($request);

    Http::assertNothingSent();
    expect(substr($png, 0, 8))->toBe("\x89PNG\r\n\x1a\n");
});

it('http driver posts the request body to the service url', function () {
    config(['wm-package.features.print.map_service' => [
        'driver' => 'http', 'url' => 'https://maps.test/render', 'token' => 'tok', 'timeout' => 5,
    ]]);
    Http::fake(['maps.test/*' => Http::response('PNGDATA', 200)]);
    $request = MapRenderRequest::forShard(printFeatureCollection(1), 100, 80);

    $out = app(MapRenderer::class)->render($request);

    expect($out)->toBe('PNGDATA');
    Http::assertSent(fn ($r) => $r->url() === 'https://maps.test/render'
        && $r->method() === 'POST'
        && $r->data() === $request->toArray()
        && $r->hasHeader('Authorization', 'Bearer tok'));
});

it('http driver throws a readable exception on 500', function () {
    config(['wm-package.features.print.map_service' => ['driver' => 'http', 'url' => 'https://maps.test/render', 'timeout' => 5]]);
    Http::fake(['maps.test/*' => Http::response('boom', 500)]);

    app(MapRenderer::class)->render(MapRenderRequest::forShard(printFeatureCollection(1), 100, 80));
})->throws(PrintCompilationException::class, 'HTTP 500');

it('http driver throws a readable exception on timeout', function () {
    config(['wm-package.features.print.map_service' => ['driver' => 'http', 'url' => 'https://maps.test/render', 'timeout' => 5]]);
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

    app(MapRenderer::class)->render(MapRenderRequest::forShard(printFeatureCollection(1), 100, 80));
})->throws(PrintCompilationException::class, 'timeout');
