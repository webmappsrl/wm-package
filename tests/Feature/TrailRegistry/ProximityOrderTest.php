<?php

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

beforeEach(function () {
    runTrailRegistryStubs();
});

it('crea un codice con la geometria richiesta', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $id = makeCode([
        'number' => 11,
        'status' => TrailCodeStatus::Assigned,
        'geometry_wkt' => 'LINESTRING Z (1 1 0, 1.001 1.001 0)',
    ]);

    $wkt = DB::selectOne(<<<'SQL'
        SELECT ST_AsText(t.geometry) AS wkt
        FROM trail_registry_codes c
        JOIN ec_tracks t ON t.id = c.ec_track_id
        WHERE c.id = ?
    SQL, [$id])->wkt;

    expect($wkt)->toContain('1 1');
});

it('ordina i liberi per distanza numerica dal cluster piu vicino', function () {
    // Cluster A = {11,12,13} a 10 m; cluster B = {40} a 500 m.
    // Governa A: dal suo bordo escono prima 10 e 14, poi 9 e 15.
    $ordered = app(TrailRegistryService::class)->orderByProximity(
        [9, 10, 14, 15, 39, 41],
        [11 => 10.0, 12 => 10.0, 13 => 10.0, 40 => 500.0],
    );

    expect($ordered)->toBe([10, 14, 9, 15, 39, 41]);
});

it('a parita di distanza numerica sceglie il precedente', function () {
    $ordered = app(TrailRegistryService::class)->orderByProximity(
        [14, 12],
        [13 => 5.0],
    );

    expect($ordered)->toBe([12, 14]);
});

it('senza codici nel settore lascia l ordine numerico', function () {
    $ordered = app(TrailRegistryService::class)->orderByProximity([3, 1, 2], []);

    expect($ordered)->toBe([1, 2, 3]);
});

it('non perde nessun numero: ordinare non e filtrare', function () {
    $available = range(0, 99);
    unset($available[20]);

    $ordered = app(TrailRegistryService::class)
        ->orderByProximity(array_values($available), [20 => 1.0]);

    expect($ordered)->toHaveCount(99)
        ->and(array_diff(array_values($available), $ordered))->toBe([]);
});

it('e deterministico: due chiamate danno lo stesso ordine', function () {
    // Il determinismo che conta e' verso l'ordine con cui il database
    // restituisce le righe: passando lo stesso contenuto in due ordini di
    // inserimento diversi il risultato non deve cambiare.
    $service = app(TrailRegistryService::class);

    $ordinatoUno = $service->orderByProximity([5, 6, 50, 51], [7 => 42.0, 49 => 42.0]);
    $ordinatoDue = $service->orderByProximity([5, 6, 50, 51], [49 => 42.0, 7 => 42.0]);

    expect($ordinatoUno)->toBe($ordinatoDue);
});

it('a parita di distanza geografica governa il cluster col numero piu basso', function () {
    $ordered = app(TrailRegistryService::class)->orderByProximity(
        [5, 6, 50, 51],
        [7 => 42.0, 49 => 42.0],
    );

    // Vince il cluster {7}: 6 dista 1, 5 dista 2.
    expect($ordered[0])->toBe(6)
        ->and($ordered[1])->toBe(5);
});

it('un solo codice nel settore fa cluster da solo', function () {
    // Cluster {50}: 98 dista 48, 1 e 99 distano 49 (a parita' vince il
    // precedente), 0 dista 50.
    $ordered = app(TrailRegistryService::class)->orderByProximity(
        [0, 1, 98, 99],
        [50 => 3.0],
    );

    expect($ordered)->toBe([98, 1, 99, 0]);
});

it('misura la distanza dei numeri usati dalla traccia in esame', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    makeCode([
        'number' => 11,
        'status' => TrailCodeStatus::Assigned,
        'geometry_wkt' => 'LINESTRING Z (1 1 0, 1.001 1.001 0)',
    ]);
    makeCode([
        'number' => 40,
        'status' => TrailCodeStatus::Assigned,
        'geometry_wkt' => 'LINESTRING Z (9 9 0, 9.001 9.001 0)',
    ]);

    $service = app(TrailRegistryService::class);
    $method = new ReflectionMethod($service, 'usedNumbersWithDistance');
    $distances = $method->invoke($service, 'ZNUB5', 'MULTILINESTRING Z ((1 1 0, 1.002 1.002 0))');

    expect($distances)->toHaveKeys([11, 40])
        ->and($distances[11])->toBeLessThan($distances[40]);
});
