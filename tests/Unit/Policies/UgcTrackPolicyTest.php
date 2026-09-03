<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

it('always allows Administrator on any UGC ability, regardless of app', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $otherApp = App::factory()->createQuietly();
    $ugcTrack = UgcTrack::factory()->createQuietly(['app_id' => $otherApp->id]);

    expect(Gate::forUser($admin)->allows('viewAny', UgcTrack::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('view', $ugcTrack))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $ugcTrack))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $ugcTrack))->toBeTrue();
});

it('always allows Validator on any UGC ability, regardless of app (mirrors menu visibility decision)', function () {
    $validator = User::factory()->create();
    $validator->assignRole('Validator');
    $otherApp = App::factory()->createQuietly();
    $ugcTrack = UgcTrack::factory()->createQuietly(['app_id' => $otherApp->id]);

    expect(Gate::forUser($validator)->allows('viewAny', UgcTrack::class))->toBeTrue()
        ->and(Gate::forUser($validator)->allows('view', $ugcTrack))->toBeTrue()
        ->and(Gate::forUser($validator)->allows('update', $ugcTrack))->toBeTrue()
        ->and(Gate::forUser($validator)->allows('delete', $ugcTrack))->toBeTrue();
});

it('allows Editor with hasDashboardShow to view a UGC of their own app, but not create/update/delete', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $ownApp = App::factory()->createQuietly(['user_id' => $editor->id, 'dashboard_show' => true]);
    $ugcTrack = UgcTrack::factory()->createQuietly(['app_id' => $ownApp->id]);

    expect(Gate::forUser($editor)->allows('viewAny', UgcTrack::class))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('view', $ugcTrack))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('create', UgcTrack::class))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('update', $ugcTrack))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('delete', $ugcTrack))->toBeFalse();
});

it('denies Editor with hasDashboardShow from viewing a UGC of another app', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    App::factory()->createQuietly(['user_id' => $editor->id, 'dashboard_show' => true]);
    $otherApp = App::factory()->createQuietly();
    $ugcTrack = UgcTrack::factory()->createQuietly(['app_id' => $otherApp->id]);

    expect(Gate::forUser($editor)->allows('view', $ugcTrack))->toBeFalse();
});

it('denies Editor without hasDashboardShow from viewing any UGC (existing gate, unchanged)', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $ownApp = App::factory()->createQuietly(['user_id' => $editor->id, 'dashboard_show' => false]);
    $ugcTrack = UgcTrack::factory()->createQuietly(['app_id' => $ownApp->id]);

    expect(Gate::forUser($editor)->allows('viewAny', UgcTrack::class))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('view', $ugcTrack))->toBeFalse();
});
