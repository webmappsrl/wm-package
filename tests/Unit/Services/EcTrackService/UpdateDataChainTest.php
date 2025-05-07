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
            // Populate this array with properties you expect to be read,
            // e.g. osmid will be set for specific tests.
            // For the 'at_least_one_job' test, osmid should not be present here
            // if you don't want UpdateEcTrackFromOsmJob to be added in that case.
            // Your previous 'dem_data' and 'manual_data' are no longer used by updateDataChain.
        ];

        // Use a PURE mock, not makePartial()
        $this->track = Mockery::mock(EcTrack::class)->makePartial();

        // --- Mock for Laravel queue serialization ---
        $this->track->shouldReceive('getKey')->andReturn(1); // Model ID
        $this->track->shouldReceive('getQueueableClass')->andReturn(EcTrack::class); // REAL class for serialization
        $this->track->shouldReceive('getQueueableId')->andReturn(1); // Model ID for queueing
        $this->track->shouldReceive('getQueueableRelations')->andReturn([]); // Relations to serialize (none here)
        $this->track->shouldReceive('getQueueableConnection')->andReturn('test_connection_name'); // Test connection name or null

        // --- Mock for property access from the service ---
        $this->track->shouldReceive('getAttribute')->with('properties')->andReturnUsing(function () {
            return $this->mockedTrackProperties;
        });
        // Mock for direct access to ->properties
        $this->track->shouldReceive('__get')->with('properties')->andReturnUsing(function () {
            return $this->mockedTrackProperties;
        });

        // Mock for getAttribute('id') (common)
        $this->track->shouldReceive('getAttribute')->with('id')->andReturn(1);

        // Mock to handle isset($track->some_property) or empty($track->some_property)
        // which internally call __isset -> offsetExists
        $this->track->shouldReceive('offsetExists')->byDefault()->andReturnUsing(function ($key) {
            // Here you might want to be more specific if you know which keys are being checked.
            // For a generic mock, we return true for common keys if they exist in the mocked properties,
            // or for fundamental keys like 'id'. Otherwise false.
            if ($key === 'id') return true;
            // If the code happens to do isset($track->properties)
            if ($key === 'properties') return true;
            // For other keys, check if they exist in mockedTrackProperties (to simulate isset on real properties)
            // This is useful if a job does isset($track->osmid) directly.
            return array_key_exists($key, $this->mockedTrackProperties);
        });

        // Mock to handle $track->some_property (direct access to properties not explicitly defined)
        // which internally calls __get -> offsetGet (if the property is not a direct attribute)
        // or __get -> getAttribute if it is an attribute.
        // Since getAttribute is already mocked for 'id' and 'properties', this is a fallback.
        $this->track->shouldReceive('offsetGet')->byDefault()->andReturnUsing(function ($key) {
            // Similar to offsetExists, returns the value from mockedTrackProperties if the key exists,
            // otherwise null.
            if ($key === 'id') return 1; // Already covered by getAttribute('id') but for safety
            return $this->mockedTrackProperties[$key] ?? null;
        });

        // wasChanged will be mocked for each specific test
        // No need for shouldAllowMockingMethod with pure mocks if expectations are clear for tests.
    }

    public function test_update_data_chain_dispatches_at_least_one_job()
    {
        // Mock wasChanged('geometry') only here
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

        $this->track->shouldReceive('wasChanged')->with('geometry')->andReturn(false);

        $this->ecTrackService->updateDataChain($this->track);

        Bus::assertDispatched(UpdateEcTrackFromOsmJob::class);
    }
}
