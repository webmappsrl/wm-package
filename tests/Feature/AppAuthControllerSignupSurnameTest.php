<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Wm\WmPackage\Jobs\FetchGravatarAvatarJob;
use Wm\WmPackage\Models\User;

uses(Tests\TestCase::class, DatabaseTransactions::class);

function validSignupPayload(array $overrides = []): array
{
    return array_merge([
        'email' => 'nuovo-utente@example.com',
        'password' => 'password123',
        'name' => 'Mario',
    ], $overrides);
}

it('signup succeeds without surname, for backward compatibility with old app versions', function () {
    Queue::fake();

    $response = $this->postJson('/api/auth/signup', validSignupPayload());

    $response->assertStatus(200);
    $user = User::where('email', 'nuovo-utente@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->surname)->toBeNull();

    Queue::assertPushed(FetchGravatarAvatarJob::class);
});

it('signup persists surname when the app sends it', function () {
    Queue::fake();

    $response = $this->postJson('/api/auth/signup', validSignupPayload(['surname' => 'Rossi']));

    $response->assertStatus(200);
    $user = User::where('email', 'nuovo-utente@example.com')->first();
    expect($user->surname)->toBe('Rossi');
});

it('signup fails with 400 when surname is sent but empty', function () {
    Queue::fake();

    $response = $this->postJson('/api/auth/signup', validSignupPayload(['surname' => '']));

    $response->assertStatus(400)
        ->assertJson(['error' => 'Il campo cognome è obbligatorio.']);

    expect(User::where('email', 'nuovo-utente@example.com')->exists())->toBeFalse();
});
