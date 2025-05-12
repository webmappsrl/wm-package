<?php

namespace Tests\Unit\Services\EcTrackService;

use Illuminate\Support\Facades\Bus;
use Mockery;
use Wm\WmPackage\Jobs\Pbf\GenerateEcTrackPBFBatch;
use Wm\WmPackage\Jobs\Track\UpdateEcTrack3DDemJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackCurrentDataJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackDemJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackFromOsmJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackGenerateElevationChartImage;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackManualDataJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackOrderRelatedPoi;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackSlopeValues;
use Wm\WmPackage\Jobs\UpdateModelWithGeometryTaxonomyWhere;
use Wm\WmPackage\Models\EcTrack;

class UpdateDataChainTest extends AbstractEcTrackServiceTest
{
    private $track;

    private array $mockedTrackProperties;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();

        $this->mockedTrackProperties = [];

        // Use a SPY, not mock() or makePartial()
        $this->track = Mockery::spy(EcTrack::class);
        // No need to manually set queueableClass for a spy on a real class

        // --- Mock for Laravel queue serialization (still needed for spy) ---
        // Spies allow real method calls by default, but we can still override specific ones if needed.
        // For serialization, Laravel might still call these internally, so let's keep them.
        $this->track->shouldReceive('getKey')->andReturn(1);
        $this->track->shouldReceive('getQueueableId')->andReturn(1);
        $this->track->shouldReceive('getQueueableClass')->andReturn(EcTrack::class);
        $this->track->shouldReceive('getQueueableRelations')->andReturn([]);
        $this->track->shouldReceive('getQueueableConnection')->andReturn('test_connection_name');

        // --- Mock for property access from the service ---
        // For spies, we need to explicitly tell them what to return for specific calls
        // that the service makes, otherwise the real method would be called.
        $this->track->shouldReceive('getAttribute')->with('properties')->andReturnUsing(function () {
            return $this->mockedTrackProperties;
        });
        // We also need to mock the magic __get for direct property access, if used.
        $this->track->shouldReceive('__get')->with('properties')->andReturnUsing(function () {
            return $this->mockedTrackProperties;
        });
        // Mock getAttribute('id')
        $this->track->shouldReceive('getAttribute')->with('id')->andReturn(1);

        // wasChanged will be mocked for each specific test
        // No need for shouldAllowMockingMethod with pure mocks if expectations are clear for tests.
    }

    public function test_update_data_chain_dispatches_at_least_one_job()
    {
        // Mock wasChanged specifically for this test
        $this->track->shouldReceive('wasChanged')->with('geometry')->once()->andReturn(true);

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

        // Mock getAttribute('osmid') for the spy
        $this->track->shouldReceive('getAttribute')->with('osmid')->andReturn(123);
        // If the code accesses $track->osmid directly, mock __get as well
        $this->track->shouldReceive('__get')->with('osmid')->andReturn(123);

        // Mock wasChanged specifically for this test
        $this->track->shouldReceive('wasChanged')->with('geometry')->andReturn(false);

        $this->ecTrackService->updateDataChain($this->track);

        Bus::assertDispatched(UpdateEcTrackFromOsmJob::class);
    }
}
