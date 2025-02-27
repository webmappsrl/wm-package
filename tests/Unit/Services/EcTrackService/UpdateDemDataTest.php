<?php

namespace Tests\Unit\Services\EcTrackService;

use Mockery;

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
            'duration_backward_hiking' => 90,
        ],
    ];

    /** @test */
    public function update_dem_data_updates_track_with_dem_data()
    {
        $track = $this->prepareTrackWithGeojson(1, ['type' => 'Feature']);
        $track->shouldReceive('saveQuietly')->once();

        $track->shouldReceive('setAttribute')
            ->with('dem_data', Mockery::type('array'))
            ->once();

        $track->shouldReceive('getAttribute')
            ->with('dem_data')
            ->andReturn(json_encode(self::EXPECTED_DEM_DATA['properties']));

        $this->ecTrackService->updateDemData($track);

        $this->assertEquals(self::EXPECTED_DEM_DATA['properties'], json_decode($track->dem_data, true));
    }
}
