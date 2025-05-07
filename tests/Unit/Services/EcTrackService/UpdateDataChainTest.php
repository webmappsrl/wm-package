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
        parent::setUp(); // Call parent setup first
        Bus::fake();     // Then fake the bus

        $this->mockedTrackProperties = [
            'dem_data' => [],
            'manual_data' => []
        ];

        $this->track = Mockery::mock(EcTrack::class)->makePartial();
        $this->track->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $this->track->id = 1;

        $this->track->shouldReceive('getAttribute')->with('properties')->andReturnUsing(function () {
            return $this->mockedTrackProperties;
        });

        $this->track->shouldReceive('setAttribute')->with('properties', Mockery::any())->andReturnUsing(function ($key, $value) {
            $this->mockedTrackProperties = $value;
        });

        // It's important that EcTrackService is resolved *after* Bus::fake() if it injects the dispatcher.
        // The AbstractEcTrackServiceTest::setUp() already calls $this->ecTrackService = EcTrackService::make();
        // after its own parent::setUp(), so EcTrackService should pick up the faked bus.
        // If EcTrackService was resolved before Bus::fake(), it would have the real dispatcher.
    }

    public function test_update_data_chain_dispatches_at_least_one_job()
    {
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
