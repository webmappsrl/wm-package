<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Services\Models\LayerService;

/**
 * Il ramo POI del filtro per termine (oc:8910).
 *
 * Era rimasto scoperto, e dentro usava le variabili del ramo delle tracce: ogni salvataggio
 * delle zone di un layer passava di qui — `TaxonomyWhereablesObserver` chiama
 * `assignPoisByTaxonomy()` — e moriva con «Undefined variable $trackTable», senza che si
 * potesse salvare il layer.
 */
uses(DatabaseTransactions::class);

it('assegna al layer i POI che hanno il termine, senza rompersi sul ramo dei POI', function () {
    $app = App::factory()->createQuietly();
    $layer = Layer::factory()->createQuietly([
        'app_id' => $app->id,
        'configuration' => ['poi_mode' => 'auto', 'track_mode' => 'auto'],
    ]);

    $taxonomy = TaxonomyTerm::create(['identifier' => 'poi-kind-'.uniqid(), 'name' => ['it' => 'Tipo punto']]);
    $term = TaxonomyTerm::create([
        'identifier' => 'poi-kind-fountain-'.uniqid(),
        'parent_id' => $taxonomy->id,
        'name' => ['it' => 'Fontana'],
    ]);

    // La factory del package scrive `properties` con `json_encode()`, cioè una stringa, mentre
    // il modello la casta ad array: qui si passa l'array, come fa il codice vero.
    $poi = EcPoi::factory()->createQuietly(['app_id' => $app->id, 'properties' => []]);
    $poi->taxonomyTerms()->attach($term->id);
    $layer->taxonomyTerms()->attach($term->id);

    app(LayerService::class)->assignPoisByTaxonomy($layer->fresh());

    expect(DB::table('layerables')
        ->where('layer_id', $layer->id)
        ->where('layerable_id', $poi->id)
        ->exists())->toBeTrue();
});

it('non assegna un POI che il termine non ce l ha', function () {
    $app = App::factory()->createQuietly();
    $layer = Layer::factory()->createQuietly([
        'app_id' => $app->id,
        'configuration' => ['poi_mode' => 'auto', 'track_mode' => 'auto'],
    ]);

    $taxonomy = TaxonomyTerm::create(['identifier' => 'poi-kind-'.uniqid(), 'name' => ['it' => 'Tipo punto']]);
    $term = TaxonomyTerm::create([
        'identifier' => 'poi-kind-shelter-'.uniqid(),
        'parent_id' => $taxonomy->id,
        'name' => ['it' => 'Rifugio'],
    ]);

    $foreign = EcPoi::factory()->createQuietly(['app_id' => $app->id, 'properties' => []]);
    $layer->taxonomyTerms()->attach($term->id);

    app(LayerService::class)->assignPoisByTaxonomy($layer->fresh());

    expect(DB::table('layerables')
        ->where('layer_id', $layer->id)
        ->where('layerable_id', $foreign->id)
        ->exists())->toBeFalse();
});
