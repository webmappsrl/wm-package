<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

it('allows Editor to view/update/delete an EcPoi of their own app', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $ownApp = App::factory()->createQuietly(['user_id' => $editor->id]);
    $poi = EcPoi::factory()->createQuietly(['app_id' => $ownApp->id]);

    expect(Gate::forUser($editor)->allows('view', $poi))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('update', $poi))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('delete', $poi))->toBeTrue();
});

it('denies Editor from viewing/updating/deleting an EcPoi of another app', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    App::factory()->createQuietly(['user_id' => $editor->id]);
    $otherApp = App::factory()->createQuietly();
    $poi = EcPoi::factory()->createQuietly(['app_id' => $otherApp->id]);

    expect(Gate::forUser($editor)->allows('view', $poi))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('update', $poi))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('delete', $poi))->toBeFalse();
});

it('allows Administrator to view/update/delete any EcPoi regardless of app', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $otherApp = App::factory()->createQuietly();
    $poi = EcPoi::factory()->createQuietly(['app_id' => $otherApp->id]);

    expect(Gate::forUser($admin)->allows('view', $poi))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $poi))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $poi))->toBeTrue();
});
