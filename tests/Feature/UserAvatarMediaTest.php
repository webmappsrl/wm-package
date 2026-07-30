<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\User;

uses(TestCase::class, DatabaseTransactions::class);

// Media requires a valid app_id (NOT NULL FK to `apps`), and unlike Layer, User has
// no boot()-time auto-assignment of app_id from the first App. Same pattern as
// LayerLogoMediaTest::makeLayerForMedia().
function makeUserForMedia(): User
{
    $app = App::factory()->createQuietly();

    return User::factory()->create(['app_id' => $app->id]);
}

it('allows mass assignment of surname', function () {
    $user = User::factory()->create(['surname' => 'Rossi']);

    expect($user->fresh()->surname)->toBe('Rossi');
});

it('registers an avatar media collection as single file', function () {
    Storage::fake('wmfe');
    $user = makeUserForMedia();

    $user->addMedia(UploadedFile::fake()->image('avatar.jpg', 400, 400))
        ->toMediaCollection('avatar');
    $user->addMedia(UploadedFile::fake()->image('avatar-2.jpg', 400, 400))
        ->toMediaCollection('avatar');

    expect($user->fresh()->getMedia('avatar'))->toHaveCount(1);
});

it('exposes avatar_url when an avatar is attached', function () {
    Storage::fake('wmfe');
    $user = makeUserForMedia();

    $user->addMedia(UploadedFile::fake()->image('avatar.jpg', 400, 400))
        ->toMediaCollection('avatar');

    $fresh = $user->fresh();
    expect($fresh->avatar_url)->toBeString()
        ->and($fresh->toArray()['avatar_url'])->toBe($fresh->avatar_url);
});

it('returns null for avatar_url when no avatar is attached', function () {
    $user = User::factory()->create();

    expect($user->fresh()->avatar_url)->toBeNull()
        ->and($user->fresh()->toArray()['avatar_url'])->toBeNull();
});
