<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Services\Models\LayerService;

/**
 * Un layer che filtra solo per termini, senza attività né zone (oc:8910).
 *
 * È la forma normale del modello unico: «Tipo percorso = sentiero» sostituisce l'attività
 * `sardegnasentieri:type:sentiero` di prima. Il package però cercava i filtri solo fra zone e
 * attività, e un layer così risultava «senza filtri»: `updateLayersPropertyOnLayeredFeature()`
 * lo interpretava come «non possiede nessun contenuto» e toglieva l'id del layer da
 * `properties.layers` di tutte le tracce — il campo che leggono l'app e le API. Nello stesso
 * vuoto cadeva `scopeOnLayer()`, che senza criteri restituiva tutte le tracce dell'app.
 */
uses(DatabaseTransactions::class);

function layerOnlyTermsFixture(): array
{
    $app = App::factory()->createQuietly();
    $layer = Layer::factory()->createQuietly([
        'app_id' => $app->id,
        'configuration' => ['track_mode' => 'auto'],
    ]);

    $routeType = TaxonomyTerm::create(['identifier' => 'route-type-'.uniqid(), 'name' => ['it' => 'Tipo percorso']]);
    $trail = TaxonomyTerm::create([
        'identifier' => 'route-type-trail-'.uniqid(),
        'parent_id' => $routeType->id,
        'name' => ['it' => 'Sentiero'],
    ]);
    $walk = TaxonomyTerm::create([
        'identifier' => 'route-type-walk-'.uniqid(),
        'parent_id' => $routeType->id,
        'name' => ['it' => 'Cammino'],
    ]);

    $difficulty = TaxonomyTerm::create(['identifier' => 'mtb-difficulty-'.uniqid(), 'name' => ['it' => 'Difficoltà MTB']]);
    $bc = TaxonomyTerm::create([
        'identifier' => 'mtb-difficulty-bc-'.uniqid(),
        'parent_id' => $difficulty->id,
        'name' => ['it' => 'BC'],
    ]);

    return compact('app', 'layer', 'trail', 'walk', 'bc');
}

function trackWithTerms(App $app, array $termIds): EcTrack
{
    $track = EcTrack::factory()->createQuietly(['app_id' => $app->id, 'osmid' => null, 'properties' => []]);
    $track->taxonomyTerms()->attach($termIds);

    return $track;
}

it('vede come candidate le sole tracce che hanno il termine del layer (oc:8910)', function () {
    ['app' => $app, 'layer' => $layer, 'trail' => $trail, 'walk' => $walk] = layerOnlyTermsFixture();
    $sentiero = trackWithTerms($app, [$trail->id]);
    $cammino = trackWithTerms($app, [$walk->id]);
    $layer->taxonomyTerms()->attach($trail->id);
    $layer->load('taxonomyTerms');

    $candidates = (new EcTrack)->onLayer($layer)->pluck('id')->all();

    expect($candidates)->toContain($sentiero->id)
        ->and($candidates)->not->toContain($cammino->id);
});

it('dentro la stessa tassonomia i termini sono in OR (oc:8910)', function () {
    ['app' => $app, 'layer' => $layer, 'trail' => $trail, 'walk' => $walk] = layerOnlyTermsFixture();
    $sentiero = trackWithTerms($app, [$trail->id]);
    $cammino = trackWithTerms($app, [$walk->id]);
    $layer->taxonomyTerms()->attach([$trail->id, $walk->id]);
    $layer->load('taxonomyTerms');

    $candidates = (new EcTrack)->onLayer($layer)->pluck('id')->all();

    expect($candidates)->toContain($sentiero->id)
        ->and($candidates)->toContain($cammino->id);
});

it('fra tassonomie diverse i termini sono in AND (oc:8910)', function () {
    ['app' => $app, 'layer' => $layer, 'trail' => $trail, 'bc' => $bc] = layerOnlyTermsFixture();
    $soloSentiero = trackWithTerms($app, [$trail->id]);
    $sentieroBc = trackWithTerms($app, [$trail->id, $bc->id]);
    $layer->taxonomyTerms()->attach([$trail->id, $bc->id]);
    $layer->load('taxonomyTerms');

    $candidates = (new EcTrack)->onLayer($layer)->pluck('id')->all();

    expect($candidates)->toContain($sentieroBc->id)
        ->and($candidates)->not->toContain($soloSentiero->id);
});

it('scrive properties.layers sulle tracce di un layer a soli termini (oc:8910)', function () {
    // Senza i termini fra i filtri validi il layer risultava senza contenuti, e questo metodo
    // toglieva il suo id da properties.layers invece di scriverlo.
    ['app' => $app, 'layer' => $layer, 'trail' => $trail, 'walk' => $walk] = layerOnlyTermsFixture();
    $sentiero = trackWithTerms($app, [$trail->id]);
    $cammino = trackWithTerms($app, [$walk->id]);
    $layer->taxonomyTerms()->attach($trail->id);
    $layer->load('taxonomyTerms');

    app(LayerService::class)->updateLayersPropertyOnLayeredFeature($layer, EcTrack::class);

    expect($sentiero->fresh()->properties['layers'] ?? [])->toContain($layer->id)
        ->and($cammino->fresh()->properties['layers'] ?? [])->not->toContain($layer->id);
});

it('toglie properties.layers alla traccia che il termine non ce l_ha più (oc:8910)', function () {
    ['app' => $app, 'layer' => $layer, 'trail' => $trail] = layerOnlyTermsFixture();
    $track = trackWithTerms($app, [$trail->id]);
    $layer->taxonomyTerms()->attach($trail->id);
    $layer->load('taxonomyTerms');
    app(LayerService::class)->updateLayersPropertyOnLayeredFeature($layer, EcTrack::class);
    expect($track->fresh()->properties['layers'] ?? [])->toContain($layer->id);

    $track->taxonomyTerms()->detach($trail->id);
    app(LayerService::class)->updateLayersPropertyOnLayeredFeature($layer, EcTrack::class);

    expect($track->fresh()->properties['layers'] ?? [])->not->toContain($layer->id);
});
