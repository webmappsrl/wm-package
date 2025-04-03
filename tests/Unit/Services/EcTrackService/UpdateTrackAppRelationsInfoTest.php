<?php

namespace Tests\Unit\Services\EcTrackService;

use Illuminate\Support\Collection;

class UpdateTrackAppRelationsInfoTest extends AbstractEcTrackServiceTest
{
    protected $track;

    protected $layers;

    const EXPECTED_UPDATES = [
        'layers' => [
            'app1' => 123,
            'app2' => 456,
        ],
        'activities' => [
            'app1' => 'activities_data',
            'app2' => 'activities_data',
        ],
        'themes' => [
            'app1' => 'themes_data',
            'app2' => 'themes_data',
        ],
        'searchable' => [
            'app1' => 'searchable_app1',
            'app2' => 'searchable_app2',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->track = $this->createTrackWithFields();
        $this->layers = [
            (object) ['app_id' => 'app1', 'id' => 123],
            (object) ['app_id' => 'app2', 'id' => 456],
        ];
    }

    /** @test */
    // public function update_track_app_relations_info_does_not_call_update_when_no_layers()
    // {
    //     $this->track->associatedLayers = new Collection;
    //     $this->track->shouldNotReceive('update');
    //     $this->ecTrackService->updateTrackAppRelationsInfo($this->track);
    // }

}
