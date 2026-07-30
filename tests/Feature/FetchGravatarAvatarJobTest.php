<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Wm\WmPackage\Jobs\FetchGravatarAvatarJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\User;

uses(TestCase::class, DatabaseTransactions::class);

// Media requires a valid app_id (NOT NULL FK to `apps`). User has no boot()-time
// auto-assignment of app_id from the first App. Distinct function name to avoid
// collision with makeUserForMedia() (UserAvatarMediaTest), makeUserWithAppForAuth()
// (AppAuthControllerUpdateProfileTest) and makeUserWithAppForMeEndpoint()
// (AppAuthControllerMeProfileTest) — Pest closure files declare top-level
// `function`s in the global namespace, so identical names collide with a fatal
// "Cannot redeclare function" error when the whole tests/Feature/ dir loads together.
function makeUserWithAppForGravatarJob(): User
{
    $app = App::factory()->createQuietly();

    return User::factory()->create(['app_id' => $app->id]);
}

it('dispatches FetchGravatarAvatarJob on signup', function () {
    Queue::fake();

    $this->postJson('/api/auth/signup', [
        'email' => 'gravatar-test@example.com',
        'password' => 'password123',
        'name' => 'Gravatar Test',
    ]);

    Queue::assertPushed(FetchGravatarAvatarJob::class);
});

it('dispatches FetchGravatarAvatarJob with the appId from the app-id header on signup', function () {
    Queue::fake();
    $app = App::factory()->createQuietly();

    $this->withHeaders(['app-id' => (string) $app->id])
        ->postJson('/api/auth/signup', [
            'email' => 'gravatar-appid-test@example.com',
            'password' => 'password123',
            'name' => 'Gravatar AppId Test',
        ]);

    Queue::assertPushed(FetchGravatarAvatarJob::class, fn (FetchGravatarAvatarJob $job) => $job->appId === $app->id);
});

it('does not fail signup when dispatching FetchGravatarAvatarJob throws (e.g. queue connection down)', function () {
    // Bus::shouldReceive() swaps the same container binding (Illuminate\Contracts\Bus\Dispatcher)
    // that PendingDispatch::__destruct() resolves via app(Dispatcher::class), so this reproduces a
    // real queue-connection failure at dispatch time without needing a fake queue driver.
    Bus::shouldReceive('dispatch')->andThrow(new Exception('Queue connection refused'));

    $response = $this->postJson('/api/auth/signup', [
        'email' => 'dispatch-fail-test@example.com',
        'password' => 'password123',
        'name' => 'Dispatch Fail Test',
    ]);

    $response->assertStatus(200);
    expect(User::where('email', 'dispatch-fail-test@example.com')->exists())->toBeTrue();
});

it('saves the avatar when Gravatar responds with a real image (200)', function () {
    Storage::fake('wmfe');
    Http::fake([
        'gravatar.com/*' => Http::response(file_get_contents(dirname(__DIR__).'/fixtures/avatar-with-gps-exif.jpg'), 200, ['Content-Type' => 'image/jpeg']),
    ]);
    $user = makeUserWithAppForGravatarJob();

    (new FetchGravatarAvatarJob($user->id))->handle();

    expect($user->fresh()->getMedia('avatar'))->toHaveCount(1);
});

it('does not save an avatar when Gravatar responds 404 (no avatar)', function () {
    Http::fake(['gravatar.com/*' => Http::response('', 404)]);
    $user = makeUserWithAppForGravatarJob();

    (new FetchGravatarAvatarJob($user->id))->handle();

    expect($user->fresh()->getMedia('avatar'))->toHaveCount(0);
});

it('logs a distinct failure and does not save an avatar on rate-limit (429)', function () {
    Http::fake(['gravatar.com/*' => Http::response('', 429)]);
    $user = makeUserWithAppForGravatarJob();

    // ->channel() is called before ->error() in BaseJob::logError(); andReturnSelf()
    // keeps the chain on the same mock so the ->error() expectation below can match.
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('error')
        ->once()
        ->with(Mockery::pattern('/status 429/'));

    (new FetchGravatarAvatarJob($user->id))->handle();

    expect($user->fresh()->getMedia('avatar'))->toHaveCount(0);
});

it('logs a distinct failure and does not save an avatar on timeout/connection error', function () {
    Http::fake(['gravatar.com/*' => fn () => throw new ConnectionException('timed out')]);
    $user = makeUserWithAppForGravatarJob();

    (new FetchGravatarAvatarJob($user->id))->handle();

    expect($user->fresh()->getMedia('avatar'))->toHaveCount(0);
});

it('saves the avatar media with the appId passed to the job instead of falling back to app_id=1', function () {
    Storage::fake('wmfe');
    Http::fake([
        'gravatar.com/*' => Http::response(file_get_contents(dirname(__DIR__).'/fixtures/avatar-with-gps-exif.jpg'), 200, ['Content-Type' => 'image/jpeg']),
    ]);
    $user = makeUserWithAppForGravatarJob();
    $otherApp = App::factory()->createQuietly();

    (new FetchGravatarAvatarJob($user->id, $otherApp->id))->handle();

    $media = $user->fresh()->getFirstMedia('avatar');
    expect($media)->not->toBeNull()
        ->and($media->app_id)->toBe($otherApp->id)
        ->and($media->app_id)->not->toBe($user->app_id);
});

it('does not overwrite an existing user-uploaded avatar with a Gravatar image', function () {
    Storage::fake('wmfe');
    Http::fake([
        'gravatar.com/*' => Http::response(file_get_contents(dirname(__DIR__).'/fixtures/avatar-with-gps-exif.jpg'), 200, ['Content-Type' => 'image/jpeg']),
    ]);
    $user = makeUserWithAppForGravatarJob();
    $user->addMedia(UploadedFile::fake()->image('user-uploaded.jpg', 400, 400))
        ->toMediaCollection('avatar');
    $originalMediaId = $user->fresh()->getFirstMedia('avatar')->id;

    (new FetchGravatarAvatarJob($user->id))->handle();

    $fresh = $user->fresh();
    expect($fresh->getMedia('avatar'))->toHaveCount(1)
        ->and($fresh->getFirstMedia('avatar')->id)->toBe($originalMediaId);
});
