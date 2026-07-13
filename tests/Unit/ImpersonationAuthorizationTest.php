<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;
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

it('Administrator can be impersonated by another Administrator', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    expect($admin->canBeImpersonated())->toBeTrue();
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
