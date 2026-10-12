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
    $context = array_values(array_filter($features, fn ($f) => ($f['properties']['context'] ?? false) === true));

    expect($context)->toHaveCount(1);
    expect($context[0]['properties']['tooltip'])->toBe('Cammino Test — Tappa vicina');
    expect($context[0]['properties']['lineLabel'])->toBe('Tappa vicina');
    expect($context[0]['properties']['strokeWidth'])->toBe(3);
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

it('l\'etichetta lungo la linea è il nome intero della tappa', function () {
    $layer = contextLayer($this->app_->id, 'Cammino del Gran Sasso');
    $track = contextEcTrack($this->app_->id, 'MULTILINESTRING((12.99 43.005, 13.02 43.005))', 'Cammino del Gran Sasso - Tappa 05: Barisciano - Fonte Cerreto');
    $layer->ecTracks()->attach($track->id);

    $props = $this->ugc->fresh()->layerContextFeatures()[0]['properties'];

    expect($props['lineLabel'])->toBe('Cammino del Gran Sasso - Tappa 05: Barisciano - Fonte Cerreto');
    expect($props['tooltip'])->toBe('Cammino del Gran Sasso — Cammino del Gran Sasso - Tappa 05: Barisciano - Fonte Cerreto');
});

it('una EcTrack in più layer dell\'App compare una volta sola', function () {
    $track = contextEcTrack($this->app_->id, 'MULTILINESTRING((12.99 43.005, 13.02 43.005))', 'Tappa');
    contextLayer($this->app_->id, 'Layer A')->ecTracks()->attach($track->id);
    contextLayer($this->app_->id, 'Layer B')->ecTracks()->attach($track->id);

    $context = $this->ugc->fresh()->layerContextFeatures();

    expect($context)->toHaveCount(1);
    expect($context[0]['properties']['tooltip'])->toBe('Layer A, Layer B — Tappa');
    expect($context[0]['properties']['lineLabel'])->toBe('Tappa');
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
