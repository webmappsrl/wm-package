<?php

namespace Tests\Unit\Services;

use Mockery;
use Wm\WmPackage\Tests\TestCase;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\Models\EcTrackService;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Http\Clients\DemClient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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


class EcTrackServiceTest extends TestCase
{
    use DatabaseTransactions;
    protected EcTrackService $ecTrackService;
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(DemClient::class, MockDemClient::class);
        $this->ecTrackService = EcTrackService::make();
    }
    
    

 /** @test */
public function update_dem_data_updates_track_with_dem_data()
{
    // Create mock track (use a partial mock for real attribute handling)
    $track = Mockery::mock(EcTrack::class)->makePartial();
    $track->shouldReceive('getGeojson')->once()->andReturn(['type' => 'Feature']);
    $track->shouldReceive('saveQuietly')->times(2);

    // Allow attribute setting
    $track->shouldReceive('setAttribute')
        ->with('dem_data', Mockery::type('array'))
        ->once();

    $track->shouldReceive('getAttribute')
        ->with('dem_data')
        ->andReturn(json_encode([
            'ele_min' => 100,
            'ele_max' => 500,
            'ele_from' => 200,
            'ele_to' => 400,
            'ascent' => 300,
            'descent' => 200,
            'distance' => 5000,
            'duration_forward_hiking' => 120,
            'duration_backward_hiking' => 90
        ]));

    // Mock DEM response data
    $demResponseData = [
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

    // Execute the method
    $this->ecTrackService->updateDemData($track);

    // Verify the track was updated with DEM data
    $this->assertEquals($demResponseData['properties'], json_decode($track->dem_data, true));
}


    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
