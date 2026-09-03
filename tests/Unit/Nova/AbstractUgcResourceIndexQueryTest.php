<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

it('scopes the UgcPoi index by app_id for a non-Administrator', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $ownApp = App::factory()->createQuietly(['user_id' => $editor->id]);
    $otherApp = App::factory()->createQuietly();

    $ownUgcPoi = UgcPoi::factory()->createQuietly(['app_id' => $ownApp->id]);
    $otherUgcPoi = UgcPoi::factory()->createQuietly(['app_id' => $otherApp->id]);

    $this->actingAs($editor);

    $request = NovaRequest::create('/');
    $request->setUserResolver(fn () => $editor);

    $ids = Wm\WmPackage\Nova\UgcPoi::indexQuery($request, UgcPoi::query())->pluck('id');

    expect($ids)->toContain($ownUgcPoi->id)
        ->and($ids)->not->toContain($otherUgcPoi->id);
});

it('does not scope the UgcPoi index for a Validator (mirrors the UgcPoiPolicy::before() bypass)', function () {
    $validator = User::factory()->create();
    $validator->assignRole('Validator');
    $otherApp = App::factory()->createQuietly();
    $otherUgcPoi = UgcPoi::factory()->createQuietly(['app_id' => $otherApp->id]);

    $this->actingAs($validator);

    $request = NovaRequest::create('/');
    $request->setUserResolver(fn () => $validator);

    $ids = Wm\WmPackage\Nova\UgcPoi::indexQuery($request, UgcPoi::query())->pluck('id');

    expect($ids)->toContain($otherUgcPoi->id);
});

it('does not scope the UgcPoi index for an Administrator', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $otherApp = App::factory()->createQuietly();
    $otherUgcPoi = UgcPoi::factory()->createQuietly(['app_id' => $otherApp->id]);

    $this->actingAs($admin);

    $request = NovaRequest::create('/');
    $request->setUserResolver(fn () => $admin);

    $ids = Wm\WmPackage\Nova\UgcPoi::indexQuery($request, UgcPoi::query())->pluck('id');

    expect($ids)->toContain($otherUgcPoi->id);
});
