<?php

declare(strict_types=1);

require_once __DIR__.'/../Concerns/InsertsGeometryFixtures.php';

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Jobs\App\ReindexAppContentsJob;
use Wm\WmPackage\Jobs\BuildAppPoisGeojsonJob;
use Wm\WmPackage\Jobs\TaxonomyWhere\RegenerateTaxonomyWhereOutputsJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;
use Wm\WmPackage\Jobs\UpdateUgcTrackDemStatsJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\TaxonomyPoiType;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Tests\Concerns\InsertsGeometryFixtures;

/**
 * `properties.taxonomies` nelle feature di `pois.geojson`, con la stessa forma del dettaglio delle
 * tracce (oc:8959). `taxonomyIdentifiers` resta com'era.
 */
uses(DatabaseTransactions::class, InsertsGeometryFixtures::class);

beforeEach(function () {
    config(['wm-package.shard_name' => 'test-shard']);
    Storage::fake('wmfe');
    // Ogni legame di un POI accoda la rigenerazione di pois.geojson: qui il file si costruisce a mano.
    Bus::fake([BuildAppPoisGeojsonJob::class, RegenerateTaxonomyWhereOutputsJob::class, UpdateAppConfigJob::class, UpdateEcTrackAwsJob::class, UpdateUgcTrackDemStatsJob::class]);
});

function poiFeature(App $app, int $poiId): array
{
    $feature = collect($app->fresh()->getAllPoisGeojson())->first(fn ($f) => ($f['properties']['id'] ?? null) === $poiId);
    expect($feature)->not->toBeNull();

    return $feature;
}

function poiTaxonomy(App $app, array $models): array
{
    $taxonomy = TaxonomyTerm::create(['identifier' => 'tx-'.uniqid(), 'name' => ['it' => 'Servizi'], 'properties' => ['tab' => 'Services']]);
    $term = TaxonomyTerm::create(['identifier' => 'term-'.uniqid(), 'parent_id' => $taxonomy->id, 'name' => ['it' => 'Parcheggio']]);
    $taxonomy->apps()->attach($app->id, ['models' => json_encode($models)]);

    return [$taxonomy, $term];
}

it('porta le tassonomie di un POI nella stessa forma del dettaglio delle tracce', function () {
    $app = App::factory()->createQuietly();
    $poi = EcPoi::find($this->insertEcPoiWithGeometry($app, ['taxonomy_where' => [
        'R40176' => ['it' => 'Atzara', '_admin_level' => 8, '_source' => 'osmfeatures'],
    ]]));
    $poiType = TaxonomyPoiType::withoutEvents(fn () => TaxonomyPoiType::create(['identifier' => 'fountain-'.uniqid(), 'name' => ['it' => 'Fontana']]));
    $activity = TaxonomyActivity::withoutEvents(fn () => TaxonomyActivity::create(['identifier' => 'act-'.uniqid(), 'name' => ['it' => 'Escursionismo']]));
    $poi->taxonomyPoiTypes()->attach($poiType->id);
    $poi->taxonomyActivities()->attach($activity->id);
    [$taxonomy, $term] = poiTaxonomy($app, ['EcPoi']);
    $poi->taxonomyTerms()->attach($term->id);

    $taxonomies = (array) poiFeature($app, $poi->id)['properties']['taxonomies'];

    expect($taxonomies['poi-type'])->toEqual([['identifier' => $poiType->identifier, 'name' => ['it' => 'Fontana'], 'tab' => 'General']])
        ->and($taxonomies['activity'][0]['identifier'])->toBe($activity->identifier)
        ->and($taxonomies['where'])->toEqual([['identifier' => 'R40176', 'name' => ['it' => 'Atzara'], 'tab' => 'General', 'admin_level' => 8, 'source' => 'osmfeatures']])
        ->and($taxonomies[$taxonomy->identifier])->toEqual([['identifier' => $term->identifier, 'name' => ['it' => 'Parcheggio'], 'tab' => 'Services', 'sort_order' => 0]]);
});

it('non porta nei POI le tassonomie accese solo per le tracce', function () {
    $app = App::factory()->createQuietly();
    $poi = EcPoi::find($this->insertEcPoiWithGeometry($app, []));
    [$taxonomy, $term] = poiTaxonomy($app, ['EcTrack']);
    $poi->taxonomyTerms()->attach($term->id);

    expect((array) poiFeature($app, $poi->id)['properties']['taxonomies'])->not->toHaveKey($taxonomy->identifier);
});

it('scrive taxonomies come oggetto JSON vuoto per un POI senza tassonomie', function () {
    $app = App::factory()->createQuietly();
    $poi = EcPoi::find($this->insertEcPoiWithGeometry($app, []));

    expect(json_encode(poiFeature($app, $poi->id)['properties']))->toContain('"taxonomies":{}');
});

it('lascia taxonomyIdentifiers com\'era', function () {
    $app = App::factory()->createQuietly();
    $poi = EcPoi::find($this->insertEcPoiWithGeometry($app, []));
    $poiType = TaxonomyPoiType::withoutEvents(fn () => TaxonomyPoiType::create(['identifier' => 'fountain-'.uniqid(), 'name' => ['it' => 'Fontana']]));
    $poi->taxonomyPoiTypes()->attach($poiType->id);
    [, $term] = poiTaxonomy($app, ['EcPoi']);
    $poi->taxonomyTerms()->attach($term->id);

    expect(poiFeature($app, $poi->id)['properties']['taxonomyIdentifiers'])
        ->toEqualCanonicalizing(['poi_type_'.$poiType->identifier, $term->identifier]);
});

it('rigenera pois.geojson quando le tassonomie accese nell\'app cambiano', function () {
    $app = App::factory()->createQuietly();
    $before = ReindexAppContentsJob::taxonomiesSignature($app);
    poiTaxonomy($app, ['EcPoi']);

    (new ReindexAppContentsJob($app->id, $before))->handle();

    // pois.geojson lo rigenera il job delle uscite pubbliche dell'app.
    Bus::assertDispatched(RegenerateTaxonomyWhereOutputsJob::class, fn ($job) => $job->appId === $app->id);
});
