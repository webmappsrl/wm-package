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

it('allows Editor with hasUgcEnabled to view a UGC of their own app, but not create/update/delete', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $ownApp = App::factory()->createQuietly([
        'user_id' => $editor->id,
        'auth_show_at_startup' => true,
        'geolocation_record_enable' => true,
    ]);
    $ugcTrack = UgcTrack::factory()->createQuietly(['app_id' => $ownApp->id]);

    expect(Gate::forUser($editor)->allows('viewAny', UgcTrack::class))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('view', $ugcTrack))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('create', UgcTrack::class))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('update', $ugcTrack))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('delete', $ugcTrack))->toBeFalse();
});

it('denies Editor with hasUgcEnabled from viewing a UGC of another app', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    App::factory()->createQuietly([
        'user_id' => $editor->id,
        'auth_show_at_startup' => true,
        'geolocation_record_enable' => true,
    ]);
    $otherApp = App::factory()->createQuietly();
    $ugcTrack = UgcTrack::factory()->createQuietly(['app_id' => $otherApp->id]);

    expect(Gate::forUser($editor)->allows('view', $ugcTrack))->toBeFalse();
});

it('denies Editor without hasUgcEnabled from viewing any UGC (aligned with the menu canSee() criterion)', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $ownApp = App::factory()->createQuietly([
        'user_id' => $editor->id,
        'auth_show_at_startup' => false,
        'geolocation_record_enable' => false,
    ]);
    $ugcTrack = UgcTrack::factory()->createQuietly(['app_id' => $ownApp->id]);

    expect(Gate::forUser($editor)->allows('viewAny', UgcTrack::class))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('view', $ugcTrack))->toBeFalse();
});

it('allows Editor to view UGC when hasUgcEnabled is true even if dashboard_show is false (regression: menu vs policy criterion mismatch, oc:8162 review)', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $ownApp = App::factory()->createQuietly([
        'user_id' => $editor->id,
        'auth_show_at_startup' => true,
        'geolocation_record_enable' => true,
        'dashboard_show' => false,
    ]);
    $ugcTrack = UgcTrack::factory()->createQuietly(['app_id' => $ownApp->id]);

    expect(Gate::forUser($editor)->allows('viewAny', UgcTrack::class))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('view', $ugcTrack))->toBeTrue();
});
