<?php

declare(strict_types=1);

require_once __DIR__.'/../Concerns/InsertsGeometryFixtures.php';

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Http\Resources\EcTrackResource;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\TaxonomyPoiType;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Models\TaxonomyTheme;
use Wm\WmPackage\Tests\Concerns\InsertsGeometryFixtures;

/**
 * `properties.taxonomies` nel dettaglio delle tracce (oc:8959): l'unico punto da cui i frontend
 * leggono le tassonomie, accanto alle chiavi vecchie che restano identiche.
 */
uses(DatabaseTransactions::class, InsertsGeometryFixtures::class);

// Il formato vecchio delle attività legge le icone dal disco `wmfe` e calcola i percorsi su AWS
// dal nome dello shard: in test nessuno dei due è configurato.
beforeEach(function () {
    config(['wm-package.shard_name' => 'test-shard']);
    Storage::fake('wmfe');
});

function taxonomiesTrack(App $app, array $properties = []): EcTrack
{
    return EcTrack::find(test()->insertEcTrackWithGeometry($app, $properties));
}

/**
 * Una tassonomia di `taxonomy_terms` accesa per l'app, con un termine attaccato alla traccia.
 *
 * @return array{0: TaxonomyTerm, 1: TaxonomyTerm}
 */
function attachTaxonomyTerm(EcTrack $track, array $taxonomyProperties = [], ?array $models = ['EcTrack']): array
{
    $taxonomy = TaxonomyTerm::create([
        'identifier' => 'tx-'.uniqid(),
        'name' => ['it' => 'Tassonomia'],
        'properties' => $taxonomyProperties,
    ]);
    $term = TaxonomyTerm::create([
        'identifier' => 'term-'.uniqid(),
        'parent_id' => $taxonomy->id,
        'name' => ['it' => 'Sentiero', 'en' => 'Trail'],
    ]);
    $taxonomy->apps()->attach($track->app_id, ['models' => $models === null ? null : json_encode($models)]);
    $track->taxonomyTerms()->attach($term->id);

    return [$taxonomy, $term];
}

function taxonomiesOf(EcTrack $track): array
{
    return (array) $track->fresh()->getDetailGeojson()['properties']['taxonomies'];
}

it('raggruppa i termini di taxonomy_terms per identifier della tassonomia, con il tab della tassonomia', function () {
    $track = taxonomiesTrack(App::factory()->createQuietly());
    [$taxonomy, $term] = attachTaxonomyTerm($track, ['tab' => 'Activities']);

    expect(taxonomiesOf($track)[$taxonomy->identifier])->toEqual([[
        'identifier' => $term->identifier,
        'name' => ['it' => 'Sentiero', 'en' => 'Trail'],
        'tab' => 'Activities',
        'sort_order' => 0,
    ]]);
});

it('usa General quando la tassonomia non dichiara un tab', function () {
    $track = taxonomiesTrack(App::factory()->createQuietly());
    [$taxonomy] = attachTaxonomyTerm($track);

    expect(taxonomiesOf($track)[$taxonomy->identifier][0]['tab'])->toBe('General');
});

it('esclude le tassonomie spente nell\'app o accese solo per EcPoi', function () {
    $track = taxonomiesTrack(App::factory()->createQuietly());
    [$poiOnly] = attachTaxonomyTerm($track, [], ['EcPoi']);
    [$off] = attachTaxonomyTerm($track);
    $off->apps()->detach();

    expect(taxonomiesOf($track))->not->toHaveKey($poiOnly->identifier)
        ->not->toHaveKey($off->identifier);
});

it('mette activity con il tab di legacy_taxonomies, theme e poi_type con General', function () {
    $track = taxonomiesTrack(App::factory()->createQuietly());
    $activity = TaxonomyActivity::withoutEvents(fn () => TaxonomyActivity::create(['identifier' => 'act-'.uniqid(), 'name' => ['it' => 'Escursionismo']]));
    $theme = TaxonomyTheme::withoutEvents(fn () => TaxonomyTheme::create(['identifier' => 'theme-'.uniqid(), 'name' => ['it' => 'Natura']]));
    $poiType = TaxonomyPoiType::withoutEvents(fn () => TaxonomyPoiType::create(['identifier' => 'poitype-'.uniqid(), 'name' => ['it' => 'Fonte']]));
    $track->taxonomyActivities()->attach($activity->id);
    $track->taxonomyThemes()->attach($theme->id);
    $track->taxonomyPoiTypes()->attach($poiType->id);

    $taxonomies = taxonomiesOf($track);

    expect($taxonomies['activity'])->toBe([['identifier' => $activity->identifier, 'name' => ['it' => 'Escursionismo'], 'tab' => 'Activities']])
        ->and($taxonomies['theme'][0]['tab'])->toBe('General')
        ->and($taxonomies['poi-type'][0]['identifier'])->toBe($poiType->identifier);
});

it('scarta i termini storici senza identifier', function () {
    $track = taxonomiesTrack(App::factory()->createQuietly());
    $theme = TaxonomyTheme::withoutEvents(fn () => TaxonomyTheme::create(['identifier' => 'theme-'.uniqid(), 'name' => ['it' => 'Natura']]));
    $theme->forceFill(['identifier' => null])->saveQuietly();
    $track->taxonomyThemes()->attach($theme->id);

    expect(taxonomiesOf($track))->not->toHaveKey('theme');
});

it('costruisce where dalle zone mostrate dall\'app, senza le chiavi tecniche', function () {
    $app = App::factory()->createQuietly(['properties' => ['taxonomy_where_display' => ['4']]]);
    $track = taxonomiesTrack($app, ['taxonomy_where' => [
        'R40784' => ['it' => 'Lazio', 'en' => 'Lazio', '_admin_level' => 4, '_source' => 'osmfeatures'],
        'R41241' => ['it' => 'Esperia', '_admin_level' => 8],
    ]]);

    expect(taxonomiesOf($track)['where'])->toEqual([[
        'identifier' => 'R40784',
        'name' => ['it' => 'Lazio', 'en' => 'Lazio'],
        'tab' => 'General',
        'admin_level' => 4,
        'source' => 'osmfeatures',
    ]]);
});

it('non scrive when, target né tassonomie che la traccia non ha', function () {
    $track = taxonomiesTrack(App::factory()->createQuietly());
    [$taxonomy] = attachTaxonomyTerm($track);

    expect(array_keys(taxonomiesOf($track)))->toBe([$taxonomy->identifier]);
});

it('scrive taxonomies come oggetto JSON vuoto quando la traccia non ha tassonomie', function () {
    $track = taxonomiesTrack(App::factory()->createQuietly());

    expect(json_encode($track->getDetailGeojson()['properties']))->toContain('"taxonomies":{}');
});

it('lascia invariate taxonomy, taxonomy_where e taxonomyWheres', function () {
    $app = App::factory()->createQuietly();
    $track = taxonomiesTrack($app, ['taxonomy_where' => ['R40784' => ['it' => 'Lazio', '_admin_level' => 4]]]);
    $activity = TaxonomyActivity::withoutEvents(fn () => TaxonomyActivity::create(['identifier' => 'act-'.uniqid(), 'name' => ['it' => 'Escursionismo']]));
    $track->taxonomyActivities()->attach($activity->id);
    attachTaxonomyTerm($track);

    $properties = $track->fresh()->getGeojson()['properties'];

    expect($properties['taxonomy'])->toBe([
        'activity' => [$activity->fresh()->getJson()],
        'when' => [],
        'poi_type' => null,
        'poi_types' => [],
    ])
        ->and($properties['taxonomy_where'])->toBe(['R40784' => ['it' => 'Lazio', '_admin_level' => 4]])
        ->and($properties['taxonomyWheres'])->toBe(['Lazio']);
});

it('non aggiunge taxonomies ai POI', function () {
    $app = App::factory()->createQuietly();
    $poi = EcPoi::find($this->insertEcPoiWithGeometry($app, []));

    expect($poi->getGeojson()['properties'])->not->toHaveKey('taxonomies');
});

it('arriva nel file del dettaglio e nell\'endpoint multiple', function () {
    $track = taxonomiesTrack(App::factory()->createQuietly());
    [$taxonomy] = attachTaxonomyTerm($track);

    $resource = json_decode((new EcTrackResource($track->fresh()))->toJson(), true);
    $multiple = $this->getJson(route('default.api.ec.track.multiple', ['ids' => (string) $track->id]))->json();

    expect($resource['properties']['taxonomies'])->toHaveKey($taxonomy->identifier)
        ->and($multiple['features'][0]['properties']['taxonomies'])->toHaveKey($taxonomy->identifier);
});

it('porta admin_level e source delle zone, quando ci sono', function () {
    $track = taxonomiesTrack(App::factory()->createQuietly(), ['taxonomy_where' => [
        'R40176' => ['en' => 'Atzara', '_source' => 'osmfeatures', '_admin_level' => 8],
        '81' => ['it' => 'Area T (Centrale)', '_source' => 'geohub_conf_32'],
    ]]);

    expect(collect(taxonomiesOf($track)['where'])->sortBy('identifier')->values()->all())->toEqual([
        ['identifier' => '81', 'name' => ['it' => 'Area T (Centrale)'], 'tab' => 'General', 'source' => 'geohub_conf_32'],
        ['identifier' => 'R40176', 'name' => ['en' => 'Atzara'], 'tab' => 'General', 'admin_level' => 8, 'source' => 'osmfeatures'],
    ]);
});

it('porta descrizione, excerpt e icona dei termini storici, solo se valorizzati', function () {
    $track = taxonomiesTrack(App::factory()->createQuietly());
    $full = TaxonomyActivity::withoutEvents(fn () => TaxonomyActivity::create([
        'identifier' => 'act-'.uniqid(),
        'name' => ['it' => 'Escursionismo'],
        'description' => ['it' => 'A piedi'],
        'excerpt' => ['it' => 'Breve'],
        'icon' => 'txn-hiking',
    ]));
    $bare = TaxonomyActivity::withoutEvents(fn () => TaxonomyActivity::create(['identifier' => 'act-'.uniqid(), 'name' => ['it' => 'Bici']]));
    $track->taxonomyActivities()->attach([$full->id, $bare->id]);

    $byIdentifier = collect(taxonomiesOf($track)['activity'])->keyBy('identifier');

    expect($byIdentifier[$full->identifier])->toEqual([
        'identifier' => $full->identifier, 'name' => ['it' => 'Escursionismo'], 'tab' => 'Activities',
        'description' => ['it' => 'A piedi'], 'excerpt' => ['it' => 'Breve'], 'icon' => 'txn-hiking',
    ])
        ->and(array_keys($byIdentifier[$bare->identifier]))->toBe(['identifier', 'name', 'tab']);
});

it('porta descrizione, icona, sort_order dei termini di taxonomy_terms e le properties del legame', function () {
    $track = taxonomiesTrack(App::factory()->createQuietly());
    [$taxonomy, $term] = attachTaxonomyTerm($track);
    $term->update(['description' => ['it' => 'Tracciato a piedi'], 'icon' => 'txn-trail', 'sort_order' => 3]);
    $track->taxonomyTerms()->updateExistingPivot($term->id, ['properties' => ['duration_forward' => 7200]]);

    expect(taxonomiesOf($track)[$taxonomy->identifier][0])->toEqual([
        'identifier' => $term->identifier,
        'name' => ['it' => 'Sentiero', 'en' => 'Trail'],
        'tab' => 'General',
        'description' => ['it' => 'Tracciato a piedi'],
        'icon' => 'txn-trail',
        'sort_order' => 3,
        'pivot_properties' => ['duration_forward' => 7200],
    ]);
});

it('non calcola taxonomies nel GeoJSON interno, usato dai job', function () {
    $track = taxonomiesTrack(App::factory()->createQuietly());
    attachTaxonomyTerm($track);

    expect($track->fresh()->getGeojson()['properties'])->not->toHaveKey('taxonomies');
});

it('scrive name come oggetto JSON anche quando non ha traduzioni', function () {
    $track = taxonomiesTrack(App::factory()->createQuietly());
    [, $term] = attachTaxonomyTerm($track);
    DB::table('taxonomy_terms')->where('id', $term->id)->update(['name' => '{}']);

    expect(json_encode($track->fresh()->getDetailGeojson()['properties']['taxonomies']))->toContain('"name":{}');
});

it('ordina i termini storici per id e le zone per admin_level, senza livello per prime', function () {
    $track = taxonomiesTrack(App::factory()->createQuietly(), ['taxonomy_where' => [
        'R8' => ['it' => 'Comune', '_admin_level' => 8],
        'X' => ['it' => 'Area', '_source' => 'geohub_conf_32'],
        'R4' => ['it' => 'Regione', '_admin_level' => 4],
    ]]);
    $first = TaxonomyActivity::withoutEvents(fn () => TaxonomyActivity::create(['identifier' => 'act-a-'.uniqid(), 'name' => ['it' => 'A']]));
    $second = TaxonomyActivity::withoutEvents(fn () => TaxonomyActivity::create(['identifier' => 'act-b-'.uniqid(), 'name' => ['it' => 'B']]));
    $track->taxonomyActivities()->attach([$second->id, $first->id]);

    $taxonomies = taxonomiesOf($track);

    expect(array_column($taxonomies['activity'], 'identifier'))->toBe([$first->identifier, $second->identifier])
        ->and(array_column($taxonomies['where'], 'identifier'))->toBe(['X', 'R4', 'R8']);
});
