<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\User;

uses(TestCase::class, DatabaseTransactions::class);

function actingAsUserWithToken(User $user): string
{
    return auth('api')->login($user);
}

// Media requires a valid app_id (NOT NULL FK to `apps`) enforced by MediaObserver
// when attaching media; User has no boot()-time auto-assignment of app_id from the
// first App. Same pattern as UserAvatarMediaTest::makeUserForMedia(), but renamed
// (makeUserWithAppForAuth) to avoid a global function name collision with that file:
// Pest closure-style test files declare top-level `function`s in the global
// namespace, so two files both declaring `makeUserForMedia()` cause a fatal
// "Cannot redeclare function" error when loaded together in the same process
// (e.g. running the whole tests/Feature/ directory).
function makeUserWithAppForAuth(): User
{
    $app = App::factory()->createQuietly();

    return User::factory()->create(['app_id' => $app->id]);
}

it('updates surname via POST /api/auth/user', function () {
    $user = User::factory()->create();
    $token = actingAsUserWithToken($user);

    $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/auth/user', ['surname' => 'Bianchi']);

    $response->assertStatus(200)->assertJson(['surname' => 'Bianchi']);
    expect($user->fresh()->surname)->toBe('Bianchi');
});

it('clears an existing surname when surname is sent as an empty string', function () {
    $user = User::factory()->create(['surname' => 'Bianchi']);
    $token = actingAsUserWithToken($user);

    $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/auth/user', ['surname' => '']);

    $response->assertStatus(200)->assertJson(['surname' => '']);
    expect($user->fresh()->surname)->toBe('');
});

it('uploads and stores avatar via POST /api/auth/user', function () {
    Storage::fake('wmfe');
    $user = makeUserWithAppForAuth();
    $token = actingAsUserWithToken($user);

    $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->post('/api/auth/user', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 400, 400),
        ]);

    $response->assertStatus(200);
    expect($user->fresh()->getMedia('avatar'))->toHaveCount(1);
    expect($response->json('avatar_url'))->toBeString();
});

it('replaces previous avatar on second upload (singleFile)', function () {
    Storage::fake('wmfe');
    $user = makeUserWithAppForAuth();
    $token = actingAsUserWithToken($user);

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->post('/api/auth/user', ['avatar' => UploadedFile::fake()->image('a1.jpg', 400, 400)]);
    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->post('/api/auth/user', ['avatar' => UploadedFile::fake()->image('a2.jpg', 400, 400)]);

    expect($user->fresh()->getMedia('avatar'))->toHaveCount(1);
});

it('rejects surname longer than 255 characters', function () {
    $user = User::factory()->create();
    $token = actingAsUserWithToken($user);

    $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/auth/user', ['surname' => str_repeat('a', 256)]);

    $response->assertStatus(400);
});

it('attaches avatar media with app_id from the app-id header instead of falling back to app_id=1', function () {
    Storage::fake('wmfe');
    $user = makeUserWithAppForAuth();
    $token = actingAsUserWithToken($user);
    $otherApp = App::factory()->createQuietly();

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'app-id' => (string) $otherApp->id,
    ])->post('/api/auth/user', [
        'avatar' => UploadedFile::fake()->image('avatar.jpg', 400, 400),
    ]);

    $response->assertStatus(200);
    $media = $user->fresh()->getFirstMedia('avatar');
    expect($media)->not->toBeNull()
        ->and($media->app_id)->toBe($otherApp->id)
        ->and($media->app_id)->not->toBe($user->app_id);
});

it('uploads a real JPEG with a mismatched .txt client extension successfully', function () {
    Storage::fake('wmfe');
    $user = makeUserWithAppForAuth();
    $token = actingAsUserWithToken($user);

    // Real JPEG bytes but a client-supplied .txt filename/extension: previously
    // stripExifFromUploadedImage() used getClientOriginalExtension() (trusts the
    // client filename), which would build a temp path ending in ".txt" and crash
    // Intervention Image's encoder with "Encoding format (txt) is not supported".
    $exifImagePath = dirname(__DIR__).'/fixtures/avatar-with-gps-exif.jpg';
    $uploaded = new UploadedFile($exifImagePath, 'photo.txt', 'image/jpeg', null, true);

    $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->post('/api/auth/user', ['avatar' => $uploaded]);

    $response->assertStatus(200);
    $storedPath = $user->fresh()->getFirstMedia('avatar')->getPath();
    expect(@getimagesize($storedPath))->not->toBeFalse();
});

it('strips EXIF GPS metadata from uploaded avatar', function () {
    Storage::fake('wmfe');
    $user = makeUserWithAppForAuth();
    $token = actingAsUserWithToken($user);

    // Resolved relative to this test file (tests/Feature/ -> tests/ -> fixtures/),
    // not base_path(), since these tests run via the host app's Tests\TestCase and
    // base_path() resolves to the host app's root, not wm-package's — the fixture
    // must be found regardless of which app is running these wm-package tests.
    $exifImagePath = dirname(__DIR__).'/fixtures/avatar-with-gps-exif.jpg';
    $uploaded = new UploadedFile($exifImagePath, 'avatar.jpg', 'image/jpeg', null, true);

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->post('/api/auth/user', ['avatar' => $uploaded]);

    $storedPath = $user->fresh()->getFirstMedia('avatar')->getPath();
    $exif = @exif_read_data($storedPath);

    expect($exif === false || ! isset($exif['GPSLatitude']))->toBeTrue();
});
