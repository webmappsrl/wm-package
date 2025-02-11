<?php

namespace Tests\Unit\Services\EcTrackService;

use Wm\WmPackage\Http\Clients\DemClient;
use Wm\WmPackage\Services\Models\EcTrackService;
use Wm\WmPackage\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Http\Clients\OsmClient;

class AbstractEcTrackServiceTest extends TestCase
{
    use DatabaseTransactions;
    protected EcTrackService $ecTrackService;
    protected function setUp(): void
    {
        parent::setUp();
        $this->bindDefaultDependencies();
    }

    private function bindDefaultDependencies(): void
    {
        $this->app->bind(DemClient::class, MockDemClient::class);
        $this->app->bind(OsmClient::class, MockOsmClient::class);
        $this->app->bind(GeometryComputationService::class, MockGeometryComputationService::class);
        $this->ecTrackService = EcTrackService::make();
    }
    public function rebindOsmClient(string $osmClientClass): void
    {
        $this->app->bind(OsmClient::class, $osmClientClass);
        $this->ecTrackService = EcTrackService::make();
    }

    public function assertFields($track, array $fields, string $messageSuffix): void
    {
        foreach ($fields as $field => $expected) {
            $this->assertEquals(
                $expected,
                $track->$field,
                "Field '{$field}' {$messageSuffix}."
            );
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

//
// Mocks
//

class MockDemClient extends DemClient
{
    public function getTechData($geojson): array
    {
        return [
            'properties' => [
                'ele_min' => 100,
                'ele_max' => 500, 
                'ele_from' => 200,
                'ele_to' => 400,
                'ascent' => 300,
                'descent' => 200,
                'distance' => 5000,
                'duration_forward_hiking' => 120,
                'duration_backward_hiking' => 90
            ]
        ];
    }
}

class MockGeometryComputationService extends GeometryComputationService
{
    public function get3dLineMergeWktFromGeojson($geojson): string
    {
        return 'LINESTRING(10.0 45.0, 10.5 45.5)';
    }
}

class MockOsmClient extends OsmClient
{
    public function getGeojson($osmId): string
    {
        return json_encode([
            'properties' => [
                'name'              => 'New Track Name',
                'ref'               => 'T123',
                'duration:forward'  => '02:30',
                'duration:backward' => '03:00',
                'ascent'            => 500,
                'descent'           => 400,
                'distance'          => 7000,
            ],
            'geometry' => [
                'type'        => 'LineString',
                'coordinates' => [[10.0, 45.0], [10.5, 45.5]],
            ],
        ]);
    }
}

// Mock per il caso in cui manchino le proprietà (solleva un errore)
class MockOsmClientNoProperties extends OsmClient
{
    public function getGeojson($osmId): string
    {
        return json_encode([
            'geometry' => [
                'type'        => 'LineString',
                'coordinates' => [[10.0, 45.0], [10.5, 45.5]],
            ],
        ]);
    }
}

// Mock per il caso in cui manchi la geometria (solleva un errore)
class MockOsmClientNoGeometry extends OsmClient
{
    public function getGeojson($osmId): string
    {
        return json_encode([
            'properties' => [
                'name'              => 'New Track Name',
                'ref'               => 'T123',
                'duration:forward'  => '02:30',
                'duration:backward' => '03:00',
                'ascent'            => 500,
                'descent'           => 400,
                'distance'          => 7000,
            ],
            // "geometry" mancante
        ]);
    }
}