<?php

namespace Tests\Unit\Services\EcTrackService;

use Illuminate\Support\Facades\Bus;
use Mockery;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackDemJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackFromOsmJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackManualDataJob;
use Wm\WmPackage\Models\EcTrack;

class UpdateDataChainTest extends AbstractEcTrackServiceTest
{
    /** @var EcTrack|Mockery\MockInterface */
    private $track;

    private array $mockedTrackProperties; // To hold the state of properties for the mock

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->track = Mockery::mock(EcTrack::class)->makePartial();

        // Mock ID
        $this->track->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $this->track->id = 1; // For direct access if needed by partial mock aspects

        // Initialize and manage properties state externally for the mock
        $this->mockedTrackProperties = [];
        $this->track->shouldReceive('getAttribute')->with('properties')->andReturnUsing(function () {
            return $this->mockedTrackProperties;
        });

        $this->track->shouldReceive('setAttribute')->with('properties', Mockery::any())->andReturnUsing(function ($key, $value) {
            $this->mockedTrackProperties = $value;
        });

        // If EcTrack uses ArrayAccess for properties (e.g., $track['osmid'])
        // you might need to mock offsetExists, offsetGet, etc., for 'properties' itself if it's accessed like an array item,
        // or ensure the 'properties' attribute itself is an array/ArrayAccess.
        // The EcTrackService accesses $track->properties['osmid'], so getAttribute('properties') returning an array is key.
    }

    public function test_update_data_chain_dispatches_at_least_one_job()
    {
        // Ensure properties is at least an empty array for the service logic
        // AND contains 'manual_data' for the UpdateEcTrackManualDataJob to be chained
        $this->mockedTrackProperties = ['manual_data' => []];

        $this->ecTrackService->updateDataChain($this->track);
        Bus::assertChained([
            UpdateEcTrackDemJob::class,
            UpdateEcTrackManualDataJob::class,
        ]);
    }

    public function test_update_data_chain_dispatches_job_if_track_has_osm_data()
    {
        // Set osmid within the externally managed properties array
        $this->mockedTrackProperties = ['osmid' => 123];

        // No need to mock offsetExists or offsetGet on $this->track directly for 'osmid'
        // because EcTrackService will do: $track->properties['osmid']
        // and $track->properties is already mocked via getAttribute('properties') to return $this->mockedTrackProperties.

        $this->ecTrackService->updateDataChain($this->track);
        Bus::assertDispatched(UpdateEcTrackFromOsmJob::class);
    }

    // public function test_update_data_chain_dispatches_job_if_track_has_layers()
    // {
    //     $this->track->shouldReceive('getAttribute')->with('associatedLayers')->andReturn(collect([Mockery::mock(Layer::class)]));
    //     $this->ecTrackService->updateDataChain($this->track);
    //     Bus::assertDispatched(UpdateLayerTracksJob::class);
    // }
}
