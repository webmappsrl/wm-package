<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Laravel\Nova\Http\Requests\ResourceDetailRequest;
use Laravel\Nova\Http\Requests\ResourceIndexRequest;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Nova\AbstractUserResource;
use Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src\FeatureCollectionMap;
use Wm\WmPackage\Nova\UgcTrack as UgcTrackResource;

class UgcTrackLayerContextTestUserResource extends AbstractUserResource
{
    public static $model = User::class;
}

// AbstractUgcResource::fields() referenzia App\Nova\User, la risorsa dello shard.
if (! class_exists('App\\Nova\\User')) {
    class_alias(UgcTrackLayerContextTestUserResource::class, 'App\\Nova\\User');
}

beforeEach(function () {
    // Nel package da solo App\Models\EcTrack non esiste: Layer::ecTracks() userebbe una classe mancante.
    config(['wm-package.ec_track_model' => EcTrack::class]);
    $this->app_ = App::factory()->createQuietly();
    $this->user = User::factory()->create(['app_id' => $this->app_->id]);
    $this->ugc = UgcTrack::factory()->createQuietly([
        'user_id' => $this->user->id,
        'app_id' => $this->app_->id,
        'properties' => ['name' => 'ugc'],
    ]);
    // Traccia UGC di ~1 km attorno a (13.0, 43.0).
    DB::table('ugc_tracks')->where('id', $this->ugc->id)
        ->update(['geometry' => DB::raw("ST_Force3D(ST_GeomFromText('MULTILINESTRING((13.0 43.0, 13.01 43.01))', 4326))::geography")]);
});

function contextEcTrack(int $appId, string $wkt, string $name): EcTrack
{
    $track = EcTrack::factory()->createQuietly(['app_id' => $appId, 'name' => ['it' => $name]]);
    DB::table('ec_tracks')->where('id', $track->id)
        ->update(['geometry' => DB::raw("ST_Force3D(ST_GeomFromText('{$wkt}', 4326))::geography")]);

    return $track;
}

/**
 * Tappe di contesto intere: tolti i pezzi toccati dalla traccia (larghi CONTEXT_TOUCHED_WIDTH) e
 * i tratti a segmenti (onlyZoomedOut), che sono contesto anch'essi (oc:8776).
 *
 * @return list<array>
 */
function contextStages(array $features): array
{
    return array_values(array_filter($features, fn ($f) => ($f['properties']['context'] ?? false) === true
        && ! isset($f['properties']['onlyZoomedOut'])
        && $f['properties']['strokeWidth'] !== UgcTrack::CONTEXT_TOUCHED_WIDTH));
}

/** @return list<array> pezzi di tappa toccati dalla traccia (oc:8776) */
function touchedPortions(array $features): array
{
    return array_values(array_filter($features, fn ($f) => ($f['properties']['context'] ?? false) === true
        && ! isset($f['properties']['onlyZoomedOut'])
        && $f['properties']['strokeWidth'] === UgcTrack::CONTEXT_TOUCHED_WIDTH));
}

function contextLayer(int $appId, string $name): Layer
{
    return Layer::factory()->createQuietly(['app_id' => $appId, 'name' => ['it' => $name]]);
}

it('aggiunge come contesto le EcTrack dei layer dell\'App che passano nella zona', function () {
    $layer = contextLayer($this->app_->id, 'Cammino Test');
    $near = contextEcTrack($this->app_->id, 'MULTILINESTRING((12.99 43.005, 13.02 43.005))', 'Tappa vicina');
    $far = contextEcTrack($this->app_->id, 'MULTILINESTRING((15.0 45.0, 15.1 45.1))', 'Tappa lontana');
    $layer->ecTracks()->attach([$near->id, $far->id]);

    $otherApp = App::factory()->createQuietly();
    $otherLayer = contextLayer($otherApp->id, 'Altro cammino');
    $otherTrack = contextEcTrack($otherApp->id, 'MULTILINESTRING((13.0 43.0, 13.01 43.01))', 'Tappa altra app');
    $otherLayer->ecTracks()->attach($otherTrack->id);

    $features = $this->ugc->fresh()->getFeatureCollectionMap()['features'];
    $context = contextStages($features);

    expect($context)->toHaveCount(1);
    expect($context[0]['properties']['tooltip'])->toBe('Cammino Test — Tappa vicina');
    // Sottile: la traccia la incrocia, e il pezzo toccato si disegna a parte (oc:8776).
    expect($context[0]['properties']['strokeWidth'])->toBe(UgcTrack::CONTEXT_THIN_WIDTH);
    expect($context[0]['properties'])->not->toHaveKey('slopeChart');
    expect($context[0]['properties']['strokeColor'])->toBe(UgcTrack::CONTEXT_TRACK_PALETTE[0]);
    expect($context[0]['geometry']['type'])->toBe('MultiLineString');
    expect(count($context[0]['geometry']['coordinates'][0][0]))->toBe(2);

    // La traccia UGC resta l'unica linea del profilo altimetrico, disegnata sopra il contesto.
    $own = array_values(array_filter($features, fn ($f) => ! isset($f['properties']['context'])));
    expect($own)->toHaveCount(1);
    expect($own[0]['properties']['slopeChart'])->toBeTrue();
    expect(array_key_last($features))->toBe(array_search($own[0], $features, true));
});

it('la zona è il bbox della UGC allargato del 30% per lato, senza margine fisso', function () {
    // bbox UGC 13.0–13.01 × 43.0–43.01: con il 30% per lato la zona va da 12.997 a 13.013.
    $layer = contextLayer($this->app_->id, 'Cammino');
    $inside = contextEcTrack($this->app_->id, 'MULTILINESTRING((13.012 43.012, 13.05 43.05))', 'Dentro');
    $outside = contextEcTrack($this->app_->id, 'MULTILINESTRING((13.014 43.0, 13.05 43.0))', 'Appena fuori');
    $layer->ecTracks()->attach([$inside->id, $outside->id]);

    $tooltips = array_map(fn ($f) => $f['properties']['tooltip'], $this->ugc->fresh()->layerContextFeatures());

    expect($tooltips)->toBe(['Cammino — Dentro']);
});

it('il nome della tappa sta nel tooltip, non scritto lungo la linea', function () {
    $layer = contextLayer($this->app_->id, 'Cammino del Gran Sasso');
    $track = contextEcTrack($this->app_->id, 'MULTILINESTRING((12.99 43.005, 13.02 43.005))', 'Cammino del Gran Sasso - Tappa 05: Barisciano - Fonte Cerreto');
    $layer->ecTracks()->attach($track->id);

    $props = $this->ugc->fresh()->layerContextFeatures()[0]['properties'];

    expect($props)->not->toHaveKey('lineLabel');
    expect($props['tooltip'])->toBe('Cammino del Gran Sasso — Cammino del Gran Sasso - Tappa 05: Barisciano - Fonte Cerreto');
});

it('una EcTrack in più layer dell\'App compare una volta sola', function () {
    $track = contextEcTrack($this->app_->id, 'MULTILINESTRING((12.99 43.005, 13.02 43.005))', 'Tappa');
    contextLayer($this->app_->id, 'Layer A')->ecTracks()->attach($track->id);
    contextLayer($this->app_->id, 'Layer B')->ecTracks()->attach($track->id);

    $context = $this->ugc->fresh()->layerContextFeatures();

    expect($context)->toHaveCount(1);
    expect($context[0]['properties']['tooltip'])->toBe('Layer A, Layer B — Tappa');
});

it('sulla stessa mappa due cammini hanno colori diversi, lo stesso cammino lo stesso colore', function () {
    $layerA = contextLayer($this->app_->id, 'Cammino A');
    $layerB = contextLayer($this->app_->id, 'Cammino B');
    $a1 = contextEcTrack($this->app_->id, 'MULTILINESTRING((12.99 43.005, 13.02 43.005))', 'A1');
    $a2 = contextEcTrack($this->app_->id, 'MULTILINESTRING((12.99 43.006, 13.02 43.006))', 'A2');
    $b1 = contextEcTrack($this->app_->id, 'MULTILINESTRING((12.99 43.007, 13.02 43.007))', 'B1');
    $layerA->ecTracks()->attach([$a1->id, $a2->id]);
    $layerB->ecTracks()->attach($b1->id);

    $colors = collect($this->ugc->fresh()->layerContextFeatures())
        ->mapWithKeys(fn ($f) => [$f['properties']['tooltip'] => $f['properties']['strokeColor']]);

    expect($colors['Cammino A — A1'])->toBe($colors['Cammino A — A2']);
    expect($colors['Cammino A — A1'])->not->toBe($colors['Cammino B — B1']);
    // Ordine per id: il layer creato prima prende il primo colore.
    expect($colors['Cammino A — A1'])->toBe(UgcTrack::CONTEXT_TRACK_PALETTE[0]);
    expect($colors['Cammino B — B1'])->toBe(UgcTrack::CONTEXT_TRACK_PALETTE[1]);
});

it('con 8 cammini sulla mappa nessun colore si ripete', function () {
    for ($i = 0; $i < 8; $i++) {
        $track = contextEcTrack($this->app_->id, 'MULTILINESTRING((12.99 43.00'.$i.', 13.02 43.00'.$i.'))', 'T'.$i);
        contextLayer($this->app_->id, 'Cammino '.$i)->ecTracks()->attach($track->id);
    }

    $colors = array_map(fn ($f) => $f['properties']['strokeColor'], $this->ugc->fresh()->layerContextFeatures());

    expect($colors)->toHaveCount(8);
    expect(array_unique($colors))->toHaveCount(8);
});

it('i colori dipendono dagli id ordinati, non dall\'ordine di arrivo', function () {
    expect(UgcTrack::contextTrackColors([42, 7, 42, 13]))->toBe([
        7 => UgcTrack::CONTEXT_TRACK_PALETTE[0],
        13 => UgcTrack::CONTEXT_TRACK_PALETTE[1],
        42 => UgcTrack::CONTEXT_TRACK_PALETTE[2],
    ]);
    expect(UgcTrack::contextTrackColors(range(1, 9))[9])->toBe(UgcTrack::CONTEXT_TRACK_PALETTE[0]);
});

it('una EcTrack in più cammini prende il colore del layer con id più basso', function () {
    $shared = contextEcTrack($this->app_->id, 'MULTILINESTRING((12.99 43.005, 13.02 43.005))', 'Condivisa');
    $own = contextEcTrack($this->app_->id, 'MULTILINESTRING((12.99 43.006, 13.02 43.006))', 'Solo seconda');
    $first = contextLayer($this->app_->id, 'Primo');
    $second = contextLayer($this->app_->id, 'Secondo');
    $second->ecTracks()->attach([$shared->id, $own->id]);
    $first->ecTracks()->attach($shared->id);

    $colors = collect($this->ugc->fresh()->layerContextFeatures())
        ->mapWithKeys(fn ($f) => [$f['properties']['tooltip'] => $f['properties']['strokeColor']]);

    expect($colors['Primo, Secondo — Condivisa'])->toBe(UgcTrack::CONTEXT_TRACK_PALETTE[0]);
    expect($colors['Secondo — Solo seconda'])->toBe(UgcTrack::CONTEXT_TRACK_PALETTE[1]);
});

it('nessun contesto se la traccia UGC non ha App', function () {
    $track = contextEcTrack($this->app_->id, 'MULTILINESTRING((12.99 43.005, 13.02 43.005))', 'Tappa');
    contextLayer($this->app_->id, 'Layer')->ecTracks()->attach($track->id);
    $ugc = $this->ugc->fresh();
    $ugc->app_id = null;

    expect($ugc->layerContextFeatures())->toBe([]);
});

function contextLegend(UgcTrack $ugc, string $requestClass = ResourceDetailRequest::class): array
{
    $request = $requestClass::create('/nova-api/ugc-tracks/'.$ugc->id, 'GET');
    $field = (new UgcTrackResource($ugc->fresh()))
        ->availableFields($request)
        ->first(fn ($field) => $field->attribute === 'geometry');

    return $field->meta['legend'] ?? [];
}

it('la legenda del dettaglio ha una riga per cammino, col colore delle sue linee', function () {
    $this->actingAs($this->user);
    $layerA = contextLayer($this->app_->id, 'Cammino A');
    $layerB = contextLayer($this->app_->id, 'Cammino B');
    $a = contextEcTrack($this->app_->id, 'MULTILINESTRING((12.99 43.005, 13.02 43.005))', 'A1');
    $b = contextEcTrack($this->app_->id, 'MULTILINESTRING((12.99 43.006, 13.02 43.006))', 'B1');
    $layerA->ecTracks()->attach($a->id);
    $layerB->ecTracks()->attach($b->id);

    $legend = contextLegend($this->ugc);
    $featureColors = collect($this->ugc->fresh()->layerContextFeatures())
        ->mapWithKeys(fn ($f) => [explode(' — ', $f['properties']['tooltip'])[0] => $f['properties']['strokeColor']])->all();

    // Traccia UGC senza locations: nessun tratto ricostruito, solo traccia + cammini.
    expect($legend)->toHaveCount(3);
    expect($legend[0])->toBe(['label' => __('Recorded track'), 'color' => 'rgba(0, 0, 255, 1)', 'dash' => false]);
    expect($legend[1])->toBe(['label' => 'Cammino A', 'color' => $featureColors['Cammino A'], 'dash' => false]);
    expect($legend[2])->toBe(['label' => 'Cammino B', 'color' => $featureColors['Cammino B'], 'dash' => false]);
    expect($legend[1]['color'])->not->toBe($legend[2]['color']);
});

it('senza cammini né tratti ricostruiti nessuna legenda', function () {
    $this->actingAs($this->user);
    $far = contextEcTrack($this->app_->id, 'MULTILINESTRING((15.0 45.0, 15.1 45.1))', 'Lontana');
    contextLayer($this->app_->id, 'Lontano')->ecTracks()->attach($far->id);

    expect(contextLegend($this->ugc))->toBe([]);
    expect($this->ugc->fresh()->contextLegendItems())->toBe([]);
});

it('nell\'index non calcola la legenda dei cammini', function () {
    $this->actingAs($this->user);
    $track = contextEcTrack($this->app_->id, 'MULTILINESTRING((12.99 43.005, 13.02 43.005))', 'A1');
    contextLayer($this->app_->id, 'Cammino A')->ecTracks()->attach($track->id);

    expect(contextLegend($this->ugc, ResourceIndexRequest::class))->toBe([]);
});

it('nel dettaglio la mappa della UgcTrack ha extentMargin e lockZoomOut', function () {
    $this->actingAs($this->user);
    $request = ResourceDetailRequest::create('/nova-api/ugc-tracks/'.$this->ugc->id, 'GET');
    $field = (new UgcTrackResource($this->ugc->fresh()))
        ->availableFields($request)
        ->first(fn ($field) => $field->attribute === 'geometry');

    expect($field->meta['extentMargin'] ?? null)->toBe(UgcTrack::MAP_EXTENT_MARGIN);
    expect(UgcTrack::MAP_EXTENT_MARGIN)->toBe(0.3);
    expect($field->meta['lockZoomOut'] ?? null)->toBeTrue();
});

it('il campo di default non ha extentMargin né lockZoomOut', function () {
    $field = FeatureCollectionMap::make('Geometry', 'geometry');

    expect($field->meta)->not->toHaveKey('extentMargin');
    expect($field->meta)->not->toHaveKey('lockZoomOut');
});

it('il campo di default non ha stackedTooltip, la mappa della UgcTrack sì', function () {
    expect(FeatureCollectionMap::make('Geometry', 'geometry')->meta)->not->toHaveKey('stackedTooltip');

    $this->actingAs($this->user);
    $request = ResourceDetailRequest::create('/nova-api/ugc-tracks/'.$this->ugc->id, 'GET');
    $field = (new UgcTrackResource($this->ugc->fresh()))
        ->availableFields($request)
        ->first(fn ($field) => $field->attribute === 'geometry');

    expect($field->meta['stackedTooltip'] ?? null)->toBeTrue();
});

function contextRun(int $ugcId, string $status = 'computed'): void
{
    DB::table('ugc_track_matching_runs')->updateOrInsert(['ugc_track_id' => $ugcId], [
        'status' => $status, 'segment_length_meters' => 400, 'max_distance_meters' => 50,
        'computed_at' => '2026-10-13 10:00:00', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function contextMatch(int $ugcId, int $ecId, int $appId): void
{
    DB::table('ugc_track_ec_track_matches')->updateOrInsert(['ugc_track_id' => $ugcId, 'ec_track_id' => $ecId], [
        'app_id' => $appId, 'matched_segments' => json_encode([0, 1, 2, 3, 4]), 'total_segments' => 5,
        'segment_length_meters' => 400, 'max_distance_meters' => 50, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * UGC da 13.0 a 13.03 sul parallelo 43.005, poi un po' a nord: la tappa ne copre i primi 2/3.
 * La piega finale dà altezza al bbox, da cui dipende la zona del contesto.
 */
function ugcOnStage(object $test): EcTrack
{
    DB::table('ugc_tracks')->where('id', $test->ugc->id)
        ->update(['geometry' => DB::raw("ST_Force3D(ST_GeomFromText('MULTILINESTRING((13.0 43.005, 13.03 43.005, 13.03 43.008))', 4326))::geography")]);
    $stage = contextEcTrack($test->app_->id, 'MULTILINESTRING((13.0 43.005, 13.02 43.005))', 'Tappa');
    contextLayer($test->app_->id, 'Cammino A')->ecTracks()->attach($stage->id);

    return $stage;
}

it('senza contesto le proprietà della mappa restano quelle di prima', function () {
    $features = $this->ugc->fresh()->getFeatureCollectionMap()['features'];

    expect($features)->toHaveCount(1);
    expect($features[0]['properties'])->not->toHaveKey('zoomedOutStyle');
    expect($features[0]['properties'])->not->toHaveKey('casing');
});

it('tappe sottili, piene solo dove la traccia le tocca, e a segmenti da lontano dove i cammini sono più d\'uno', function () {
    // Traccia da 13.0 a 13.03 sul parallelo 43.005. A da 12.99 a 13.02 sullo stesso parallelo,
    // B ~22 m più a nord da 13.0 a 13.03: la traccia tocca A e B, insieme fra 13.0 e 13.02.
    DB::table('ugc_tracks')->where('id', $this->ugc->id)
        ->update(['geometry' => DB::raw("ST_Force3D(ST_GeomFromText('MULTILINESTRING((13.0 43.005, 13.03 43.005, 13.03 43.008))', 4326))::geography")]);
    $a = contextEcTrack($this->app_->id, 'MULTILINESTRING((12.99 43.005, 13.02 43.005))', 'Tappa A');
    $b = contextEcTrack($this->app_->id, 'MULTILINESTRING((13.0 43.0052, 13.03 43.0052))', 'Tappa B');
    contextLayer($this->app_->id, 'Cammino A')->ecTracks()->attach($a->id);
    contextLayer($this->app_->id, 'Cammino B')->ecTracks()->attach($b->id);

    $features = $this->ugc->fresh()->getFeatureCollectionMap()['features'];

    // Tappe intere, sottili, uguali a ogni zoom e senza etichetta lungo la linea.
    $tappe = contextStages($features);
    expect($tappe)->toHaveCount(2);
    foreach ($tappe as $tappa) {
        expect($tappa['properties']['strokeWidth'])->toBe(UgcTrack::CONTEXT_THIN_WIDTH);
        expect($tappa['properties'])->not->toHaveKey('zoomedOutStyle');
        expect($tappa['properties'])->not->toHaveKey('lineLabel');
    }

    // Pezzi toccati: a ogni zoom, nel colore del cammino, solo vicino alla traccia (lat ≤ 43.0057).
    $portions = touchedPortions($features);
    expect(array_values(array_unique(array_map(fn ($p) => $p['properties']['tooltip'], $portions))))->toEqualCanonicalizing(['Cammino A — Tappa A', 'Cammino B — Tappa B']);
    foreach ($portions as $portion) {
        expect($portion['properties'])->not->toHaveKey('zoomedOutStyle');
        foreach ($portion['geometry']['coordinates'] as $part) {
            foreach ($part as [$lon, $lat]) {
                expect($lon)->toBeGreaterThan(12.999);
            }
        }
    }

    // Da lontano, sul tratto toccato da A e B, segmenti nei colori di entrambi.
    $purple = 'rgba(147, 51, 234, 1)';
    $red = 'rgba(220, 38, 38, 1)';
    $dashed = array_values(array_filter($features, fn ($f) => ($f['properties']['onlyZoomedOut'] ?? false) === true));
    expect($dashed)->not->toBeEmpty();
    foreach ($dashed as $piece) {
        expect($piece['properties']['zoomedOutStyle'])->toMatchArray([
            'strokeWidth' => UgcTrack::CONTEXT_TOUCHED_WIDTH,
            'dashColors' => [$purple, $red],
            'dashLength' => 8,
            'zIndex' => -0.5,
        ]);
        expect($piece['properties']['tooltip'])->toBe("Cammino A — Tappa A\nCammino B");
    }
});

it('nel tooltip la traccia si chiama «Traccia registrata» e viene prima dei tratti ricostruiti', function () {
    ugcOnStage($this);

    $own = array_values(array_filter($this->ugc->fresh()->getFeatureCollectionMap()['features'], fn ($f) => ($f['properties']['slopeChart'] ?? false) === true));

    expect($own[0]['properties']['tooltip'])->toBe(__('Recorded track'));
    expect($own[0]['properties']['tooltipOrder'])->toBe(0);
});

it('se il calcolo dei tratti condivisi fallisce le tappe restano come sono', function () {
    $a = contextEcTrack($this->app_->id, 'MULTILINESTRING((12.99 43.005, 13.02 43.005))', 'Tappa A');
    contextLayer($this->app_->id, 'Cammino A')->ecTracks()->attach($a->id);
    // Tolleranza non valida: il servizio fallisce e restituisce null.
    config(['wm-package.ugc_track_matching.max_distance_meters' => -1]);

    $features = $this->ugc->fresh()->getFeatureCollectionMap()['features'];

    expect(array_filter($features, fn ($f) => isset($f['properties']['onlyZoomedOut'])))->toBe([]);
    foreach (array_filter($features, fn ($f) => isset($f['properties']['context'])) as $tappa) {
        expect($tappa['properties'])->not->toHaveKey('zoomedOutStyle');
    }
});

it('la soglia è la tolleranza in pixel di linea, corretta per la latitudine', function () {
    $ugc = $this->ugc->fresh();
    $at43 = $ugc->zoomedOutMinResolution(50);
    expect($at43)->toEqualWithDelta(50 / 5 / cos(deg2rad(43.005)), 0.01);

    DB::table('ugc_tracks')->where('id', $ugc->id)
        ->update(['geometry' => DB::raw("ST_Force3D(ST_GeomFromText('MULTILINESTRING((13.0 60.0, 13.01 60.01))', 4326))::geography")]);
    expect($ugc->fresh()->zoomedOutMinResolution(50))->toBeGreaterThan($at43);
});

it('con le tappe di contesto la traccia ha sempre la stessa misura, con il contorno', function () {
    $stage = ugcOnStage($this);
    contextRun($this->ugc->id);
    contextMatch($this->ugc->id, $stage->id, $this->app_->id);

    $features = $this->ugc->fresh()->getFeatureCollectionMap()['features'];
    $own = array_values(array_filter($features, fn ($f) => ($f['properties']['slopeChart'] ?? false) === true));

    expect($own)->toHaveCount(1);
    expect($own[0]['properties'])->toMatchArray([
        'strokeColor' => UgcTrack::TRACK_COLOR,
        'strokeWidth' => 1.5,
        'casing' => ['color' => 'rgba(255, 255, 255, 1)', 'width' => 2.5],
    ]);
    expect($own[0]['properties'])->not->toHaveKey('zoomedOutStyle');
    // Nessun riempimento: solo i tratti dei cammini esistono solo da lontano.
    expect(array_filter($features, fn ($f) => ($f['properties']['onlyZoomedOut'] ?? false) === true && ! isset($f['properties']['context'])))->toBe([]);
    // La traccia resta l'ultima feature: sopra a parità di zIndex, come prima.
    expect(array_key_last($features))->toBe(array_search($own[0], $features, true));
});

it('la misura della traccia non dipende dalla corrispondenza', function () {
    ugcOnStage($this);

    $own = array_values(array_filter($this->ugc->fresh()->getFeatureCollectionMap()['features'], fn ($f) => ($f['properties']['slopeChart'] ?? false) === true));

    expect($own[0]['properties']['strokeWidth'])->toBe(1.5);
    expect($own[0]['properties']['casing']['width'])->toBe(2.5);
});

it('con la corrispondenza calcolata mostra solo le tappe toccate dalla traccia, anche in legenda', function () {
    $stage = ugcOnStage($this);
    // Tappa vicina di un altro cammino, sullo stesso sentiero ma non percorsa secondo la corrispondenza.
    $near = contextEcTrack($this->app_->id, 'MULTILINESTRING((13.0 43.0052, 13.02 43.0052))', 'Tappa vicina');
    contextLayer($this->app_->id, 'Cammino B')->ecTracks()->attach($near->id);
    contextRun($this->ugc->id);
    contextMatch($this->ugc->id, $stage->id, $this->app_->id);

    $ugc = $this->ugc->fresh();
    $context = $ugc->layerContextFeatures();

    expect(array_map(fn ($f) => $f['properties']['tooltip'], $context))->toBe(['Cammino A — Tappa']);
    expect($context[0]['properties']['strokeWidth'])->toBe(5);
    expect(array_map(fn ($i) => $i['label'], $ugc->contextLegendItems()))->toBe(['Cammino A']);
    // Nessun tratto condiviso: resta una tappa sola.
    expect(array_filter($ugc->getFeatureCollectionMap()['features'], fn ($f) => ($f['properties']['onlyZoomedOut'] ?? false) === true))->toBe([]);
});

it('senza corrispondenza calcolata mostra tutte le tappe della zona', function () {
    ugcOnStage($this);
    $near = contextEcTrack($this->app_->id, 'MULTILINESTRING((13.0 43.0052, 13.02 43.0052))', 'Tappa vicina');
    contextLayer($this->app_->id, 'Cammino B')->ecTracks()->attach($near->id);

    $context = $this->ugc->fresh()->layerContextFeatures();

    expect($context)->toHaveCount(2);
    foreach ($context as $f) {
        expect($f['properties']['strokeWidth'])->toBe(5);
    }
});
