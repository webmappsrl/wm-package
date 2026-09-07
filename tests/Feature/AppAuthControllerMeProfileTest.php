<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\User;

uses(TestCase::class, DatabaseTransactions::class);

// Media requires a valid app_id (NOT NULL FK to `apps`). User has no boot()-time
// auto-assignment of app_id from the first App. Distinct function name to avoid
// collision with makeUserForMedia() (UserAvatarMediaTest) and makeUserWithAppForAuth()
// (AppAuthControllerUpdateProfileTest) — see note in AppAuthControllerUpdateProfileTest.php.
function makeUserWithAppForMeEndpoint(): User
{
    $app = App::factory()->createQuietly();

    return User::factory()->create(['app_id' => $app->id]);
}

it('includes surname and avatar_url (null) in me() response', function () {
    $user = User::factory()->create(['surname' => 'Verdi']);
    $token = auth('api')->login($user);

    $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/auth/me');

    $response->assertStatus(200)
        ->assertJson(['surname' => 'Verdi', 'avatar_url' => null]);
});

it('includes avatar_url in me() response when an avatar is attached', function () {
    Storage::fake('wmfe');
    $user = makeUserWithAppForMeEndpoint();
    $user->addMedia(UploadedFile::fake()->image('avatar.jpg', 400, 400))->toMediaCollection('avatar');
    $token = auth('api')->login($user);

    $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/auth/me');

    $response->assertStatus(200);
    expect($response->json('avatar_url'))->toBeString();
});
