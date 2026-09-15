<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

it('scopes the Layer index by app_id for an Editor', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $ownApp = App::factory()->createQuietly(['user_id' => $editor->id]);
    $otherApp = App::factory()->createQuietly();

    $ownLayer = Layer::factory()->createQuietly(['app_id' => $ownApp->id]);
    $otherLayer = Layer::factory()->createQuietly(['app_id' => $otherApp->id]);

    $this->actingAs($editor);

    $request = NovaRequest::create('/');
    $request->setUserResolver(fn () => $editor);

    $ids = Wm\WmPackage\Nova\Layer::indexQuery($request, Layer::query())->pluck('id');

    expect($ids)->toContain($ownLayer->id)
        ->and($ids)->not->toContain($otherLayer->id);
});

it('also scopes the Layer index by app_id for a Validator (EC/Layer scoping applies to any non-Administrator, unlike UGC)', function () {
    $validator = User::factory()->create();
    $validator->assignRole('Validator');
    $ownApp = App::factory()->createQuietly(['user_id' => $validator->id]);
    $otherApp = App::factory()->createQuietly();

    $ownLayer = Layer::factory()->createQuietly(['app_id' => $ownApp->id]);
    $otherLayer = Layer::factory()->createQuietly(['app_id' => $otherApp->id]);

    $this->actingAs($validator);

    $request = NovaRequest::create('/');
    $request->setUserResolver(fn () => $validator);

    $ids = Wm\WmPackage\Nova\Layer::indexQuery($request, Layer::query())->pluck('id');

    expect($ids)->toContain($ownLayer->id)
        ->and($ids)->not->toContain($otherLayer->id);
});

it('does not scope the Layer index for an Administrator', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $otherApp = App::factory()->createQuietly();
    $otherLayer = Layer::factory()->createQuietly(['app_id' => $otherApp->id]);

    $this->actingAs($admin);

    $request = NovaRequest::create('/');
    $request->setUserResolver(fn () => $admin);

    $ids = Wm\WmPackage\Nova\Layer::indexQuery($request, Layer::query())->pluck('id');

    expect($ids)->toContain($otherLayer->id);
});
