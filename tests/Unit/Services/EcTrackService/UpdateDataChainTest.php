<?php

namespace Tests\Unit\Services\EcTrackService;

use Mockery;
use Wm\WmPackage\Models\EcTrack;
use Illuminate\Support\Facades\Bus;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackDemJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackFromOsmJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackManualDataJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackOrderRelatedPoi;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackCurrentDataJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrack3DDemJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackSlopeValues;
use Wm\WmPackage\Jobs\UpdateModelWithGeometryTaxonomyWhere;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackGenerateElevationChartImage;
use Wm\WmPackage\Jobs\Pbf\GenerateEcTrackPBFBatch;

class UpdateDataChainTest extends AbstractEcTrackServiceTest
{
    /** @var EcTrack|Mockery\MockInterface */
    private $track;

    private array $mockedTrackProperties; // To hold the state of properties for the mock

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();

        $this->mockedTrackProperties = [
            'dem_data' => ['needs_processing' => true],
            'manual_data' => ['needs_processing' => true],
        ];

        $this->track = Mockery::mock(EcTrack::class);

        // Simulate $track->properties
        $this->track->shouldReceive('__get')->with('properties')->andReturnUsing(function () {
            return $this->mockedTrackProperties;
        });

        // For isset($track['properties']) or array-like access
        $this->track->shouldReceive('offsetExists')->andReturnUsing(function ($key) {
            return isset($this->mockedTrackProperties[$key]);
        });

        $this->track->shouldReceive('offsetGet')->andReturnUsing(function ($key) {
            return $this->mockedTrackProperties[$key] ?? null;
        });

        // For serialization in Jobs
        $this->track->shouldReceive('getKey')->andReturn(1);
        $this->track->shouldReceive('getQueueableClass')->andReturn(EcTrack::class);
        $this->track->shouldReceive('getQueueableRelations')->andReturn([]);
        $this->track->shouldReceive('getQueueableConnection')->andReturn('test_connection_name');
        $this->track->shouldReceive('getAttribute')->with('id')->andReturn(1);
    }


    public function test_update_data_chain_dispatches_at_least_one_job()
    {
        // Mock wasChanged('geometry') to return true to enter the conditional block
        $this->track->shouldReceive('wasChanged')->with('geometry')->once()->andReturn(true);

        // Ensure 'osmid' is not set in properties for this specific test case,
        // so UpdateEcTrackFromOsmJob is not part of this chain.
        // The default $this->mockedTrackProperties in setUp() does not include 'osmid'.
        // If it could be set by other means, explicitly unset it or re-initialize:
        // $this->mockedTrackProperties = [
        //     'dem_data' => ['needs_processing' => true], // Though 'needs_processing' is no longer used by updateDataChain
        //     'manual_data' => ['needs_processing' => true] // Same as above
        // ];
        // To be absolutely sure 'osmid' is not there for THIS test:
        unset($this->mockedTrackProperties['osmid']);

        $this->ecTrackService->updateDataChain($this->track);

        Bus::assertChained([
            UpdateEcTrackDemJob::class,
            UpdateEcTrackManualDataJob::class,
            UpdateEcTrackCurrentDataJob::class,
            UpdateEcTrack3DDemJob::class,
            UpdateEcTrackSlopeValues::class,
            UpdateModelWithGeometryTaxonomyWhere::class,
            UpdateEcTrackGenerateElevationChartImage::class,
            UpdateEcTrackAwsJob::class,
            UpdateEcTrackOrderRelatedPoi::class,
            GenerateEcTrackPBFBatch::class,
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
