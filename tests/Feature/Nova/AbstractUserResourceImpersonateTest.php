<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Wm\WmPackage\Services\RolesAndPermissionsService;

// `Wm\WmPackage\Tests\TestCase` (bound di default da wm-package/tests/Pest.php) non è
// autoloadabile da maphub — si usa il bootstrap dell'app host, stesso pattern degli altri
// test di questo ticket (vedi ImpersonationAuthorizationTest.php / ImpersonationHttpTest.php).
uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

it('authorizedToImpersonate is true from the real Nova index endpoint', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    $editor = User::factory()->create();
    $editor->assignRole('Editor');

    $response = $this->actingAs($admin)
        ->getJson('/nova-api/users?perPage=100')
        ->assertOk();

    $resource = collect($response->json('resources'))
        ->firstOrFail(fn ($r) => (int) $r['id']['value'] === $editor->id);

    expect($resource['authorizedToImpersonate'])->toBeTrue();
});

it('authorizedToImpersonate is false from the real Nova detail endpoint (Nova SPA CSRF bug workaround)', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    $editor = User::factory()->create();
    $editor->assignRole('Editor');

    $response = $this->actingAs($admin)
        ->getJson("/nova-api/users/{$editor->id}")
        ->assertOk();

    expect($response->json('resource.authorizedToImpersonate'))->toBeFalse();
});
