<?php

namespace Tests\Unit\Services\EcTrackService;

use Mockery;
use Tests\Unit\Services\EcTrackService\AbstractEcTrackServiceTest;
use Wm\WmPackage\Models\EcTrack;

class GetRelatedPoisOrderTest extends AbstractEcTrackServiceTest
{
    /** @test */
    public function get_related_pois_order_returns_null_when_no_related_pois_exist()
    {
        $geojson = [
            'ecTrack' => [
                'properties' => [
                    // 'related_pois' non è presente.
                ],
                'geometry' => ['some' => 'track_geometry']
            ]
        ];
        $track = $this->createMockTrack(1, $geojson);

        $result = $this->ecTrackService->getRelatedPoisOrder($track);
        $this->assertNull($result);
    }

    /** @test */
    public function get_related_pois_order_returns_ordered_poi_ids_when_related_pois_exist()
    {
        $geojson = [
            'ecTrack' => [
                'properties' => [
                    'related_pois' => [
                        [
                            'properties' => ['id' => 'poi1'],
                            'geometry'   => ['order' => 0.8]
                        ],
                        [
                            'properties' => ['id' => 'poi2'],
                            'geometry'   => ['order' => 0.2]
                        ],
                        [
                            'properties' => ['id' => 'poi3'],
                            'geometry'   => ['order' => 0.5]
                        ],
                    ]
                ],
                'geometry' => ['some' => 'track_geometry']
            ]
        ];
        $track = $this->createMockTrack(1, $geojson);
        
        $result = $this->ecTrackService->getRelatedPoisOrder($track);
        $this->assertEquals(['poi2', 'poi3', 'poi1'], $result);
    }
}
