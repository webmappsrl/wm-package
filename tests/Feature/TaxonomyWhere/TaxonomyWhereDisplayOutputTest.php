<?php

declare(strict_types=1);

// Wm\WmPackage\Tests\ non e' in autoload-dev del consumer — require diretto cosi' `use
// InsertsGeometryFixtures` sotto risolve indipendentemente da quale suite lancia questo file
// (stesso pattern di ImportEcPoiJobTaxonomySyncTest.php, oc:8588).
require_once __DIR__.'/../../Concerns/InsertsGeometryFixtures.php';

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Tests\Concerns\InsertsGeometryFixtures;

uses(TestCase::class, DatabaseTransactions::class, InsertsGeometryFixtures::class);

function wheresFixture(): array
{
    return [
        'R40784' => ['it' => 'Lazio', 'en' => 'Lazio', '_admin_level' => 4],
        'R41241' => ['name' => ['it' => 'Esperia'], 'admin_level' => 8, 'source' => 'osmfeatures'],
    ];
}

function poiWithWheres(App $app, array $properties): EcPoi
{
    return EcPoi::find(test()->insertEcPoiWithGeometry($app, $properties));
}

it('keeps every entry, in the legacy shape, when no category is selected', function () {
    $app = App::factory()->create();
    $poi = poiWithWheres($app, ['taxonomy_where' => wheresFixture()]);

    $out = $poi->applyTaxonomyWhereDisplay($poi->getGeojson()['properties']);

    expect($out['taxonomy_where'])->toEqual([
        'R40784' => ['it' => 'Lazio', 'en' => 'Lazio', '_admin_level' => 4],
        'R41241' => ['it' => 'Esperia', '_admin_level' => 8, '_source' => 'osmfeatures'],
    ]);
    expect($out['taxonomyWheres'])->toBe(['Lazio', 'Esperia']);
});

it('shows only the selected categories', function () {
    $app = App::factory()->create(['properties' => ['taxonomy_where_display' => ['4']]]);
    $poi = poiWithWheres($app, ['taxonomy_where' => wheresFixture()]);

    $out = $poi->applyTaxonomyWhereDisplay($poi->getGeojson()['properties']);

    expect(array_keys($out['taxonomy_where']))->toBe(['R40784']);
    expect($out['taxonomyWheres'])->toBe(['Lazio']);
});

it('returns empty lists when no entry matches the selected categories', function () {
    $app = App::factory()->create(['properties' => ['taxonomy_where_display' => ['4']]]);
    $poi = poiWithWheres($app, ['taxonomy_where' => ['OLD' => ['it' => 'Ausonia']]]);

    $out = $poi->applyTaxonomyWhereDisplay($poi->getGeojson()['properties']);

    expect($out['taxonomy_where'])->toBe([]);
    expect($out['taxonomyWheres'])->toBe([]);
});

it('strips the backup key from public output', function () {
    $app = App::factory()->create();
    $poi = poiWithWheres($app, ['taxonomy_where' => wheresFixture(), '_taxonomy_where_backup' => ['X' => ['it' => 'Vecchio']]]);

    $out = $poi->applyTaxonomyWhereDisplay($poi->getGeojson()['properties']);

    expect($out)->not->toHaveKey('_taxonomy_where_backup');
});

it('does not filter getModelAsGeojson, used by internal jobs', function () {
    $app = App::factory()->create(['properties' => ['taxonomy_where_display' => ['4']]]);
    $poi = poiWithWheres($app, ['taxonomy_where' => wheresFixture()]);

    expect(array_keys($poi->getGeojson()['properties']['taxonomy_where']))->toEqualCanonicalizing(['R40784', 'R41241']);
});

it('filters pois.geojson of the app', function () {
    $app = App::factory()->create(['properties' => ['taxonomy_where_display' => ['4']]]);
    $poi = poiWithWheres($app, ['taxonomy_where' => wheresFixture()]);

    $feature = collect($app->getAllPoisGeojson())->firstWhere('properties.id', $poi->id);

    expect(array_keys($feature['properties']['taxonomy_where']))->toBe(['R40784']);
});

it('keeps the full-text searchable string unfiltered', function () {
    // Il default di AppFactory per `track_searchables` è ['name', 'description', 'excerpt'],
    // senza 'taxonomyWheres' — con quel default getSearchableString() non appende mai i
    // taxonomy_where, a prescindere dall'implementazione di questo task (oc:8588): il test
    // imposta esplicitamente 'taxonomyWheres' in track_searchables per esercitare il path.
    $app = App::factory()->create([
        'properties' => ['taxonomy_where_display' => ['4']],
        'track_searchables' => json_encode(['name', 'description', 'excerpt', 'taxonomyWheres']),
    ]);
    $trackId = DB::table('ec_tracks')->insertGetId([
        'name' => json_encode(['it' => 'Tappa']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"MultiLineString\",\"coordinates\":[[[13.7,41.4,0],[13.8,41.5,0]]]}')"),
        'properties' => json_encode(['taxonomy_where' => wheresFixture()]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(EcTrack::find($trackId)->getSearchableString())->toContain('Esperia');
});
