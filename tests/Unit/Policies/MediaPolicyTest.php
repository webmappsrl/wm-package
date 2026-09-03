<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Media;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

it('allows Administrator to create, update and delete Media', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    // 'geometry' esplicito: la colonna è geography(PointZ,4326) (richiede 3 coordinate),
    // il default della factory genera solo un Point 2D e fallisce con
    // "Column has Z dimension but geometry does not" (verificato).
    $media = Media::factory()->createQuietly([
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[10.4,43.7,0]}')"),
    ]);

    expect(Gate::forUser($admin)->allows('create', Media::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $media))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $media))->toBeTrue();
});

it('denies Editor from creating, updating or deleting Media', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    // 'geometry' esplicito: la colonna è geography(PointZ,4326) (richiede 3 coordinate),
    // il default della factory genera solo un Point 2D e fallisce con
    // "Column has Z dimension but geometry does not" (verificato).
    $media = Media::factory()->createQuietly([
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[10.4,43.7,0]}')"),
    ]);

    expect(Gate::forUser($editor)->allows('create', Media::class))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('update', $media))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('delete', $media))->toBeFalse();
});

it('keeps Editor viewAny/view allowed only when hasDashboardShow is true (unchanged behaviour)', function () {
    $editorWithDashboard = User::factory()->create();
    $editorWithDashboard->assignRole('Editor');
    App::factory()->for($editorWithDashboard, 'author')->createQuietly(['dashboard_show' => true]);

    $editorWithoutDashboard = User::factory()->create();
    $editorWithoutDashboard->assignRole('Editor');

    expect(Gate::forUser($editorWithDashboard)->allows('viewAny', Media::class))->toBeTrue()
        ->and(Gate::forUser($editorWithoutDashboard)->allows('viewAny', Media::class))->toBeFalse();
});
