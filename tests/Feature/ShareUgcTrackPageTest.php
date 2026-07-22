<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;

// Base TestCase (Wm\WmPackage\Tests\TestCase, with RefreshDatabase) is applied globally by
// tests/Pest.php — no explicit uses() needed here.

beforeEach(function () {
    Storage::fake('wmfe');
    config()->set('wm-package.shard_name', 'test_shard');
    $this->withoutMiddleware('auth.jwt');
    $this->artisan('jwt:secret --always-no');
});

/**
 * End-to-end: actually calls the share endpoint (real MapRenderService + StoryShareImageService,
 * tile downloads faked) so the resulting UgcTrack has a genuine persisted `share_image` +
 * `properties.share_snapshot`, exactly like production. Returns the shared track (refreshed).
 */
function shareATrack(User $user, App $app, ?string $trackName = null): UgcTrack
{
    Http::fake(fn () => Http::response(
        Image::canvas(256, 256, '#7a9e7a')->encode('png')->getEncoded(),
        200,
        ['Content-Type' => 'image/png']
    ));

    $uuid = (string) Str::uuid();
    $track = UgcTrack::factory()->createQuietly([
        'user_id' => $user->id,
        'app_id' => $app->id,
        'name' => $trackName ?? 'Sentiero di prova',
        'properties' => ['uuid' => $uuid, 'app_id' => $app->id],
    ]);

    test()->actingAs($user, 'api')
        ->post('/api/share-story-image', ['uuid' => $uuid], ['Accept' => 'application/json'])
        ->assertStatus(200);

    return $track->refresh();
}

it('returns 404 when no track matches the given uuid', function () {
    $response = $this->get('/share/ugc-track/'.Str::uuid());

    $response->assertStatus(404);
});

it('returns 404 when the track exists but was never shared (no persisted share_image)', function () {
    $app = App::factory()->createQuietly();
    $user = User::factory()->create(['app_id' => $app->id]);
    $uuid = (string) Str::uuid();
    UgcTrack::factory()->createQuietly([
        'user_id' => $user->id,
        'app_id' => $app->id,
        'properties' => ['uuid' => $uuid, 'app_id' => $app->id],
    ]);

    $response = $this->get('/share/ugc-track/'.$uuid);

    $response->assertStatus(404);
});

it('returns 404 when the track has been deleted after being shared', function () {
    $app = App::factory()->createQuietly();
    $user = User::factory()->create(['app_id' => $app->id]);
    $track = shareATrack($user, $app);
    $uuid = $track->properties['uuid'];

    $track->delete(); // hard delete: UgcTrack has no SoftDeletes

    $response = $this->get('/share/ugc-track/'.$uuid);

    $response->assertStatus(404);
});

it('returns 200 with the expected Open Graph tags for a shared track', function () {
    $app = App::factory()->createQuietly();
    $user = User::factory()->create(['app_id' => $app->id]);
    $track = shareATrack($user, $app, 'Sentiero delle Alpi Apuane');
    $uuid = $track->properties['uuid'];

    $response = $this->get('/share/ugc-track/'.$uuid);

    $response->assertStatus(200);
    $response->assertSee('og:title', false);
    $response->assertSee('og:description', false);
    $response->assertSee('og:image', false);
    $response->assertSee('og:url', false);
    $response->assertSee('Sentiero delle Alpi Apuane', false);

    $content = $response->getContent();
    expect($content)->toContain(route('share.ugc-track', ['uuid' => $uuid]));
});

it('builds the canonical og:url from the route, matching the uuid requested', function () {
    $app = App::factory()->createQuietly();
    $user = User::factory()->create(['app_id' => $app->id]);
    $track = shareATrack($user, $app);
    $uuid = $track->properties['uuid'];

    $response = $this->get('/share/ugc-track/'.$uuid);

    $expectedUrl = route('share.ugc-track', ['uuid' => $uuid]);
    $response->assertSee($expectedUrl, false);
});
