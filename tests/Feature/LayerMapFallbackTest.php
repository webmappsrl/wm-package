<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Services\Models\LayerService;

/**
 * La mappa della scheda di un Layer, quando `layerables` è ancora vuoto (oc:8910).
 *
 * Succede subito dopo aver salvato i filtri e dopo un reset, prima che il ricalcolo passi: la
 * mappa non resta vuota, ricava le tracce dai filtri. Quel calcolo era però scritto una seconda
 * volta dentro il modello, e divergeva da `LayerService`: teneva tutti i termini in un `whereIn`
 * unico — OR fra tassonomie diverse invece di AND — e mostrava più tracce del dovuto. Peggio,
 * le scriveva nel pivot con un `sync()`, dentro una GET: `LayerableObserver` scattava riga per
 * riga, riscrivendo `properties.layers` e accodando un job di geometria e una catena PBF per
 * ogni traccia. Il risultato sbagliato finiva così nei dati che leggono l'app e le API.
 */
uses(DatabaseTransactions::class);

function layerWithTwoFilters(): array
{
    config(['wm-package.shard_name' => 'test_shard']);
    Storage::fake('pois');
    Storage::fake('wmfe');
    Storage::fake('conf');

    $suffix = uniqid();
    $app = App::factory()->createQuietly(['map_bbox' => '[8.0,38.8,9.9,41.3]']);
    $layer = Layer::factory()->createQuietly(['app_id' => $app->id, 'properties' => [], 'configuration' => []]);

    $routeType = TaxonomyTerm::create(['identifier' => "route-type-{$suffix}", 'name' => ['it' => 'Tipo percorso']]);
    $trail = TaxonomyTerm::create(['parent_id' => $routeType->id, 'identifier' => "rt-trail-{$suffix}", 'name' => ['it' => 'Sentiero']]);
    $difficulty = TaxonomyTerm::create(['identifier' => "mtb-difficulty-{$suffix}", 'name' => ['it' => 'Difficoltà MTB']]);
    $bc = TaxonomyTerm::create(['parent_id' => $difficulty->id, 'identifier' => "mtb-bc-{$suffix}", 'name' => ['it' => 'BC']]);

    $soloSentiero = [];
    for ($i = 0; $i < 3; $i++) {
        $t = EcTrack::factory()->createQuietly(['app_id' => $app->id, 'osmid' => null]);
        $t->taxonomyTerms()->attach($trail->id);
        $soloSentiero[] = $t->id;
    }
    $sentieroBc = [];
    for ($i = 0; $i < 2; $i++) {
        $t = EcTrack::factory()->createQuietly(['app_id' => $app->id, 'osmid' => null]);
        $t->taxonomyTerms()->attach([$trail->id, $bc->id]);
        $sentieroBc[] = $t->id;
    }

    // «Sentiero E difficoltà BC»: solo le due che hanno entrambi i termini.
    $layer->taxonomyTerms()->attach([$trail->id, $bc->id]);
    $layer->load('taxonomyTerms');

    return [$layer->fresh(), $sentieroBc, $soloSentiero];
}

it('mostra le tracce che soddisfano tutti i filtri, non una qualsiasi (oc:8910)', function () {
    [$layer, $sentieroBc] = layerWithTwoFilters();
    expect(DB::table('layerables')->where('layer_id', $layer->id)->count())->toBe(0);

    $features = $layer->getFeatureCollectionMap();

    expect($features['features'])->toHaveCount(count($sentieroBc));
});

it('non scrive niente nel pivot: è una lettura (oc:8910)', function () {
    [$layer] = layerWithTwoFilters();

    $layer->getFeatureCollectionMap();

    expect(DB::table('layerables')->where('layer_id', $layer->id)->count())->toBe(0);
});

it('legge le stesse tracce che il ricalcolo scriverebbe nel pivot (oc:8910)', function () {
    // È il punto: una regola sola. Se le due strade divergono di nuovo, questo test cade.
    [$layer] = layerWithTwoFilters();

    // `properties.id` della feature è il progressivo del disegno, non la traccia: l'id della
    // traccia sta nel link alla sua scheda Nova.
    $dallaMappa = collect($layer->getFeatureCollectionMap()['features'])
        ->map(fn (array $f) => (int) basename((string) ($f['properties']['link'] ?? '')))
        ->filter()->sort()->values()->all();
    $dalServizio = collect(app(LayerService::class)->trackIdsMatchingLayerFilters($layer))
        ->sort()->values()->all();

    expect($dallaMappa)->toBe($dalServizio);
});
