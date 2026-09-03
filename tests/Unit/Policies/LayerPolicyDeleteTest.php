<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
    App::factory()->createQuietly();
});

it('allows Administrator to delete a layer', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $layer = Layer::factory()->createQuietly();

    expect(Gate::forUser($admin)->allows('delete', $layer))->toBeTrue();
});

it('denies Editor from deleting a layer', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $layer = Layer::factory()->createQuietly();

    expect(Gate::forUser($editor)->allows('delete', $layer))->toBeFalse();
});
