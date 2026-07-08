<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Laravel\Nova\Events\StartedImpersonating;
use Laravel\Nova\Events\StoppedImpersonating;
use Tests\TestCase;
use Wm\WmPackage\Listeners\LogImpersonationStarted;
use Wm\WmPackage\Listeners\LogImpersonationStopped;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;

// `Wm\WmPackage\Tests\TestCase` (bound di default da wm-package/tests/Pest.php) non è
// autoloadabile da maphub (non è in autoload-dev del consumer) — si usa il bootstrap
// dell'app host, stesso pattern di wm-package/tests/Feature/FeatureCollectionUploadTest.php.
uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();

    // Replica il gate 'viewNova' definito da ogni app consumer (es. maphub NovaServiceProvider::gate())
    // così il test resta isolato dal bootstrap dell'app host.
    Gate::define('viewNova', fn (User $user) => $user->can('access-nova'));
});

it('Administrator can impersonate', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    expect($admin->canImpersonate())->toBeTrue();
});

it('Editor cannot impersonate', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');

    expect($editor->canImpersonate())->toBeFalse();
});

it('Validator cannot impersonate', function () {
    $validator = User::factory()->create();
    $validator->assignRole('Validator');

    expect($validator->canImpersonate())->toBeFalse();
});

it('Administrator cannot be impersonated', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    expect($admin->canBeImpersonated())->toBeFalse();
});

it('Editor can be impersonated by an Administrator', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');

    expect($editor->canBeImpersonated())->toBeTrue();
});

it('Guest cannot be impersonated (lacks access-nova, would get the admin stuck)', function () {
    $guest = User::factory()->create();
    $guest->assignRole('Guest');

    expect($guest->canBeImpersonated())->toBeFalse();
});

it('respects a custom allowed_roles config', function () {
    config(['wm-package.impersonation.allowed_roles' => ['Editor']]);

    $editor = User::factory()->create();
    $editor->assignRole('Editor');

    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    expect($editor->canImpersonate())->toBeTrue()
        ->and($admin->canImpersonate())->toBeFalse();
});

it('logs impersonation start', function () {
    Log::shouldReceive('info')
        ->once()
        ->with('Nova impersonation started', Mockery::type('array'));

    $admin = User::factory()->create(['email' => 'admin@test.com']);
    $editor = User::factory()->create(['email' => 'editor@test.com']);

    (new LogImpersonationStarted)->handle(new StartedImpersonating($admin, $editor, null));
});

it('logs impersonation stop', function () {
    Log::shouldReceive('info')
        ->once()
        ->with('Nova impersonation stopped', Mockery::type('array'));

    $admin = User::factory()->create(['email' => 'admin@test.com']);
    $editor = User::factory()->create(['email' => 'editor@test.com']);

    (new LogImpersonationStopped)->handle(new StoppedImpersonating($admin, $editor, null));
});
