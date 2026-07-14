<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;
use Wm\WmPackage\Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

it('denies the osm import report route to Guest', function () {
    $user = User::factory()->create();
    $user->assignRole('Guest');

    $response = $this->actingAs($user)->get('/nova-vendor/osm-import-reports/'.str_repeat('a', 16));

    $response->assertForbidden();
});

it('allows the osm import report route to Editor', function () {
    $user = User::factory()->create();
    $user->assignRole('Editor');

    $response = $this->actingAs($user)->get('/nova-vendor/osm-import-reports/'.str_repeat('a', 16));

    // access-nova middleware must not block (403); 404 is expected because the token is fake.
    expect($response->status())->not->toBe(403);
});
