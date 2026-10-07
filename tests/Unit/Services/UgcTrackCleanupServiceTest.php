<?php

declare(strict_types=1);

use Wm\WmPackage\Services\Models\UgcTrackCleanupService;

// Base TestCase (Wm\WmPackage\Tests\TestCase) applicato globalmente da tests/Pest.php.

function cleanupPoint(float $lat, float $lon, ?float $accuracy, int $time, ?float $altitude = 100.0): array
{
    $point = ['latitude' => $lat, 'longitude' => $lon, 'time' => $time];
    if ($accuracy !== null) {
        $point['accuracy'] = $accuracy;
    }
    if ($altitude !== null) {
        $point['altitude'] = $altitude;
    }

    return $point;
}

beforeEach(function () {
    config()->set('wm-package.ugc_track_max_accuracy_meters', 40.0);
    config()->set('wm-package.ugc_track_max_deviation_meters', 50.0);
    $this->service = UgcTrackCleanupService::make();
});

/**
 * Spostamento verso nord, in gradi di latitudine, pari a $meters metri (R = 6371000).
 */
function metersNorth(float $meters): float
{
    return rad2deg($meters / 6371000);
}

/** @param  array<int, mixed>  $locations */
function flagsOf(UgcTrackCleanupService $service, array $locations): array
{
    return $service->keptFlags($locations);
}

it('tiene i punti buoni: accuracy sotto o uguale alla soglia, assente o negativa', function () {
    $locations = [
        cleanupPoint(43.0, 13.0, 40.0, 0),
        cleanupPoint(43.0, 13.001, null, 1000),
        cleanupPoint(43.0, 13.002, -1.0, 2000),
        ['latitude' => 43.0, 'longitude' => 13.003, 'accuracy' => 'n/d'],
    ];

    expect(flagsOf($this->service, $locations))->toBe([true, true, true, true]);
});

it('scarta sempre il punto (0,0), anche con accuracy buona, ma non (0, lon)', function () {
    $locations = [
        cleanupPoint(0.0, 0.0, 5.0, 0),
        cleanupPoint(0.0, 13.0, 5.0, 1000),
    ];

    expect(flagsOf($this->service, $locations))->toBe([false, true]);
});

it('scarta i punti senza coordinate numeriche, non finite o fuori range', function () {
    $locations = [
        cleanupPoint(43.0, 13.0, 5, 0),
        ['latitude' => 'x', 'longitude' => 13.0, 'accuracy' => 5],
        ['longitude' => 13.0, 'accuracy' => 5],
        'non un punto',
        ['latitude' => '1e999', 'longitude' => 13.0, 'accuracy' => 5],
        ['latitude' => 43.0, 'longitude' => '1e999', 'accuracy' => 5],
        cleanupPoint(95.0, 13.0, 5, 0),
        cleanupPoint(43.0, 181.0, 5, 0),
        cleanupPoint(43.0, 13.001, 5, 1000),
    ];

    expect(flagsOf($this->service, $locations))
        ->toBe([true, false, false, false, false, false, false, false, true]);
});

it('tiene un punto sospetto vicino al tratto fra i due punti buoni accanto', function () {
    $locations = [
        cleanupPoint(43.0, 13.000, 5, 0),
        cleanupPoint(43.0 + metersNorth(10), 13.001, 100, 1000),
        cleanupPoint(43.0, 13.002, 5, 2000),
    ];

    expect(flagsOf($this->service, $locations))->toBe([true, true, true]);
    expect($this->service->keptLocations($locations))->toBe($locations);
    expect($this->service->gaps($locations))->toBe([]);
});

it('scarta un punto sospetto lontano dal tratto fra i due punti buoni accanto', function () {
    $locations = [
        cleanupPoint(43.0, 13.000, 5, 0),
        cleanupPoint(43.0 + metersNorth(2000), 13.001, 2000, 1000),
        cleanupPoint(43.0, 13.002, 5, 2000),
    ];

    expect(flagsOf($this->service, $locations))->toBe([true, false, true]);
});

it('in una sequenza di sospetti tiene quelli vicini e scarta quelli lontani', function () {
    $locations = [
        cleanupPoint(43.0, 13.000, 5, 0),
        cleanupPoint(43.0 + metersNorth(10), 13.001, 100, 1000),
        cleanupPoint(43.0 + metersNorth(3000), 13.002, 4000, 2000),
        cleanupPoint(43.0 - metersNorth(20), 13.003, 80, 3000),
        cleanupPoint(43.0 + metersNorth(1500), 13.004, 2000, 4000),
        cleanupPoint(43.0, 13.005, 5, 5000),
    ];

    expect(flagsOf($this->service, $locations))->toBe([true, true, false, true, false, true]);
});

it('misura un sospetto in testa o in coda dall\'unica ancora esistente', function () {
    $near = [
        cleanupPoint(43.0 + metersNorth(30), 13.0, 100, 0),
        cleanupPoint(43.0, 13.0, 5, 1000),
        cleanupPoint(43.0, 13.001, 5, 2000),
        cleanupPoint(43.0 - metersNorth(30), 13.001, 100, 3000),
    ];
    $far = [
        cleanupPoint(43.0 + metersNorth(2000), 13.0, 2000, 0),
        cleanupPoint(43.0, 13.0, 5, 1000),
        cleanupPoint(43.0, 13.001, 5, 2000),
        cleanupPoint(43.0 - metersNorth(2000), 13.001, 2000, 3000),
    ];

    expect(flagsOf($this->service, $near))->toBe([true, true, true, true]);
    expect(flagsOf($this->service, $far))->toBe([false, true, true, false]);
});

it('scarta tutti i sospetti quando non c\'è nessun punto buono', function () {
    $locations = [
        cleanupPoint(43.0, 13.000, 100, 0),
        cleanupPoint(43.0, 13.0001, 100, 1000),
        cleanupPoint(43.0, 13.0002, 100, 2000),
    ];

    expect(flagsOf($this->service, $locations))->toBe([false, false, false]);
    expect($this->service->keptLocations($locations))->toBe([]);
});

it('legge le soglie dalla config', function () {
    config()->set('wm-package.ugc_track_max_accuracy_meters', 100.0);
    config()->set('wm-package.ugc_track_max_deviation_meters', 3000.0);
    $service = UgcTrackCleanupService::make();

    expect($service->maxAccuracyMeters())->toBe(100.0);
    expect($service->maxDeviationMeters())->toBe(3000.0);
    expect(flagsOf($service, [
        cleanupPoint(43.0, 13.000, 5, 0),
        cleanupPoint(43.0 + metersNorth(2000), 13.001, 2000, 1000),
        cleanupPoint(43.0, 13.002, 5, 2000),
    ]))->toBe([true, true, true]);
});

it('con soglie configurate a 0 o vuote usa i default 40 e 50', function () {
    config()->set('wm-package.ugc_track_max_accuracy_meters', 0.0);
    config()->set('wm-package.ugc_track_max_deviation_meters', 0.0);
    $service = UgcTrackCleanupService::make();

    expect($service->maxAccuracyMeters())->toBe(40.0);
    expect($service->maxDeviationMeters())->toBe(50.0);

    config()->set('wm-package.ugc_track_max_accuracy_meters', '');
    config()->set('wm-package.ugc_track_max_deviation_meters', null);

    expect($service->maxAccuracyMeters())->toBe(40.0);
    expect($service->maxDeviationMeters())->toBe(50.0);
});

it('restituisce un tratto ricostruito per ogni sequenza di punti scartati fra due punti tenuti', function () {
    $locations = [
        cleanupPoint(43.000, 13.000, 5, 0),
        cleanupPoint(43.001, 13.000, 5, 10_000),
        cleanupPoint(43.300, 13.300, 2000, 20_000),
        cleanupPoint(43.400, 13.400, 7857, 30_000),
        cleanupPoint(43.002, 13.000, 6, 70_000),
        cleanupPoint(43.003, 13.000, 5, 80_000),
    ];

    $gaps = $this->service->gaps($locations);

    expect($gaps)->toHaveCount(1);
    expect($gaps[0]['from'])->toBe($locations[1]);
    expect($gaps[0]['to'])->toBe($locations[4]);
    expect($gaps[0]['discarded'])->toBe(2);
    expect($gaps[0]['seconds'])->toBe(60);
    expect($gaps[0]['max_accuracy'])->toBe(7857.0);
});

it('non restituisce tratti per i punti scartati in testa o in coda', function () {
    $locations = [
        cleanupPoint(0.0, 0.0, 5, 0),
        cleanupPoint(43.000, 13.000, 5, 10_000),
        cleanupPoint(43.001, 13.000, 5, 20_000),
        cleanupPoint(43.500, 13.500, 3000, 30_000),
    ];

    expect($this->service->gaps($locations))->toBe([]);
    expect($this->service->keptLocations($locations))->toBe([$locations[1], $locations[2]]);
});

it('riassume punti scartati, accuracy massima e lunghezza prima e dopo', function () {
    $locations = [
        cleanupPoint(44.0, 10.0, 5, 0),
        cleanupPoint(45.0, 10.0, 3000, 1000),
        cleanupPoint(44.0, 10.0, 5, 2000),
    ];

    $summary = $this->service->summary($locations);
    $oneDegreeKm = 6371 * deg2rad(1);

    expect($summary['total'])->toBe(3);
    expect($summary['discarded'])->toBe(1);
    expect($summary['max_discarded_accuracy'])->toBe(3000.0);
    expect($summary['length_before_km'])->toEqualWithDelta(2 * $oneDegreeKm, 0.01);
    expect($summary['length_after_km'])->toEqualWithDelta(0.0, 0.0001);
});

it('costruisce un EWKT MultiLineString Z con la quota, e null con meno di 2 punti', function () {
    $kept = [
        cleanupPoint(43.1, 13.2, 5, 0, 1170.1),
        cleanupPoint(43.3, 13.4, 5, 1000, 980.0),
    ];

    expect($this->service->ewkt($kept))
        ->toBe('SRID=4326;MULTILINESTRING Z ((13.20000000 43.10000000 1170.100, 13.40000000 43.30000000 980.000))');
    expect($this->service->ewkt([$kept[0]]))->toBeNull();
    expect($this->service->ewkt([]))->toBeNull();
});

it('nell\'EWKT un punto senza quota prende quella del punto precedente', function () {
    $kept = [
        cleanupPoint(43.0, 13.0, 5, 0, null),
        cleanupPoint(43.1, 13.2, 5, 1000, 1170.1),
        cleanupPoint(43.3, 13.4, 5, 2000, null),
    ];

    expect($this->service->ewkt($kept))
        ->toBe('SRID=4326;MULTILINESTRING Z ((13.00000000 43.00000000 0.000, 13.20000000 43.10000000 1170.100, 13.40000000 43.30000000 1170.100))');
});
