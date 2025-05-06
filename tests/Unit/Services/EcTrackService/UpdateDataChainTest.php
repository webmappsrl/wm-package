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

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->track = Mockery::mock(EcTrack::class)->makePartial();
        $this->track->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $this->track->id = 1;
        $this->track->properties = [];
        $this->track->shouldReceive('getAttribute')->with('properties')->andReturnUsing(function () {
            return $this->track->properties;
        });
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
        $this->track->properties = ['osmid' => 123];
        $this->track->shouldReceive('offsetExists')->with('osmid')->andReturn(true);
        $this->track->shouldReceive('offsetGet')->with('osmid')->andReturn(123);
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
