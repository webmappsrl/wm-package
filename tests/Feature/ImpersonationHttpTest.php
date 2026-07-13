<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

it('Administrator can start and stop impersonating an Editor via the real Nova route', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    $editor = User::factory()->create();
    $editor->assignRole('Editor');

    $this->actingAs($admin)
        ->postJson('/nova-api/impersonate', [
            'resource' => 'users',
            'resourceId' => $editor->id,
        ])
        ->assertOk();

    expect(auth()->id())->toBe($editor->id);

    $this->deleteJson('/nova-api/impersonate')
        ->assertOk();

    expect(auth()->id())->toBe($admin->id);
});

it('Editor cannot impersonate via the real Nova route', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');

    $target = User::factory()->create();
    $target->assignRole('Validator');

    $this->actingAs($editor)
        ->postJson('/nova-api/impersonate', [
            'resource' => 'users',
            'resourceId' => $target->id,
        ])
        ->assertForbidden();
});

it('Administrator can impersonate another Administrator via the real Nova route', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    $otherAdmin = User::factory()->create();
    $otherAdmin->assignRole('Administrator');

    $this->actingAs($admin)
        ->postJson('/nova-api/impersonate', [
            'resource' => 'users',
            'resourceId' => $otherAdmin->id,
        ])
        ->assertOk();

    expect(auth()->id())->toBe($otherAdmin->id);
});

it('Administrator cannot impersonate a Guest and keeps their own session intact', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    $guest = User::factory()->create();
    $guest->assignRole('Guest');

    $this->actingAs($admin)
        ->postJson('/nova-api/impersonate', [
            'resource' => 'users',
            'resourceId' => $guest->id,
        ])
        ->assertForbidden();

    expect(auth()->id())->toBe($admin->id);
});
