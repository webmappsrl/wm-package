<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Policies\TaxonomyWherePolicy;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

it('resolves TaxonomyWherePolicy via Laravel auto-discovery', function () {
    expect(Gate::getPolicyFor(TaxonomyWhere::class))->toBeInstanceOf(TaxonomyWherePolicy::class);
});

it('allows Administrator to view, create, update and delete taxonomy wheres', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $taxonomy = new TaxonomyWhere(['name' => ['it' => 'Test']]);
    $taxonomy->saveQuietly();

    expect(Gate::forUser($admin)->allows('viewAny', TaxonomyWhere::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('create', TaxonomyWhere::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $taxonomy))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $taxonomy))->toBeTrue();
});

it('allows Editor to view but denies create/update/delete on taxonomy wheres', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $taxonomy = new TaxonomyWhere(['name' => ['it' => 'Test']]);
    $taxonomy->saveQuietly();

    expect(Gate::forUser($editor)->allows('viewAny', TaxonomyWhere::class))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('view', $taxonomy))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('create', TaxonomyWhere::class))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('update', $taxonomy))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('delete', $taxonomy))->toBeFalse();
});
