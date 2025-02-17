<?php
namespace Tests\Unit\Services\EcTrackService;

use Illuminate\Support\Facades\Bus;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackDemJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackFromOsmJob;
use Wm\WmPackage\Jobs\UpdateLayerTracksJob;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Mockery;

class UpdateDataChainTest extends AbstractEcTrackServiceTest
{
    private EcTrack $track;

    public function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->track = Mockery::mock(EcTrack::class)->makePartial();

    }

    public function test_update_data_chain_dispatches_at_least_one_job()
    {
        // Eseguiamo la funzione
        $this->ecTrackService->updateDataChain($this->track);

        // Controlliamo che almeno un job sia stato dispatchato
        Bus::assertDispatched(UpdateEcTrackDemJob::class);
    }

    public function test_update_data_chain_dispatches_job_if_track_has_osm_data()
    {
        $this->track->shouldReceive('getAttribute')->with('osmid')->andReturn(123);
        $this->ecTrackService->updateDataChain($this->track);
        Bus::assertDispatched(UpdateEcTrackFromOsmJob::class);
    }

    public function test_update_data_chain_dispatches_job_if_track_has_layers()
    {
        $this->track->shouldReceive('getAttribute')->with('associatedLayers')->andReturn(collect([Mockery::mock(Layer::class)]));
        $this->ecTrackService->updateDataChain($this->track);
        Bus::assertDispatched(UpdateLayerTracksJob::class);
    }
}
