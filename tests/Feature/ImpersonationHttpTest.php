<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

it('Administrator can start and stop impersonating an Editor via the real Nova route, and both are logged', function () {
    $logs = [];
    Log::listen(function ($event) use (&$logs) {
        $logs[] = [$event->message, $event->context];
    });

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

    $impersonationLogs = array_values(array_filter(
        $logs,
        fn ($log) => str_starts_with($log[0], 'Nova impersonation')
    ));

    expect($impersonationLogs)->toHaveCount(2)
        ->and($impersonationLogs[0][0])->toBe('Nova impersonation started')
        ->and($impersonationLogs[0][1]['impersonator_id'])->toBe($admin->id)
        ->and($impersonationLogs[0][1]['impersonated_id'])->toBe($editor->id)
        ->and($impersonationLogs[1][0])->toBe('Nova impersonation stopped')
        ->and($impersonationLogs[1][1]['impersonator_id'])->toBe($admin->id)
        ->and($impersonationLogs[1][1]['impersonated_id'])->toBe($editor->id);
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

it('Administrator cannot impersonate another Administrator via the real Nova route', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    $otherAdmin = User::factory()->create();
    $otherAdmin->assignRole('Administrator');

    $this->actingAs($admin)
        ->postJson('/nova-api/impersonate', [
            'resource' => 'users',
            'resourceId' => $otherAdmin->id,
        ])
        ->assertForbidden();
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

it('a real login attempt by a user without access-nova is still blocked (EnforceNovaAccessOnLogin regression)', function () {
    $noAccess = User::factory()->create(['password' => bcrypt('password')]);
    $noAccess->assignRole('Guest');

    $this->postJson('/nova/login', [
        'email' => $noAccess->email,
        'password' => 'password',
    ])->assertStatus(422);

    expect(auth()->check())->toBeFalse();
});
