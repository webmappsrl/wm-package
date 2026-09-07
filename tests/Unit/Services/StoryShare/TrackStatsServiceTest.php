<?php

declare(strict_types=1);

use Wm\WmPackage\Services\Models\StoryShare\TrackStatsService;

// Base TestCase (Wm\WmPackage\Tests\TestCase) applied globally by tests/Pest.php.

it('returns all zeros for an empty locations array', function () {
    $result = (new TrackStatsService)->compute([]);

    expect($result)->toBe([
        'duration_seconds' => 0,
        'distance_km' => 0.0,
        'ascent_meters' => 0.0,
    ]);
});

it('returns all zeros for a single-point locations array', function () {
    $result = (new TrackStatsService)->compute([
        ['time' => 0, 'latitude' => 44.0, 'longitude' => 10.0, 'altitude' => 100, 'altitudeAccuracy' => 5],
    ]);

    expect($result)->toBe([
        'duration_seconds' => 0,
        'distance_km' => 0.0,
        'ascent_meters' => 0.0,
    ]);
});

it('computes duration as (last.time - first.time) / 1000', function () {
    $result = (new TrackStatsService)->compute([
        ['time' => 10_000, 'latitude' => 44.0, 'longitude' => 10.0],
        ['time' => 13_500, 'latitude' => 44.0, 'longitude' => 10.0],
        ['time' => 20_000, 'latitude' => 44.0, 'longitude' => 10.0],
    ]);

    expect($result['duration_seconds'])->toBe(10); // (20000 - 10000) / 1000
});

it('computes haversine distance for two points one full degree of latitude apart', function () {
    // Moving along a meridian (same longitude) is an exact great-circle arc: distance =
    // R * delta_lat_in_radians, with NO cosine factor to worry about — an independent,
    // hand-computable cross-check for the haversine implementation, using the ticket's own
    // specified earth radius (6371000m).
    $expectedMeters = 6371000 * deg2rad(1);

    $result = (new TrackStatsService)->compute([
        ['time' => 0, 'latitude' => 44.0, 'longitude' => 10.0],
        ['time' => 1000, 'latitude' => 45.0, 'longitude' => 10.0],
    ]);

    expect($result['distance_km'])->toBeGreaterThan(0);
    expect($result['distance_km'] * 1000)->toEqualWithDelta($expectedMeters, 1.0);
});

it('sums distance over multiple consecutive segments', function () {
    $singleDegreeMeters = 6371000 * deg2rad(1);

    $result = (new TrackStatsService)->compute([
        ['time' => 0, 'latitude' => 44.0, 'longitude' => 10.0],
        ['time' => 1000, 'latitude' => 45.0, 'longitude' => 10.0],
        ['time' => 2000, 'latitude' => 46.0, 'longitude' => 10.0],
    ]);

    expect($result['distance_km'] * 1000)->toEqualWithDelta($singleDegreeMeters * 2, 2.0);
});

it('sums positive altitude differences above the noise threshold, discarding smaller ones and negative diffs', function () {
    $locations = [
        ['time' => 0, 'latitude' => 44.0, 'longitude' => 10.0, 'altitude' => 100.0, 'altitudeAccuracy' => 6],
        // diff from prev = 0.5; threshold = max(6,6)/6 = 1 -> 0.5 <= 1, discarded as noise
        ['time' => 1000, 'latitude' => 44.0, 'longitude' => 10.0, 'altitude' => 100.5, 'altitudeAccuracy' => 6],
        // diff from prev = 2.5; threshold = 1 -> 2.5 > 1, counted. running ascent = 2.5
        ['time' => 2000, 'latitude' => 44.0, 'longitude' => 10.0, 'altitude' => 103.0, 'altitudeAccuracy' => 6],
        // diff from prev = -13 (descent); never counted regardless of threshold
        ['time' => 3000, 'latitude' => 44.0, 'longitude' => 10.0, 'altitude' => 90.0, 'altitudeAccuracy' => 6],
        // diff from prev = 5; threshold = max(6,12)/6 = 2 -> 5 > 2, counted. running ascent = 2.5 + 5 = 7.5
        ['time' => 4000, 'latitude' => 44.0, 'longitude' => 10.0, 'altitude' => 95.0, 'altitudeAccuracy' => 12],
    ];

    $result = (new TrackStatsService)->compute($locations);

    expect($result['ascent_meters'])->toEqualWithDelta(7.5, 0.0001);
    expect($result['duration_seconds'])->toBe(4);
    expect($result['distance_km'])->toBe(0.0); // same lat/lon throughout
});

it('skips altitude contribution for a pair missing altitude data, without crashing', function () {
    $result = (new TrackStatsService)->compute([
        ['time' => 0, 'latitude' => 44.0, 'longitude' => 10.0],
        ['time' => 1000, 'latitude' => 44.001, 'longitude' => 10.0],
        ['time' => 2000, 'latitude' => 44.002, 'longitude' => 10.0, 'altitude' => 50, 'altitudeAccuracy' => 3],
    ]);

    expect($result['ascent_meters'])->toBe(0.0);
});

it('drops malformed location entries missing latitude/longitude instead of crashing', function () {
    $result = (new TrackStatsService)->compute([
        ['time' => 0, 'latitude' => 44.0, 'longitude' => 10.0],
        ['time' => 500, 'foo' => 'bar'], // malformed: no lat/lon at all
        ['time' => 1000, 'latitude' => 45.0, 'longitude' => 10.0],
    ]);

    $expectedMeters = 6371000 * deg2rad(1);

    expect($result['distance_km'] * 1000)->toEqualWithDelta($expectedMeters, 1.0);
});
