<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\FeatureCollection;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Models\FeatureCollectionService;

uses(TestCase::class, DatabaseTransactions::class);

it('generates a valid geojson feature collection from layers taxonomy wheres', function () {
    $app = App::factory()->createQuietly();
    $layer = Layer::factory()->createQuietly(['app_id' => $app->id]);

    // Insert a TaxonomyWhere with geometry
    $whereId = DB::table('taxonomy_wheres')->insertGetId([
        'name' => json_encode(['it' => 'Test Where']),
        'geometry' => DB::raw("ST_GeomFromText('POLYGON((0 0, 1 0, 1 1, 0 1, 0 0))', 4326)"),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('taxonomy_whereables')->insert([
        'taxonomy_where_id' => $whereId,
        'taxonomy_whereable_type' => 'App\Models\Layer',
        'taxonomy_whereable_id' => $layer->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $fc = FeatureCollection::factory()->createQuietly([
        'app_id' => $app->id,
        'mode' => 'generated',
        'clickable' => true,
    ]);
    $fc->layers()->attach($layer->id);

    $service = app(FeatureCollectionService::class);
    $geojson = $service->generate($fc->fresh());

    expect($geojson)->toBeArray();
    expect($geojson['type'])->toBe('FeatureCollection');
    expect($geojson['features'])->toHaveCount(1);
    expect($geojson['features'][0]['properties']['layer_id'])->toBe($layer->id);
    expect($geojson['features'][0]['properties']['clickable'])->toBeTrue();
});

it('returns empty feature collection when no layers have taxonomy wheres', function () {
    $app = App::factory()->createQuietly();
    $layer = Layer::factory()->createQuietly(['app_id' => $app->id]);

    $fc = FeatureCollection::factory()->createQuietly([
        'app_id' => $app->id,
        'mode' => 'generated',
    ]);
    $fc->layers()->attach($layer->id);

    $service = app(FeatureCollectionService::class);
    $geojson = $service->generate($fc->fresh());

    expect($geojson['type'])->toBe('FeatureCollection');
    expect($geojson['features'])->toHaveCount(0);
});
