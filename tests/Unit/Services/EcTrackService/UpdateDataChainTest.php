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

    private array $mockedTrackProperties;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();

        $this->mockedTrackProperties = [
            'dem_data' => ['needs_processing' => true],
            'manual_data' => ['needs_processing' => true],
        ];

        $this->track = Mockery::mock(EcTrack::class)->makePartial();

        // Simulated properties
        $this->track->shouldReceive('getAttribute')->with('properties')->andReturnUsing(function () {
            return $this->mockedTrackProperties;
        });

        $this->track->shouldReceive('offsetExists')->andReturnUsing(function ($key) {
            return isset($this->mockedTrackProperties[$key]);
        });

        $this->track->shouldReceive('offsetGet')->andReturnUsing(function ($key) {
            return $this->mockedTrackProperties[$key] ?? null;
        });

        // Serialization support
        $this->track->shouldReceive('getKey')->andReturn(1);
        $this->track->shouldReceive('getQueueableClass')->andReturn(EcTrack::class);
        $this->track->shouldReceive('getQueueableRelations')->andReturn([]);
        $this->track->shouldReceive('getQueueableConnection')->andReturn('test_connection_name');
        $this->track->shouldReceive('getAttribute')->with('id')->andReturn(1);

        // Generic mock for wasChanged
        $this->track->shouldAllowMockingMethod('wasChanged');
    }

    public function test_update_data_chain_dispatches_at_least_one_job()
    {
        // Mock wasChanged('geometry') only here
        $this->track->shouldReceive('wasChanged')->with('geometry')->once()->andReturn(true);

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
        $this->mockedTrackProperties = ['osmid' => 123];

        $this->track->shouldReceive('wasChanged')->with('geometry')->andReturn(false);

        $this->ecTrackService->updateDataChain($this->track);

        Bus::assertDispatched(UpdateEcTrackFromOsmJob::class);
    }
}
