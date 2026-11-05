<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Jobs\BuildAppPoisGeojsonJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\TaxonomyTerm;

/**
 * I termini di un POI devono arrivare fino al frontend (oc:8910).
 *
 * Per i POI il percorso non è Elasticsearch: `EcPoi` non è Searchable, l'app non interroga le
 * API e legge `pois.geojson`, dove i filtri si risolvono su `properties.taxonomyIdentifiers`.
 * Mancavano due anelli: gli identifier dei termini non finivano in quella lista, e un termine
 * cambiato in Nova non faceva rigenerare il file.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    // Senza, StorageService::getShardName() torna null e il geojson non si costruisce; i dischi
    // vanno simulati o la costruzione del geojson va a cercare S3.
    config(['wm-package.shard_name' => 'test_shard']);
    Storage::fake('pois');
    Storage::fake('wmfe');
    Storage::fake('conf');
});

function poiWithTerm(): array
{
    $app = App::factory()->createQuietly();
    $taxonomy = TaxonomyTerm::create([
        'identifier' => 'poi-kind-'.uniqid(),
        'name' => ['it' => 'Tipo punto'],
    ]);
    $term = TaxonomyTerm::create([
        'parent_id' => $taxonomy->id,
        'identifier' => 'poi-kind-fountain-'.uniqid(),
        'name' => ['it' => 'Fontana'],
    ]);
    $poi = EcPoi::factory()->createQuietly(['app_id' => $app->id, 'properties' => []]);

    return [$app, $poi, $term];
}

it('mette gli identifier dei termini fra i taxonomyIdentifiers del geojson (oc:8910)', function () {
    [$app, $poi, $term] = poiWithTerm();
    $poi->taxonomyTerms()->attach($term->id);

    $features = $app->fresh()->getAllPoisGeojson();

    $feature = collect($features)->first(fn ($f) => ($f['properties']['id'] ?? null) === $poi->id);
    expect($feature)->not->toBeNull()
        ->and($feature['properties']['taxonomyIdentifiers'])->toContain($term->identifier);
});

it('un POI senza termini non porta identifier in più (oc:8910)', function () {
    [$app, $poi] = poiWithTerm();

    $features = $app->fresh()->getAllPoisGeojson();

    $feature = collect($features)->first(fn ($f) => ($f['properties']['id'] ?? null) === $poi->id);
    expect($feature)->not->toBeNull()
        ->and($feature['properties']['taxonomyIdentifiers'])->toBe([]);
});

it('cambiare un termine di un POI fa rigenerare il geojson dell_app (oc:8910)', function () {
    // L'observer usciva subito perché EcPoi non è Searchable: il file restava quello vecchio e
    // il filtro a frontend continuava a non vedere il termine.
    [$app, $poi, $term] = poiWithTerm();
    Bus::fake();

    $poi->taxonomyTerms()->attach($term->id);

    Bus::assertDispatched(
        BuildAppPoisGeojsonJob::class,
        fn (BuildAppPoisGeojsonJob $job) => (new ReflectionProperty($job, 'appId'))->getValue($job) === $app->id
    );
});

it('togliere un termine da un POI fa rigenerare il geojson (oc:8910)', function () {
    [$app, $poi, $term] = poiWithTerm();
    $poi->taxonomyTerms()->attach($term->id);
    Bus::fake();

    $poi->taxonomyTerms()->detach($term->id);

    Bus::assertDispatched(BuildAppPoisGeojsonJob::class);
});
