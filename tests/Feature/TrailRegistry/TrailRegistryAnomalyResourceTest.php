<?php

use Wm\WmPackage\TrailRegistry\Enums\TrailRegistryAnomalyType;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;
use Wm\WmPackage\TrailRegistry\Nova\AnomalyDetailRenderer;
use Wm\WmPackage\TrailRegistry\Nova\TrailRegistryAnomaly as AnomalyResource;

beforeEach(fn () => runTrailRegistryStubs());

it('compone un titolo leggibile senza far esplodere l enum', function () {
    // Regressione: con `public static $title = 'type'` Nova convertiva
    // l'enum in stringa e la pagina rispondeva 500 (Resource.php:416).
    $anomaly = new TrailRegistryAnomaly([
        'ec_track_id' => 42,
        'type' => TrailRegistryAnomalyType::CodiceGiaAssegnato,
    ]);

    expect((new AnomalyResource($anomaly))->title())
        ->toBe('codice_gia_assegnato · #42');
});

it('regge un record vuoto, come quando Nova costruisce le colonne', function () {
    expect(AnomalyDetailRenderer::render(new TrailRegistryAnomaly))->toBeString();
    expect((new AnomalyResource(new TrailRegistryAnomaly))->title())->toBeString();
});

it('un anomalia senza traccia ha un titolo senza cancelletto vuoto', function () {
    $anomaly = new TrailRegistryAnomaly(['type' => 'codice_gia_assegnato', 'ec_track_id' => null]);

    expect((new AnomalyResource($anomaly))->title())->toBe('codice_gia_assegnato');
});

it('la mappa di un anomalia senza traccia e una collezione vuota', function () {
    $anomaly = new TrailRegistryAnomaly(['type' => 'shard_tipo_esempio', 'ec_track_id' => null]);

    expect($anomaly->getFeatureCollectionMap()['features'])->toBe([]);
});
