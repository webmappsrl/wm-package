<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Nova\Actions\ImportEcPoiFromOsm;
use Wm\WmPackage\Services\RolesAndPermissionsService;
use Wm\WmPackage\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['wm-package.super_admin_emails' => ['team@webmapp.it']]);
    RolesAndPermissionsService::seedDatabase();
});

it('lets an Administrator not in the super-admin allowlist see every app', function () {
    $administrator = User::factory()->create(['email' => 'not-super-admin@example.com']);
    $administrator->assignRole('Administrator');

    App::factory()->count(3)->create();

    Auth::login($administrator);
    $request = NovaRequest::create('/');
    $request->setUserResolver(fn () => $administrator);

    $fields = (new ImportEcPoiFromOsm)->fields($request);
    /** @var Select $appSelect */
    $appSelect = collect($fields)->first(fn ($f) => $f instanceof Select);

    expect($appSelect->jsonSerialize()['options'])->toHaveCount(3);
});

it('lets the configured super-admin email see every app even without the Administrator role', function () {
    $superAdmin = User::factory()->create(['email' => 'team@webmapp.it']);

    App::factory()->count(2)->create();

    Auth::login($superAdmin);
    $request = NovaRequest::create('/');
    $request->setUserResolver(fn () => $superAdmin);

    $fields = (new ImportEcPoiFromOsm)->fields($request);
    $appSelect = collect($fields)->first(fn ($f) => $f instanceof Select);

    expect($appSelect->jsonSerialize()['options'])->toHaveCount(2);
});

it('restricts a regular user to only their own apps', function () {
    $user = User::factory()->create(['email' => 'regular@example.com']);
    $ownApp = App::factory()->create(['user_id' => $user->id]);
    App::factory()->create(); // other user's app

    Auth::login($user);
    $request = NovaRequest::create('/');
    $request->setUserResolver(fn () => $user);

    $fields = (new ImportEcPoiFromOsm)->fields($request);
    $appSelect = collect($fields)->first(fn ($f) => $f instanceof Select);

    $options = $appSelect->jsonSerialize()['options'];

    expect($options)->toHaveCount(1)
        ->and($options[0]['value'])->toBe($ownApp->id);
});
