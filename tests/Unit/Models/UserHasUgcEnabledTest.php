<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Wm\WmPackage\Models\App;

// `Tests\TestCase` (Maphub) instead of the default `Wm\WmPackage\Tests\TestCase` — same
// reason documented in wm-package/tests/Unit/ImpersonationAuthorizationTest.php.
uses(TestCase::class, DatabaseTransactions::class);

it('returns true when the user owns an app with UGC enabled', function () {
    $user = User::factory()->create();
    App::factory()->for($user, 'author')->createQuietly([
        'auth_show_at_startup' => true,
        'geolocation_record_enable' => true,
    ]);

    expect($user->hasUgcEnabled())->toBeTrue();
});

it('returns false when the user owns an app with UGC not fully enabled', function () {
    $user = User::factory()->create();
    App::factory()->for($user, 'author')->createQuietly([
        'auth_show_at_startup' => true,
        'geolocation_record_enable' => false,
    ]);

    expect($user->hasUgcEnabled())->toBeFalse();
});

it('returns false when the user has no app at all, without throwing', function () {
    $user = User::factory()->create();

    expect(fn () => $user->hasUgcEnabled())->not->toThrow(Throwable::class);
    expect($user->hasUgcEnabled())->toBeFalse();
});

it('checks only the given app_id when provided, ignoring the user\'s other apps', function () {
    $user = User::factory()->create();
    App::factory()->for($user, 'author')->createQuietly([
        'auth_show_at_startup' => true,
        'geolocation_record_enable' => true,
    ]);
    $disabledApp = App::factory()->for($user, 'author')->createQuietly([
        'auth_show_at_startup' => false,
        'geolocation_record_enable' => false,
    ]);

    expect($user->hasUgcEnabled($disabledApp->id))->toBeFalse();
});

it('returns true when at least one of multiple owned apps has UGC enabled (OR criterion)', function () {
    $user = User::factory()->create();
    App::factory()->for($user, 'author')->createQuietly([
        'auth_show_at_startup' => false,
        'geolocation_record_enable' => false,
    ]);
    App::factory()->for($user, 'author')->createQuietly([
        'auth_show_at_startup' => true,
        'geolocation_record_enable' => true,
    ]);

    expect($user->hasUgcEnabled())->toBeTrue();
});
