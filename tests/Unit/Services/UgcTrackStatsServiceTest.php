<?php

declare(strict_types=1);

use Wm\WmPackage\Services\Models\UgcTrackStatsService;

beforeEach(function () {
    config()->set('wm-package.ugc_track_max_accuracy_meters', 40.0);
    config()->set('wm-package.ugc_track_max_deviation_meters', 50.0);
    config()->set('wm-package.ugc_track_max_speed_percentile', 95.0);
    config()->set('wm-package.ugc_track_moving_min_speed_kmh', 1.0);
    $this->stats = UgcTrackStatsService::make();
});

/** Gradi di latitudine pari a $meters metri verso nord (R = 6371000). */
function statsNorth(float $meters): float
{
    return rad2deg($meters / 6371000.0);
}

/** Punto per i test di stats: lat cresce di $meters dal punto di partenza 43,0 / 13,0. */
function statsPoint(float $meters, int $timeMs, ?float $speed = 4.0, float $accuracy = 5.0): array
{
    $point = ['latitude' => 43.0 + statsNorth($meters), 'longitude' => 13.0, 'time' => $timeMs, 'accuracy' => $accuracy, 'altitude' => 100.0];
    if ($speed !== null) {
        $point['speed'] = $speed;
    }

    return $point;
}

it('restituisce null con meno di due punti tenuti', function () {
    expect($this->stats->localStats([statsPoint(0, 0)]))->toBeNull();
    expect($this->stats->localStats([]))->toBeNull();
});

it('calcola distanza, tempi e media su un cammino regolare', function () {
    // 11 punti, 10 m ogni 10 s: 100 m in 100 s = 3,6 km/h
    $points = array_map(fn ($i) => statsPoint($i * 10, $i * 10_000), range(0, 10));

    $s = $this->stats->localStats($points);

    expect($s['distance'])->toBe(0.1);
    expect($s['duration'])->toBe(2);          // 100 s → 1,67 min → 2
    expect($s['duration_moving'])->toBe(2);
    expect($s['avg_speed'])->toBe(3.6);
    expect($s['max_speed'])->toBe(4.0);
    expect($s)->not->toHaveKey('points_total');
    expect($s)->not->toHaveKey('points_discarded');
    foreach (UgcTrackStatsService::DEM_KEYS as $key) {
        expect($s)->toHaveKey($key);
        expect($s[$key])->toBeNull();
    }
    expect($s['computed_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});

it('esclude dal tempo effettivo una sosta registrata come un solo tratto lento', function () {
    // 100 m in 100 s, poi 10 m in 26 minuti (distance filter), poi 100 m in 100 s
    $points = array_map(fn ($i) => statsPoint($i * 10, $i * 10_000), range(0, 10));
    $points[] = statsPoint(110, 100_000 + 26 * 60_000);
    foreach (range(1, 10) as $i) {
        $points[] = statsPoint(110 + $i * 10, 100_000 + 26 * 60_000 + $i * 10_000);
    }

    $s = $this->stats->localStats($points);

    expect($s['duration'])->toBe(29);          // 200 s + 26 min
    expect($s['duration_moving'])->toBe(3);    // 200 s → 3,33 → 3
    expect($s['avg_speed'])->toBe(3.6);
});

it('conta come movimento un lungo intervallo con centinaia di metri (cammino senza segnale)', function () {
    $points = [statsPoint(0, 0), statsPoint(10, 10_000), statsPoint(231, 610_000), statsPoint(241, 620_000)];

    $s = $this->stats->localStats($points);

    expect($s['duration_moving'])->toBe(10);   // 620 s, tutti in movimento
});

it('calcola la media sui soli tratti con GPS buono', function () {
    // tratti buoni: 10 m ogni 10 s; un tratto con accuracy 45 m (sospetto ma tenuto) salta 30 m in 1 s
    $points = array_map(fn ($i) => statsPoint($i * 10, $i * 10_000), range(0, 5));
    $points[] = statsPoint(80, 51_000, 0.0, 45.0);
    foreach (range(1, 5) as $i) {
        $points[] = statsPoint(80 + $i * 10, 51_000 + $i * 10_000);
    }

    $s = $this->stats->localStats($points);

    expect($s['avg_speed'])->toBe(3.6);
});

it('usa il percentile del campo speed come velocità massima, ignorando un picco isolato', function () {
    $points = array_map(fn ($i) => statsPoint($i * 10, $i * 10_000, 4.0), range(0, 39));
    $points[20]['speed'] = 890.0;

    expect($this->stats->localStats($points)['max_speed'])->toBe(4.0);
});

it('senza campo speed usa il percentile della velocità fra punti consecutivi', function () {
    $points = array_map(fn ($i) => statsPoint($i * 10, $i * 10_000, null), range(0, 10));

    expect($this->stats->localStats($points)['max_speed'])->toBe(3.6);
});

it('ignora i tratti con time mancante o non crescente senza errori', function () {
    $points = [statsPoint(0, 0), statsPoint(10, 10_000), statsPoint(20, 5_000), statsPoint(30, 30_000)];
    unset($points[3]['time']);

    $s = $this->stats->localStats($points);

    expect($s['distance'])->toBe(0.03);
    expect($s['duration'])->toBe(0);           // primo 0, ultimo time numerico 5 s → round(0,08) = 0
    expect($s['avg_speed'])->toBe(3.6);        // solo il primo tratto è valido
});

it('calcola il percentile con il metodo nearest-rank', function () {
    expect($this->stats->percentile([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 95))->toBe(10.0);
    expect($this->stats->percentile([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 50))->toBe(5.0);
    expect($this->stats->percentile([7], 95))->toBe(7.0);
    expect($this->stats->percentile([], 95))->toBeNull();
});

it('converte stats nel formato dell\'immagine di condivisione', function () {
    expect($this->stats->forShareImage(['duration' => 165, 'distance' => 9.37, 'ascent' => 313]))
        ->toBe(['duration_seconds' => 9900, 'distance_km' => 9.37, 'ascent_meters' => 313.0]);
    expect($this->stats->forShareImage(['duration' => null, 'distance' => 1.0, 'ascent' => null]))
        ->toBe(['duration_seconds' => null, 'distance_km' => 1.0, 'ascent_meters' => null]);
});
