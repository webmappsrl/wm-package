<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Services\GeometryComputationService;

uses(TestCase::class, DatabaseTransactions::class);

/**
 * Crea una TaxonomyWhere con geometria poligonale nota (bbox Corsica), identifier
 * randomizzato per non collidere con dati QA reali del DB di sviluppo condiviso
 * (stesso pattern di SyncTaxonomyWhereJobTest.php).
 */
function createCorsicaTaxonomyWhere(): TaxonomyWhere
{
    $taxonomyWhere = new TaxonomyWhere([
        'name' => 'Corsica',
        'properties' => ['source' => 'geohub', 'admin_level' => 4],
    ]);
    $taxonomyWhere->identifier = 'corsica-'.Str::lower(Str::random(8));
    $taxonomyWhere->save();

    DB::statement(
        'UPDATE taxonomy_wheres SET geometry = ST_GeomFromGeoJSON(?) WHERE id = ?',
        ['{"type":"Polygon","coordinates":[[[8.53,41.33],[9.56,41.33],[9.56,43.03],[8.53,43.03],[8.53,41.33]]]}', $taxonomyWhere->id]
    );

    return $taxonomyWhere->fresh();
}

it('syncs taxonomy_where in bulk for EcTrack', function () {
    createCorsicaTaxonomyWhere();
    $app = App::factory()->create();

    $trackId = DB::table('ec_tracks')->insertGetId([
        'name' => json_encode(['it' => 'Track in Corsica']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"MultiLineString\",\"coordinates\":[[[9.0,42.0,0],[9.1,42.1,0]]]}')"),
        'properties' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $synced = GeometryComputationService::make()->syncTaxonomyWhere(EcTrack::class);

    expect($synced)->toBeGreaterThanOrEqual(1);
    $track = EcTrack::find($trackId);
    expect($track->properties['taxonomy_where'] ?? [])->not->toBeEmpty();
});

it('syncs taxonomy_where in bulk for EcPoi', function () {
    createCorsicaTaxonomyWhere();
    $app = App::factory()->create();

    $poiId = DB::table('ec_pois')->insertGetId([
        'name' => json_encode(['it' => 'Poi in Corsica']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[9.05,42.05,0]}')"),
        'properties' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $synced = GeometryComputationService::make()->syncTaxonomyWhere(EcPoi::class);

    expect($synced)->toBeGreaterThanOrEqual(1);
    $poi = EcPoi::find($poiId);
    expect($poi->properties['taxonomy_where'] ?? [])->not->toBeEmpty();
});

it('scopes the sync to a single EcPoi id without touching other rows', function () {
    createCorsicaTaxonomyWhere();
    $app = App::factory()->create();

    $inCoverageId = DB::table('ec_pois')->insertGetId([
        'name' => json_encode(['it' => 'Poi in Corsica']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[9.05,42.05,0]}')"),
        'properties' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $otherInCoverageId = DB::table('ec_pois')->insertGetId([
        'name' => json_encode(['it' => 'Altro Poi in Corsica']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[9.06,42.06,0]}')"),
        'properties' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $synced = GeometryComputationService::make()->syncTaxonomyWhere(EcPoi::class, $inCoverageId);

    expect($synced)->toBe(1);
    expect(EcPoi::find($inCoverageId)->properties['taxonomy_where'] ?? [])->not->toBeEmpty();
    expect(EcPoi::find($otherInCoverageId)->properties['taxonomy_where'] ?? [])->toBeEmpty();
});

it('scopes the sync to a single EcTrack id without touching other rows', function () {
    createCorsicaTaxonomyWhere();
    $app = App::factory()->create();

    $inCoverageId = DB::table('ec_tracks')->insertGetId([
        'name' => json_encode(['it' => 'Track in Corsica']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"MultiLineString\",\"coordinates\":[[[9.0,42.0,0],[9.1,42.1,0]]]}')"),
        'properties' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $otherInCoverageId = DB::table('ec_tracks')->insertGetId([
        'name' => json_encode(['it' => 'Altra Track in Corsica']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"MultiLineString\",\"coordinates\":[[[9.2,42.2,0],[9.3,42.3,0]]]}')"),
        'properties' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $synced = GeometryComputationService::make()->syncTaxonomyWhere(EcTrack::class, $inCoverageId);

    expect($synced)->toBe(1);
    expect(EcTrack::find($inCoverageId)->properties['taxonomy_where'] ?? [])->not->toBeEmpty();
    expect(EcTrack::find($otherInCoverageId)->properties['taxonomy_where'] ?? [])->toBeEmpty();
});

it('writes a unified taxonomy_where shape with name/admin_level/source keys', function () {
    createCorsicaTaxonomyWhere();
    $app = App::factory()->create();

    $poiId = DB::table('ec_pois')->insertGetId([
        'name' => json_encode(['it' => 'Poi in Corsica']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[9.05,42.05,0]}')"),
        'properties' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    GeometryComputationService::make()->syncTaxonomyWhere(EcPoi::class, $poiId);

    $entry = collect(EcPoi::find($poiId)->properties['taxonomy_where'])->first();
    expect($entry)->toHaveKeys(['name', 'admin_level', 'source']);
    expect($entry['name'])->toBeArray();
});

