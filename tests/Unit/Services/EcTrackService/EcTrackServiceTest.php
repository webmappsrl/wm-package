<?php

namespace Tests\Unit\Services\EcTrackService;

use Mockery;
use Wm\WmPackage\Models\EcTrack;

class UpdateDemDataTest extends AbstractEcTrackServiceTest
{
    const EXPECTED_DEM_DATA = [
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
            ->andReturn(json_encode(self::EXPECTED_DEM_DATA['properties']));

        // Execute the method
        $this->ecTrackService->updateDemData($track);

        // Verify the track was updated with DEM data
        $this->assertEquals(self::EXPECTED_DEM_DATA['properties'], json_decode($track->dem_data, true));
    }
}
