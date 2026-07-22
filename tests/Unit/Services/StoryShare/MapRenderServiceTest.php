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
