<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

it('allows Editor to view/update/delete an EcTrack of their own app', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $ownApp = App::factory()->createQuietly(['user_id' => $editor->id]);
    $track = EcTrack::factory()->createQuietly(['app_id' => $ownApp->id]);

    expect(Gate::forUser($editor)->allows('view', $track))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('update', $track))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('delete', $track))->toBeTrue();
});

it('denies Editor from viewing/updating/deleting an EcTrack of another app', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    App::factory()->createQuietly(['user_id' => $editor->id]);
    $otherApp = App::factory()->createQuietly();
    $track = EcTrack::factory()->createQuietly(['app_id' => $otherApp->id]);

    expect(Gate::forUser($editor)->allows('view', $track))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('update', $track))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('delete', $track))->toBeFalse();
});

it('allows Administrator to view/update/delete any EcTrack regardless of app', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $otherApp = App::factory()->createQuietly();
    $track = EcTrack::factory()->createQuietly(['app_id' => $otherApp->id]);

    expect(Gate::forUser($admin)->allows('view', $track))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $track))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $track))->toBeTrue();
});
