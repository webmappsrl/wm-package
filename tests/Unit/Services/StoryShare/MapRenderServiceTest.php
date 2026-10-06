<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Models\StoryShare\MapRenderService;

// Base TestCase (Wm\WmPackage\Tests\TestCase, with RefreshDatabase, real Postgres/PostGIS
// connection) applied globally by tests/Pest.php.

beforeEach(function () {
    config()->set('wm-package.shard_name', 'test_shard');
});

/**
 * A minimal valid PNG usable as a stand-in for a downloaded XYZ tile.
 */
function fakeTileBytes(): string
{
    return Image::canvas(256, 256, '#7a9e7a')->encode('png')->getEncoded();
}

/**
 * Creates a UgcTrack with a real PostGIS MultiLineString geometry spanning a small, known
 * area near Lucca (Tuscany) — arbitrary but realistic coordinates for this shard.
 */
function makeUgcTrackWithGeometry(App $app, User $user, array $coordinates): UgcTrack
{
    $geojson = json_encode([
        'type' => 'MultiLineString',
        'coordinates' => [$coordinates],
    ]);

    return UgcTrack::factory()->createQuietly([
        'app_id' => $app->id,
        'user_id' => $user->id,
        'properties' => ['uuid' => (string) Str::uuid()],
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{$geojson}')"),
    ]);
}

it('renders a map image sized exactly width x height when tiles download successfully', function () {
    Http::fake(function () {
        return Http::response(fakeTileBytes(), 200, ['Content-Type' => 'image/png']);
    });

    $app = App::factory()->createQuietly();
    $user = User::factory()->create();
    $track = makeUgcTrackWithGeometry($app, $user, [
        [10.4900, 43.8400, 100],
        [10.4950, 43.8450, 120],
        [10.5000, 43.8500, 90],
    ]);

    $image = (new MapRenderService)->render($track, $app, 960, 960);

    expect($image->width())->toBe(960);
    expect($image->height())->toBe(960);
});

it('falls back to the generic webmapp tile URL when the app has no tile configured', function () {
    $requestedUrls = [];

    Http::fake(function ($request) use (&$requestedUrls) {
        $requestedUrls[] = $request->url();

        return Http::response(fakeTileBytes(), 200, ['Content-Type' => 'image/png']);
    });

    $app = App::factory()->createQuietly();
    expect($app->tiles()->count())->toBe(0);

    $user = User::factory()->create();
    $track = makeUgcTrackWithGeometry($app, $user, [
        [10.4900, 43.8400, 100],
        [10.4950, 43.8450, 120],
    ]);

    (new MapRenderService)->render($track, $app, 960, 960);

    expect($requestedUrls)->not->toBeEmpty();
    expect($requestedUrls[0])->toContain('api.webmapp.it/tiles');
});

it('throws when every tile request fails', function () {
    Http::fake(function () {
        return Http::response('server error', 500);
    });

    $app = App::factory()->createQuietly();
    $user = User::factory()->create();
    $track = makeUgcTrackWithGeometry($app, $user, [
        [10.4900, 43.8400, 100],
        [10.4950, 43.8450, 120],
    ]);

    (new MapRenderService)->render($track, $app, 960, 960);
})->throws(RuntimeException::class);

it('renders successfully for a near-degenerate (single-point-like) geometry without crashing', function () {
    Http::fake(function () {
        return Http::response(fakeTileBytes(), 200, ['Content-Type' => 'image/png']);
    });

    $app = App::factory()->createQuietly();
    $user = User::factory()->create();
    // Two points a few centimeters apart: bbox span is effectively zero, must be expanded
    // rather than blow up the zoom-fitting loop.
    $track = makeUgcTrackWithGeometry($app, $user, [
        [10.490000, 43.840000, 100],
        [10.490001, 43.840001, 100],
    ]);

    $image = (new MapRenderService)->render($track, $app, 960, 960);

    expect($image->width())->toBe(960);
    expect($image->height())->toBe(960);
});

it('still produces a correctly sized image when some (not all) tiles fail to download', function () {
    $callCount = 0;

    Http::fake(function () use (&$callCount) {
        $callCount++;

        // Fail every other tile request; the render must still succeed overall.
        if ($callCount % 2 === 0) {
            return Http::response('not found', 404);
        }

        return Http::response(fakeTileBytes(), 200, ['Content-Type' => 'image/png']);
    });

    $app = App::factory()->createQuietly();
    $user = User::factory()->create();
    $track = makeUgcTrackWithGeometry($app, $user, [
        [10.4900, 43.8400, 100],
        [10.5100, 43.8600, 120],
    ]);

    $image = (new MapRenderService)->render($track, $app, 960, 960);

    expect($image->width())->toBe(960);
    expect($image->height())->toBe(960);
});

it('keeps render() output pixel-identical (characterization)', function () {
    Http::fake(function () {
        return Http::response(fakeTileBytes(), 200, ['Content-Type' => 'image/png']);
    });

    $app = App::factory()->createQuietly();
    $user = User::factory()->create();
    $track = makeUgcTrackWithGeometry($app, $user, [
        [10.4900, 43.8400, 100],
        [10.4950, 43.8450, 120],
        [10.5000, 43.8500, 90],
        [10.5050, 43.8480, 95],
    ]);

    $image = (new MapRenderService)->render($track, $app, 960, 960);

    expect(md5($image->encode('png')->getEncoded()))->toBe('8d8944714eeb881d426de5307be02c38');
});

/**
 * Fake tile server returning a flat-color tile, so pixel assertions know the background.
 */
function fakeSolidTiles(string $color): void
{
    $bytes = Image::canvas(256, 256, $color)->encode('png')->getEncoded();

    Http::fake(fn () => Http::response($bytes, 200, ['Content-Type' => 'image/png']));
}

/**
 * Horizontal line at lat 43.85 across ~1.5km, and its bbox: with this framing the line
 * passes through the exact center pixel of the output image.
 *
 * @return array{0: array<int, array{0: float, 1: float}>, 1: array{xmin: float, ymin: float, xmax: float, ymax: float}}
 */
function horizontalLineFixture(): array
{
    return [
        [[10.490, 43.850], [10.505, 43.850]],
        ['xmin' => 10.490, 'ymin' => 43.850, 'xmax' => 10.505, 'ymax' => 43.850],
    ];
}

/**
 * @return array{0: int, 1: int, 2: int} RGB of the pixel at ($x, $y).
 */
function rgbAt($image, int $x, int $y): array
{
    $color = $image->pickColor($x, $y);

    return [$color[0], $color[1], $color[2]];
}

it('draws layers in the given order', function () {
    fakeSolidTiles('#ffffff');
    [$line, $bbox] = horizontalLineFixture();
    $app = App::factory()->createQuietly();

    $image = (new MapRenderService)->renderLayers([
        ['lineStrings' => [$line], 'color' => '#ff0000', 'thickness' => 12, 'opacity' => 1.0],
        ['lineStrings' => [$line], 'color' => '#ffff00', 'thickness' => 6, 'opacity' => 1.0],
    ], [], $bbox, $app, 960, 960);

    expect(rgbAt($image, 480, 480))->toBe([255, 255, 0]);
});

it('applies opacity to a layer', function () {
    fakeSolidTiles('#ffffff');
    [$line, $bbox] = horizontalLineFixture();
    $app = App::factory()->createQuietly();

    $image = (new MapRenderService)->renderLayers([
        ['lineStrings' => [$line], 'color' => '#ff0000', 'thickness' => 8, 'opacity' => 0.7],
    ], [], $bbox, $app, 960, 960);

    [$r, $g, $b] = rgbAt($image, 480, 480);

    expect($r)->toBe(255);
    expect($g)->toBeGreaterThan(0)->toBeLessThan(255);
    expect($b)->toBeGreaterThan(0)->toBeLessThan(255);
    // Off the line, the background is untouched.
    expect(rgbAt($image, 480, 100))->toBe([255, 255, 255]);
});

it('draws an outline around a layer when outlineColor is given', function () {
    fakeSolidTiles('#ffffff');
    [$line, $bbox] = horizontalLineFixture();
    $app = App::factory()->createQuietly();

    $image = (new MapRenderService)->renderLayers([
        ['lineStrings' => [$line], 'color' => '#ffff00', 'thickness' => 6, 'opacity' => 1.0, 'outlineColor' => '#000000', 'outlineThickness' => 4],
    ], [], $bbox, $app, 960, 960);

    expect(rgbAt($image, 480, 480))->toBe([255, 255, 0]);
    // 5px above the center: inside the outline band (3px fill half + 4px outline), not the fill.
    expect(rgbAt($image, 480, 475))->toBe([0, 0, 0]);
});

it('draws start and end markers', function () {
    fakeSolidTiles('#ffffff');
    $app = App::factory()->createQuietly();
    $bbox = ['xmin' => 10.490, 'ymin' => 43.850, 'xmax' => 10.505, 'ymax' => 43.850];

    $image = (new MapRenderService)->renderLayers([], [
        ['lon' => 10.490, 'lat' => 43.850, 'type' => 'start'],
        ['lon' => 10.505, 'lat' => 43.850, 'type' => 'end'],
    ], $bbox, $app, 960, 960);

    // Recompute the projection the service used to find where the markers landed.
    $service = new MapRenderService;
    $call = fn (string $method, ...$args) => (new ReflectionMethod($service, $method))->invoke($service, ...$args);
    $padded = $call('padBbox', $call('expandDegenerateBbox', $bbox));
    $zoom = $call('fitZoom', $padded, 960, 960);
    $left = $call('lonToPixelX', ($padded['xmin'] + $padded['xmax']) / 2, $zoom) - 480;
    $top = $call('latToPixelY', 43.850, $zoom) - 480;

    $startX = (int) round($call('lonToPixelX', 10.490, $zoom) - $left);
    $endX = (int) round($call('lonToPixelX', 10.505, $zoom) - $left);

    expect(rgbAt($image, $startX, 480))->not->toBe([255, 255, 255]);
    expect(rgbAt($image, $endX, 480))->not->toBe([255, 255, 255]);
    expect(rgbAt($image, $startX, 480))->not->toBe(rgbAt($image, $endX, 480));
});

it('frames the focus bbox, not the whole layers', function () {
    $requestedZooms = [];
    $bytes = fakeTileBytes();

    Http::fake(function ($request) use (&$requestedZooms, $bytes) {
        preg_match('#/tiles/(\d+)/#', $request->url(), $m);
        $requestedZooms[] = (int) $m[1];

        return Http::response($bytes, 200, ['Content-Type' => 'image/png']);
    });

    $app = App::factory()->createQuietly();
    $longLine = [[10.0, 43.5], [11.0, 44.0]];
    $layers = [['lineStrings' => [$longLine], 'color' => '#ff0000', 'thickness' => 4, 'opacity' => 1.0]];
    $service = new MapRenderService;

    $service->renderLayers($layers, [], ['xmin' => 10.0, 'ymin' => 43.5, 'xmax' => 11.0, 'ymax' => 44.0], $app, 960, 960);
    $wholeZoom = $requestedZooms[0];

    $requestedZooms = [];
    $service->renderLayers($layers, [], ['xmin' => 10.49, 'ymin' => 43.74, 'xmax' => 10.51, 'ymax' => 43.76], $app, 960, 960);
    $focusZoom = $requestedZooms[0];

    expect($focusZoom)->toBeGreaterThan($wholeZoom);
});

it('draws markers with the optional size, ringWidth and color keys', function () {
    fakeSolidTiles('#000000');
    $app = App::factory()->createQuietly();
    $bbox = ['xmin' => 10.490, 'ymin' => 43.850, 'xmax' => 10.505, 'ymax' => 43.850];
    $markers = fn (array $extra) => [
        ['lon' => 10.490, 'lat' => 43.850, 'type' => 'start'] + $extra,
    ];

    $service = new MapRenderService;
    $call = fn (string $method, ...$args) => (new ReflectionMethod($service, $method))->invoke($service, ...$args);
    $padded = $call('padBbox', $call('expandDegenerateBbox', $bbox));
    $zoom = $call('fitZoom', $padded, 960, 960);
    $left = $call('lonToPixelX', ($padded['xmin'] + $padded['xmax']) / 2, $zoom) - 480;
    $x = (int) round($call('lonToPixelX', 10.490, $zoom) - $left);

    // Default (28 px disc + 4 px ring): 12 px from the center is still the green disc.
    $default = $service->renderLayers([], $markers([]), $bbox, $app, 960, 960);
    expect(rgbAt($default, $x + 12, 480))->toBe([46, 125, 50]);

    // 13 px disc + 2 px ring: 7 px from the center is the white ring, 12 px is the basemap.
    $small = $service->renderLayers([], $markers(['size' => 13, 'ringWidth' => 2, 'color' => '#0000ff']), $bbox, $app, 960, 960);
    expect(rgbAt($small, $x, 480))->toBe([0, 0, 255]);
    expect(rgbAt($small, $x + 7, 480))->toBe([255, 255, 255]);
    expect(rgbAt($small, $x + 12, 480))->toBe([0, 0, 0]);
});

it('frames the focus bbox with the optional marginRatio instead of the default margin', function () {
    $requestedZooms = [];
    $bytes = fakeTileBytes();

    Http::fake(function ($request) use (&$requestedZooms, $bytes) {
        preg_match('#/tiles/(\d+)/#', $request->url(), $m);
        $requestedZooms[] = (int) $m[1];

        return Http::response($bytes, 200, ['Content-Type' => 'image/png']);
    });

    $app = App::factory()->createQuietly();
    // Span in longitudine scelto perché con il 15% per lato entri a uno zoom e con il 30% no.
    $bbox = ['xmin' => 10.0, 'ymin' => 43.80, 'xmax' => 10.95, 'ymax' => 43.81];
    $service = new MapRenderService;
    $call = fn (string $method, ...$args) => (new ReflectionMethod($service, $method))->invoke($service, ...$args);
    $defaultZoom = $call('fitZoom', $call('padBbox', $bbox), 960, 960);
    $wideZoom = $call('fitZoom', $call('padBbox', $bbox, 0.30), 960, 960);

    expect($wideZoom)->toBeLessThan($defaultZoom);
    expect($call('padBbox', $bbox, 0.30)['xmin'])->toEqualWithDelta(10.0 - 0.95 * 0.30, 1e-9);

    $service->renderLayers([], [], $bbox, $app, 960, 960);
    expect($requestedZooms[0])->toBe($defaultZoom);

    $requestedZooms = [];
    $service->renderLayers([], [], $bbox, $app, 960, 960, 0.30);
    expect($requestedZooms[0])->toBe($wideZoom);
});
