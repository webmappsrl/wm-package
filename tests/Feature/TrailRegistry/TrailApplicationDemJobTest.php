<?php

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Services\Models\EcTrackService;
use Wm\WmPackage\TrailRegistry\Jobs\UpdateTrailApplicationDemJob;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;

beforeEach(function () {
    runTrailRegistryStubs();

    $this->application = TrailApplication::factory()->create();
    DB::statement(
        'UPDATE trail_applications SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING Z ((1 1 0, 2 2 0))', $this->application->id]
    );
});

function fakeDemResponse(array $geometry): void
{
    Http::fake([
        '*' => Http::response([
            'type' => 'Feature',
            'properties' => [
                'distance' => 157.2,
                'ascent' => 300,
                'descent' => 290,
                'ele_max' => 350,
                'ele_min' => 50,
                'ele_from' => 60,
                'ele_to' => 70,
                'round_trip' => false,
                'duration_forward_hiking' => 120,
                'duration_backward_hiking' => 110,
                'duration_forward_bike' => 40,
                'duration_backward_bike' => 35,
            ],
            'geometry' => $geometry,
        ]),
    ]);
}

it('scrive dem_data e la quota del DEM senza toccare il resto di properties', function () {
    DB::statement(
        'UPDATE trail_applications SET properties = ?::jsonb WHERE id = ?',
        [json_encode(['protocollo' => 'P-1', 'manual_data' => ['duration_forward' => 180]]), $this->application->id]
    );
    fakeDemResponse(['type' => 'MultiLineString', 'coordinates' => [[[1, 1, 100], [2, 2, 200]]]]);

    (new UpdateTrailApplicationDemJob($this->application->id))->handle(app(EcTrackService::class));

    $fresh = $this->application->fresh();
    expect($fresh->properties['dem_data']['ascent'])->toBe(300)
        ->and($fresh->properties['dem_data']['duration_forward'])->toBe(120)
        ->and($fresh->properties['protocollo'])->toBe('P-1')
        ->and($fresh->properties['manual_data']['duration_forward'])->toBe(180);

    $wkt = DB::selectOne('SELECT ST_AsText(geometry) AS wkt FROM trail_applications WHERE id = ?', [$this->application->id])->wkt;
    expect($wkt)->toBe('MULTILINESTRING Z ((1 1 100,2 2 200))');
});

it('normalizza una LineString restituita dal DEM in MultiLineString', function () {
    fakeDemResponse(['type' => 'LineString', 'coordinates' => [[1, 1, 100], [2, 2, 200]]]);

    (new UpdateTrailApplicationDemJob($this->application->id))->handle(app(EcTrackService::class));

    $type = DB::selectOne('SELECT GeometryType(geometry::geometry) AS t FROM trail_applications WHERE id = ?', [$this->application->id])->t;
    expect($type)->toBe('MULTILINESTRING');
});

it('non fa nulla se l istanza non esiste piu', function () {
    Http::fake();

    (new UpdateTrailApplicationDemJob(999999))->handle(app(EcTrackService::class));

    Http::assertNothingSent();
});

it('e unico per istanza e gira sulla coda dem', function () {
    $job = new UpdateTrailApplicationDemJob($this->application->id);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe((string) $this->application->id)
        ->and($job->queue)->toBe('dem')
        ->and($job->uniqueVia())->toBeInstanceOf(Repository::class);
});
