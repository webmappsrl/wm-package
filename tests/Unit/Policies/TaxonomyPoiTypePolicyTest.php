<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;
use Wm\WmPackage\Models\TaxonomyPoiType;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

it('allows Administrator to view, create, update and delete taxonomy poi types', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $taxonomy = new TaxonomyPoiType(['name' => ['it' => 'Test']]);
    $taxonomy->saveQuietly();

    expect(Gate::forUser($admin)->allows('viewAny', TaxonomyPoiType::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('create', TaxonomyPoiType::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $taxonomy))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $taxonomy))->toBeTrue();
});

it('allows Editor to view but denies create/update/delete on taxonomy poi types', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $taxonomy = new TaxonomyPoiType(['name' => ['it' => 'Test']]);
    $taxonomy->saveQuietly();

    expect(Gate::forUser($editor)->allows('viewAny', TaxonomyPoiType::class))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('view', $taxonomy))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('create', TaxonomyPoiType::class))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('update', $taxonomy))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('delete', $taxonomy))->toBeFalse();
});

it('denies Validator from creating, updating or deleting taxonomy poi types', function () {
    $validator = User::factory()->create();
    $validator->assignRole('Validator');
    $taxonomy = new TaxonomyPoiType(['name' => ['it' => 'Test']]);
    $taxonomy->saveQuietly();

    expect(Gate::forUser($validator)->allows('create', TaxonomyPoiType::class))->toBeFalse()
        ->and(Gate::forUser($validator)->allows('update', $taxonomy))->toBeFalse()
        ->and(Gate::forUser($validator)->allows('delete', $taxonomy))->toBeFalse();
});
