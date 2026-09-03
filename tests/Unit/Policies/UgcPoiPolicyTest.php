<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

it('always allows Administrator on any UGC ability, regardless of app', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $otherApp = App::factory()->createQuietly();
    $ugcPoi = UgcPoi::factory()->createQuietly(['app_id' => $otherApp->id]);

    expect(Gate::forUser($admin)->allows('viewAny', UgcPoi::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('view', $ugcPoi))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $ugcPoi))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $ugcPoi))->toBeTrue();
});

it('always allows Validator on any UGC ability, regardless of app (mirrors menu visibility decision)', function () {
    $validator = User::factory()->create();
    $validator->assignRole('Validator');
    $otherApp = App::factory()->createQuietly();
    $ugcPoi = UgcPoi::factory()->createQuietly(['app_id' => $otherApp->id]);

    expect(Gate::forUser($validator)->allows('viewAny', UgcPoi::class))->toBeTrue()
        ->and(Gate::forUser($validator)->allows('view', $ugcPoi))->toBeTrue()
        ->and(Gate::forUser($validator)->allows('update', $ugcPoi))->toBeTrue()
        ->and(Gate::forUser($validator)->allows('delete', $ugcPoi))->toBeTrue();
});

it('allows Editor with hasDashboardShow to view a UGC of their own app, but not create/update/delete', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $ownApp = App::factory()->createQuietly(['user_id' => $editor->id, 'dashboard_show' => true]);
    $ugcPoi = UgcPoi::factory()->createQuietly(['app_id' => $ownApp->id]);

    expect(Gate::forUser($editor)->allows('viewAny', UgcPoi::class))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('view', $ugcPoi))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('create', UgcPoi::class))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('update', $ugcPoi))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('delete', $ugcPoi))->toBeFalse();
});

it('denies Editor with hasDashboardShow from viewing a UGC of another app', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    App::factory()->createQuietly(['user_id' => $editor->id, 'dashboard_show' => true]);
    $otherApp = App::factory()->createQuietly();
    $ugcPoi = UgcPoi::factory()->createQuietly(['app_id' => $otherApp->id]);

    expect(Gate::forUser($editor)->allows('view', $ugcPoi))->toBeFalse();
});

it('denies Editor without hasDashboardShow from viewing any UGC (existing gate, unchanged)', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $ownApp = App::factory()->createQuietly(['user_id' => $editor->id, 'dashboard_show' => false]);
    $ugcPoi = UgcPoi::factory()->createQuietly(['app_id' => $ownApp->id]);

    expect(Gate::forUser($editor)->allows('viewAny', UgcPoi::class))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('view', $ugcPoi))->toBeFalse();
});
