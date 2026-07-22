<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Models\StoryShare\MapRenderService;
use Wm\WmPackage\Services\Models\StoryShare\StoryShareImageService;

// Base TestCase (Wm\WmPackage\Tests\TestCase, with RefreshDatabase) is applied globally by
// tests/Pest.php — no explicit uses() needed here.

/**
 * Third revision (oc:8183): the client sends ONLY `uuid` — no screenshot, no statistics, no
 * app_id. Any extra field is harmless noise the controller never reads.
 */
function shareStoryImagePayload(string $uuid, array $extra = []): array
{
    return array_merge(['uuid' => $uuid], $extra);
}

/**
 * Creates a UgcTrack fixture owned by the given user, with `properties.uuid`/`properties.app_id`
 * set explicitly (the trusted fields the controller reads), independent from the real `app_id`
 * column the factory also fills in. The factory's default `geometry` (a small dummy
 * MultiLineString) is real PostGIS data, usable as-is by MapRenderService.
 */
function makeOwnedUgcTrack(User $user, App $app, ?string $uuid = null, array $propertiesOverrides = []): UgcTrack
{
    $uuid ??= (string) Str::uuid();

    return UgcTrack::factory()->createQuietly([
        'user_id' => $user->id,
        'app_id' => $app->id,
        'properties' => array_merge([
            'uuid' => $uuid,
            'app_id' => $app->id,
        ], $propertiesOverrides),
    ]);
}

/**
 * Posts to the endpoint with `Accept: application/json` (needed so Laravel's auth middleware
 * returns a 401 JSON response instead of redirecting to a `login` route that doesn't exist in
 * this package-only test app).
 */
function postShareStoryImage(array $payload)
{
    return test()->post('/api/share-story-image', $payload, ['Accept' => 'application/json']);
}

function fakeTileResponse()
{
    return Http::response(Image::canvas(256, 256, '#7a9e7a')->encode('png')->getEncoded(), 200, ['Content-Type' => 'image/png']);
}

/**
 * Bypasses the real MapRenderService (network calls to a tile server) for tests that only
 * care about app-resolution/authorization, not the rendering pipeline itself.
 */
function mockMapRenderService(): void
{
    test()->mock(MapRenderService::class, function ($mock) {
        $mock->shouldReceive('render')
            ->once()
            ->andReturn(Image::canvas(960, 960, '#334455'));
    });
}

beforeEach(function () {
    Storage::fake('wmfe');
    // Without this, StorageService::getShardName() gets a null `wm-package.shard_name`
    // (SHARD_NAME env unset in the test env) and throws a TypeError as soon as media
    // conversions run — same fix already used by UgcPoiControllerTest.
    config()->set('wm-package.shard_name', 'test_shard');
    $this->withoutMiddleware('auth.jwt');
    $this->artisan('jwt:secret --always-no');
});

// ── authentication ───────────────────────────────────────────────────────────

it('rejects an unauthenticated request', function () {
    $response = postShareStoryImage(shareStoryImagePayload((string) Str::uuid()));

    $response->assertStatus(401);
});

// ── validation ────────────────────────────────────────────────────────────────

it('rejects a request missing the uuid field', function () {
    $app = App::factory()->createQuietly();
    $user = User::factory()->create(['app_id' => $app->id]);
    $this->actingAs($user, 'api');

    $response = postShareStoryImage([]);

    $response->assertStatus(422);
});

// ── app resolution: strictly from the owned UgcTrack, never from the payload ───

it('derives the app from the UgcTrack matching the uuid, ignoring any extra payload fields', function () {
    $ownApp = App::factory()->createQuietly();
    $otherApp = App::factory()->createQuietly();
    $user = User::factory()->create(['app_id' => null]);
    $track = makeOwnedUgcTrack($user, $ownApp);

    mockMapRenderService();
    $this->mock(StoryShareImageService::class, function ($mock) use ($ownApp) {
        $mock->shouldReceive('compose')
            ->once()
            ->withArgs(fn ($app) => $app->id === $ownApp->id)
            ->andReturn(Image::canvas(1080, 1920)->encode('png'));
    });

    $this->actingAs($user, 'api');

    // Attempted cross-tenant app_id: must be silently ignored (the field isn't even
    // validated/read anymore — the authoritative app_id comes from the UgcTrack itself).
    $response = postShareStoryImage(shareStoryImagePayload($track->properties['uuid'], ['app_id' => $otherApp->id]));

    $response->assertStatus(200);
    $response->assertJsonStructure(['image_url', 'share_url']);
});

it('prefers properties.app_id over the real app_id column when they disagree', function () {
    $propertiesApp = App::factory()->createQuietly();
    $columnApp = App::factory()->createQuietly();
    $user = User::factory()->create(['app_id' => null]);

    $track = UgcTrack::factory()->createQuietly([
        'user_id' => $user->id,
        'app_id' => $columnApp->id,
        'properties' => [
            'uuid' => (string) Str::uuid(),
            'app_id' => $propertiesApp->id,
        ],
    ]);

    mockMapRenderService();
    $this->mock(StoryShareImageService::class, function ($mock) use ($propertiesApp) {
        $mock->shouldReceive('compose')
            ->once()
            ->withArgs(fn ($app) => $app->id === $propertiesApp->id)
            ->andReturn(Image::canvas(1080, 1920)->encode('png'));
    });

    $this->actingAs($user, 'api');

    $response = postShareStoryImage(shareStoryImagePayload($track->properties['uuid']));

    $response->assertStatus(200);
});

it('returns 404 when no track matches the given uuid', function () {
    $app = App::factory()->createQuietly();
    $user = User::factory()->create(['app_id' => $app->id]);
    $this->actingAs($user, 'api');

    $response = postShareStoryImage(shareStoryImagePayload((string) Str::uuid()));

    $response->assertStatus(404);
});

it('returns 403 when the track exists but belongs to a different user', function () {
    $app = App::factory()->createQuietly();
    $owner = User::factory()->create(['app_id' => $app->id]);
    $requester = User::factory()->create(['app_id' => $app->id]);
    $track = makeOwnedUgcTrack($owner, $app);

    $this->actingAs($requester, 'api');

    $response = postShareStoryImage(shareStoryImagePayload($track->properties['uuid']));

    $response->assertStatus(403);
});

it('returns 500 when the track has no app_id resolvable to an existing App', function () {
    $app = App::factory()->createQuietly();
    $user = User::factory()->create(['app_id' => $app->id]);
    // properties.app_id points nowhere useful; it still wins over the real (valid) app_id
    // column, and resolves to nothing.
    $track = makeOwnedUgcTrack($user, $app, null, ['app_id' => 999999]);

    $this->actingAs($user, 'api');

    $response = postShareStoryImage(shareStoryImagePayload($track->properties['uuid']));

    $response->assertStatus(500);
});

// ── happy path / fallback — full pipeline, real MapRenderService + StoryShareImageService ──

it('returns image_url and share_url and persists the share_image media when the app has a share_frame uploaded', function () {
    Http::fake(fn () => fakeTileResponse());

    $app = App::factory()->createQuietly();
    $app->addMedia(UploadedFile::fake()->image('frame.png', 1080, 1920))
        ->toMediaCollection('share_frame');
    $user = User::factory()->create(['app_id' => $app->id]);
    $track = makeOwnedUgcTrack($user, $app);
    $this->actingAs($user, 'api');

    $response = postShareStoryImage(shareStoryImagePayload($track->properties['uuid']));

    $response->assertStatus(200);
    $response->assertJsonStructure(['image_url', 'share_url']);
    $json = $response->json();
    expect($json['share_url'])->toContain($track->properties['uuid']);

    $track->refresh();
    expect($track->getFirstMedia('share_image'))->not->toBeNull();
    expect($track->properties['share_snapshot'] ?? null)->not->toBeNull();
});

it('falls back to an unbranded image when the app has no share_frame uploaded', function () {
    Http::fake(fn () => fakeTileResponse());

    $app = App::factory()->createQuietly();
    $user = User::factory()->create(['app_id' => $app->id]);
    $track = makeOwnedUgcTrack($user, $app);
    $this->actingAs($user, 'api');

    $response = postShareStoryImage(shareStoryImagePayload($track->properties['uuid']));

    $response->assertStatus(200);
    $response->assertJsonStructure(['image_url', 'share_url']);
});

it('computes statistics from properties.locations when present', function () {
    Http::fake(fn () => fakeTileResponse());

    $app = App::factory()->createQuietly();
    $user = User::factory()->create(['app_id' => $app->id]);
    $track = makeOwnedUgcTrack($user, $app, null, [
        'locations' => [
            ['time' => 0, 'latitude' => 44.0, 'longitude' => 10.0, 'altitude' => 100, 'altitudeAccuracy' => 5],
            ['time' => 60_000, 'latitude' => 44.01, 'longitude' => 10.0, 'altitude' => 150, 'altitudeAccuracy' => 5],
        ],
    ]);
    $this->actingAs($user, 'api');

    $response = postShareStoryImage(shareStoryImagePayload($track->properties['uuid']));

    $response->assertStatus(200);

    $track->refresh();
    $snapshot = $track->properties['share_snapshot'];
    expect($snapshot['duration_seconds'])->toBe(60);
    expect($snapshot['ascent_meters'])->toBeGreaterThan(0);
    expect($snapshot['distance_km'])->toBeGreaterThan(0);
});

// ── failure of the rendering pipeline itself ────────────────────────────────────

it('returns 500 when the map tile server is entirely unreachable', function () {
    Http::fake(fn () => Http::response('error', 500));

    $app = App::factory()->createQuietly();
    $user = User::factory()->create(['app_id' => $app->id]);
    $track = makeOwnedUgcTrack($user, $app);
    $this->actingAs($user, 'api');

    $response = postShareStoryImage(shareStoryImagePayload($track->properties['uuid']));

    $response->assertStatus(500);
});
