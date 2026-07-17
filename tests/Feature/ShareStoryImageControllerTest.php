<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Models\StoryShare\StoryShareImageService;

// Base TestCase (Wm\WmPackage\Tests\TestCase, with RefreshDatabase) is applied globally by
// tests/Pest.php — no explicit uses() needed here.

function shareStoryImagePayload(string $uuid, ?int $clientAppId = null, ?UploadedFile $screenshot = null): array
{
    return [
        'uuid' => $uuid,
        'app_id' => $clientAppId,
        'screenshot' => $screenshot ?? UploadedFile::fake()->image('screenshot.png', 800, 800),
        'duration_seconds' => 3600,
        'distance_km' => 10.5,
        'ascent_meters' => 320,
    ];
}

/**
 * Creates a UgcTrack fixture owned by the given user, with `properties.uuid` and
 * `properties.app_id` set explicitly (the trusted fields the controller reads), independent
 * from the real `app_id` column the factory also fills in.
 */
function makeOwnedUgcTrack(User $user, App $app, ?string $uuid = null): UgcTrack
{
    $uuid ??= (string) Str::uuid();

    return UgcTrack::factory()->createQuietly([
        'user_id' => $user->id,
        'app_id' => $app->id,
        'properties' => [
            'uuid' => $uuid,
            'app_id' => $app->id,
        ],
    ]);
}

/**
 * Posts to the endpoint with `Accept: application/json` (needed so Laravel's auth
 * middleware returns a 401 JSON response instead of redirecting to a `login` route that
 * doesn't exist in this package-only test app) while keeping multipart file upload
 * encoding (unlike postJson(), which would force a JSON request body).
 */
function postShareStoryImage(array $payload)
{
    return test()->post('/api/share-story-image', $payload, ['Accept' => 'application/json']);
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

// ── app resolution: strictly from the owned UgcTrack, never from the payload ───

it('derives the app from the UgcTrack matching the uuid, ignoring the app_id sent in the payload', function () {
    $ownApp = App::factory()->createQuietly();
    $otherApp = App::factory()->createQuietly();
    $user = User::factory()->create(['app_id' => null]);
    $track = makeOwnedUgcTrack($user, $ownApp);

    $this->mock(StoryShareImageService::class, function ($mock) use ($ownApp) {
        $mock->shouldReceive('compose')
            ->once()
            ->withArgs(fn ($app) => $app->id === $ownApp->id)
            ->andReturn(\Intervention\Image\Facades\Image::canvas(10, 10)->encode('png'));
    });

    $this->actingAs($user, 'api');

    // Attempted cross-tenant app_id: must be silently ignored by the controller — the
    // authoritative app_id is read from the UgcTrack's own `properties.app_id`, not here.
    $payload = shareStoryImagePayload($track->properties['uuid'], $otherApp->id);

    $response = postShareStoryImage($payload);

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'image/png');
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

    $this->mock(StoryShareImageService::class, function ($mock) use ($propertiesApp) {
        $mock->shouldReceive('compose')
            ->once()
            ->withArgs(fn ($app) => $app->id === $propertiesApp->id)
            ->andReturn(\Intervention\Image\Facades\Image::canvas(10, 10)->encode('png'));
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

// ── happy path / fallback ────────────────────────────────────────────────────

it('returns a composed PNG when the app has a story_frame uploaded', function () {
    $app = App::factory()->createQuietly();
    $app->addMedia(UploadedFile::fake()->image('frame.png', 1080, 1920))
        ->toMediaCollection('story_frame');
    $user = User::factory()->create(['app_id' => $app->id]);
    $track = makeOwnedUgcTrack($user, $app);
    $this->actingAs($user, 'api');

    $response = postShareStoryImage(shareStoryImagePayload($track->properties['uuid']));

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'image/png');
});

it('falls back to an unbranded image when the app has no story_frame uploaded', function () {
    $app = App::factory()->createQuietly();
    $user = User::factory()->create(['app_id' => $app->id]);
    $track = makeOwnedUgcTrack($user, $app);
    $this->actingAs($user, 'api');

    $response = postShareStoryImage(shareStoryImagePayload($track->properties['uuid']));

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'image/png');
});

// ── validation ────────────────────────────────────────────────────────────────

it('rejects a request missing the uuid field', function () {
    $app = App::factory()->createQuietly();
    $user = User::factory()->create(['app_id' => $app->id]);
    $this->actingAs($user, 'api');

    $payload = shareStoryImagePayload((string) Str::uuid());
    unset($payload['uuid']);

    $response = postShareStoryImage($payload);

    $response->assertStatus(422);
});

it('rejects a screenshot larger than the max allowed size', function () {
    $app = App::factory()->createQuietly();
    $user = User::factory()->create(['app_id' => $app->id]);
    $track = makeOwnedUgcTrack($user, $app);
    $this->actingAs($user, 'api');

    $oversized = UploadedFile::fake()->image('big.png')->size(10241);

    $response = postShareStoryImage(shareStoryImagePayload($track->properties['uuid'], null, $oversized));

    $response->assertStatus(422);
});

it('rejects a request missing required stats fields', function () {
    $app = App::factory()->createQuietly();
    $user = User::factory()->create(['app_id' => $app->id]);
    $track = makeOwnedUgcTrack($user, $app);
    $this->actingAs($user, 'api');

    $payload = shareStoryImagePayload($track->properties['uuid']);
    unset($payload['distance_km']);

    $response = postShareStoryImage($payload);

    $response->assertStatus(422);
});

it('returns an explicit error when the uploaded screenshot is not a valid image', function () {
    $app = App::factory()->createQuietly();
    $user = User::factory()->create(['app_id' => $app->id]);
    $track = makeOwnedUgcTrack($user, $app);
    $this->actingAs($user, 'api');

    $corrupted = UploadedFile::fake()->create('not-an-image.png', 5, 'image/png');

    $response = postShareStoryImage(shareStoryImagePayload($track->properties['uuid'], null, $corrupted));

    $response->assertStatus(422);
});
