<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

it('scopes the EcPoi index by app_id for a non-Administrator', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $ownApp = App::factory()->createQuietly(['user_id' => $editor->id]);

    $otherOwner = User::factory()->create();
    $otherApp = App::factory()->createQuietly(['user_id' => $otherOwner->id]);

    $ownPoi = EcPoi::factory()->createQuietly(['app_id' => $ownApp->id]);
    $otherPoi = EcPoi::factory()->createQuietly(['app_id' => $otherApp->id]);

    $this->actingAs($editor);

    $request = NovaRequest::create('/');
    $request->setUserResolver(fn () => $editor);

    $ids = Wm\WmPackage\Nova\EcPoi::indexQuery($request, EcPoi::query())->pluck('id');

    expect($ids)->toContain($ownPoi->id)
        ->and($ids)->not->toContain($otherPoi->id);
});

it('does not scope the EcPoi index for an Administrator', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $app = App::factory()->createQuietly(['user_id' => $admin->id]);
    $otherApp = App::factory()->createQuietly();

    $ownPoi = EcPoi::factory()->createQuietly(['app_id' => $app->id]);
    $otherPoi = EcPoi::factory()->createQuietly(['app_id' => $otherApp->id]);

    $this->actingAs($admin);

    $request = NovaRequest::create('/');
    $request->setUserResolver(fn () => $admin);

    $ids = Wm\WmPackage\Nova\EcPoi::indexQuery($request, EcPoi::query())->pluck('id');

    expect($ids)->toContain($ownPoi->id)
        ->and($ids)->toContain($otherPoi->id);
});
