<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Policies\TaxonomyTermPolicy;
use Wm\WmPackage\Services\RolesAndPermissionsService;

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

it('resolves TaxonomyTermPolicy via Laravel auto-discovery', function () {
    expect(Gate::getPolicyFor(TaxonomyTerm::class))->toBeInstanceOf(TaxonomyTermPolicy::class);
});

it('allows Administrator to view, create, update and delete taxonomy terms', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $term = TaxonomyTerm::create(['identifier' => 'policy-test', 'name' => ['it' => 'Test']]);

    expect(Gate::forUser($admin)->allows('viewAny', TaxonomyTerm::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('create', TaxonomyTerm::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $term))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $term))->toBeTrue();
});

it('allows Editor to view but denies create/update/delete on taxonomy terms', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $term = TaxonomyTerm::create(['identifier' => 'policy-test', 'name' => ['it' => 'Test']]);

    expect(Gate::forUser($editor)->allows('viewAny', TaxonomyTerm::class))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('view', $term))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('create', TaxonomyTerm::class))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('update', $term))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('delete', $term))->toBeFalse();
});
