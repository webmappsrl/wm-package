<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Exceptions\MapServiceBusyException;
use Wm\WmPackage\Exceptions\PrintCompilationException;
use Wm\WmPackage\Services\Print\Contracts\MapRenderer;
use Wm\WmPackage\Services\Print\FakeMapRenderer;
use Wm\WmPackage\Services\Print\HttpMapRenderer;
use Wm\WmPackage\Services\Print\MapRenderRequest;

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
    config(['wm-package.print.map_service.driver' => 'fake']);
    expect(app(MapRenderer::class))->toBeInstanceOf(FakeMapRenderer::class);

    config(['wm-package.print.map_service.driver' => 'http']);
    expect(app(MapRenderer::class))->toBeInstanceOf(HttpMapRenderer::class);
});

it('fake driver logs the request, sends nothing and returns the placeholder png', function () {
    Http::fake();
    config(['wm-package.print.map_service.driver' => 'fake']);
    $request = MapRenderRequest::forShard(printFeatureCollection(2), 100, 80);

    Log::shouldReceive('channel')->once()->with('print')->andReturnSelf();
    Log::shouldReceive('info')->once()->with(Mockery::type('string'), $request->toArray());

    $png = app(MapRenderer::class)->render($request);

    Http::assertNothingSent();
    expect(substr($png, 0, 8))->toBe("\x89PNG\r\n\x1a\n");
});

function printMapServiceConfig(array $override = []): void
{
    config(['wm-package.print.map_service' => array_merge([
        'driver' => 'http', 'url' => 'https://maps.test/v1/print/map', 'token' => 'tok', 'timeout' => 5,
        'dpi' => 300, 'style_id' => 'webmapp', 'map_info' => true,
    ], $override)]);
}

const PRINT_TEST_PNG = "\x89PNG\r\n\x1a\nresto";

it('chooses the smallest a5 or a4 page that holds the map area, oriented like it', function (float $w, float $h, string $format, string $orientation) {
    expect(MapRenderRequest::forShard(printFeatureCollection(1), $w, $h)->page(300))
        ->toBe(['format' => $format, 'orientation' => $orientation, 'dpi' => 300]);
})->with([
    'scheda di Forestas, A4 al vivo' => [210, 297, 'a4', 'portrait'],
    'riquadro 174 x 98' => [174, 98, 'a5', 'landscape'],
    "mappa d'insieme" => [200, 170, 'a4', 'landscape'],
    'piccola verticale' => [100, 150, 'a5', 'portrait'],
    'più grande di un A4' => [400, 300, 'a4', 'landscape'],
]);

it('builds the service body: only stroke properties, 2D rounded coordinates, page, style and map info', function () {
    $collection = ['type' => 'FeatureCollection', 'features' => [
        ['type' => 'Feature', 'properties' => ['id' => 7, 'name' => 'Non pubblicato', 'stroke' => '#2e7d32', 'stroke-width' => 4],
            'geometry' => ['type' => 'MultiLineString', 'coordinates' => [[[9.123456789, 40.987654321, 512.3], [9.2, 40.1, 600]]]]],
        ['type' => 'Feature', 'properties' => ['id' => 8], 'geometry' => ['type' => 'Point', 'coordinates' => [9.5, 40.5, 10]]],
    ]];

    $body = MapRenderRequest::forShard($collection, 210, 297)->toServiceBody(300, 'webmapp', false);

    expect($body)->toBe([
        'geometry' => ['type' => 'FeatureCollection', 'features' => [
            ['type' => 'Feature', 'geometry' => ['type' => 'MultiLineString', 'coordinates' => [[[9.123457, 40.987654], [9.2, 40.1]]]],
                'properties' => ['stroke' => '#2e7d32', 'stroke-width' => 4]],
            ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [9.5, 40.5]], 'properties' => null],
        ]],
        'page' => ['format' => 'a4', 'orientation' => 'portrait', 'dpi' => 300],
        'styleId' => 'webmapp',
        'mapInfo' => false,
    ]);
});

it('adds maxZoom and the padding converted from mm to page pixels only when configured', function () {
    $request = MapRenderRequest::forShard(printFeatureCollection(1), 210, 297);

    expect($request->toServiceBody(300, 'webmapp', true))->not->toHaveKeys(['maxZoom', 'padding'])
        ->and($request->toServiceBody(300, 'webmapp', true, 18, 8))->toMatchArray(['maxZoom' => 18.0, 'padding' => 94])
        ->and($request->toServiceBody(150, 'webmapp', true, 25, 10))->toMatchArray(['maxZoom' => 19.0, 'padding' => 59]);
});

it('http driver sends the padding and max zoom of the request over the configured ones', function () {
    printMapServiceConfig(['padding_mm' => 5, 'max_zoom' => 16]);
    Http::fake(['maps.test/*' => Http::response(PRINT_TEST_PNG, 200)]);

    app(MapRenderer::class)->render(MapRenderRequest::forShard(printFeatureCollection(1), 210, 297)->withPadding(42)->withMaxZoom(18));

    Http::assertSent(fn ($r) => $r['padding'] === 496 && $r['maxZoom'] === 18.0);
});

it('http driver sends max_zoom and padding_mm from the config', function () {
    printMapServiceConfig(['max_zoom' => 18, 'padding_mm' => 5]);
    Http::fake(['maps.test/*' => Http::response(PRINT_TEST_PNG, 200)]);

    app(MapRenderer::class)->render(MapRenderRequest::forShard(printFeatureCollection(1), 210, 297));

    Http::assertSent(fn ($r) => $r['maxZoom'] === 18.0 && $r['padding'] === 59);
});

it('http driver posts the service body with the bearer token and returns the png', function () {
    printMapServiceConfig();
    Http::fake(['maps.test/*' => Http::response(PRINT_TEST_PNG, 200, ['Content-Type' => 'image/png'])]);
    $request = MapRenderRequest::forShard(printFeatureCollection(1), 210, 297);

    $out = app(MapRenderer::class)->render($request);

    expect($out)->toBe(PRINT_TEST_PNG);
    Http::assertSent(fn ($r) => $r->url() === 'https://maps.test/v1/print/map'
        && $r->method() === 'POST'
        && $r->data() === $request->toServiceBody(300, 'webmapp', true)
        && $r->hasHeader('Authorization', 'Bearer tok'));
});

it('http driver signals a busy service with its Retry-After on 429, 503 and 504', function (int $status) {
    printMapServiceConfig();
    Http::fake(['maps.test/*' => Http::response(['error' => 'occupato'], $status, ['Retry-After' => '45'])]);

    try {
        app(MapRenderer::class)->render(MapRenderRequest::forShard(printFeatureCollection(1), 210, 297));
        $this->fail('Nessuna eccezione');
    } catch (MapServiceBusyException $e) {
        expect($e->retryAfter)->toBe(45)->and($e->getMessage())->toContain((string) $status);
    }
})->with([429, 503, 504]);

it('http driver treats a timeout as a busy service, with the default wait', function () {
    printMapServiceConfig();
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

    try {
        app(MapRenderer::class)->render(MapRenderRequest::forShard(printFeatureCollection(1), 210, 297));
        $this->fail('Nessuna eccezione');
    } catch (MapServiceBusyException $e) {
        expect($e->retryAfter)->toBe(HttpMapRenderer::DEFAULT_RETRY_AFTER)->and($e->getMessage())->toContain('timeout');
    }
});

it('http driver fails without retry on a request error, naming the field the service reports', function () {
    printMapServiceConfig();
    Http::fake(['maps.test/*' => Http::response(['error' => 'page.dpi deve essere un intero fra 72 e 600'], 400)]);

    expect(fn () => app(MapRenderer::class)->render(MapRenderRequest::forShard(printFeatureCollection(1), 210, 297)))
        ->toThrow(PrintCompilationException::class, 'HTTP 400: page.dpi')
        ->and(fn () => app(MapRenderer::class)->render(MapRenderRequest::forShard(printFeatureCollection(1), 210, 297)))
        ->not->toThrow(MapServiceBusyException::class);
});

it('http driver refuses a body that is not a png', function () {
    printMapServiceConfig();
    Http::fake(['maps.test/*' => Http::response('<html>proxy</html>', 200)]);

    app(MapRenderer::class)->render(MapRenderRequest::forShard(printFeatureCollection(1), 210, 297));
})->throws(PrintCompilationException::class, 'PNG');

it('http driver needs url and token', function (array $override, string $message) {
    printMapServiceConfig($override);
    Http::fake();

    expect(fn () => app(MapRenderer::class)->render(MapRenderRequest::forShard(printFeatureCollection(1), 210, 297)))
        ->toThrow(PrintCompilationException::class, $message);
    Http::assertNothingSent();
})->with([
    'senza url' => [['url' => null], 'WM_PRINT_MAP_SERVICE_URL'],
    'senza token' => [['token' => null], 'WM_PRINT_MAP_SERVICE_TOKEN'],
]);
