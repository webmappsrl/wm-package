<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

it('allows Administrator to create, update and delete taxonomy activities', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $taxonomy = new TaxonomyActivity(['name' => ['it' => 'Test']]);
    $taxonomy->saveQuietly();

    expect(Gate::forUser($admin)->allows('create', TaxonomyActivity::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $taxonomy))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $taxonomy))->toBeTrue();
});

it('allows Editor to view but denies create/update/delete on taxonomy activities', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $taxonomy = new TaxonomyActivity(['name' => ['it' => 'Test']]);
    $taxonomy->saveQuietly();

    expect(Gate::forUser($editor)->allows('viewAny', TaxonomyActivity::class))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('create', TaxonomyActivity::class))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('update', $taxonomy))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('delete', $taxonomy))->toBeFalse();
});
