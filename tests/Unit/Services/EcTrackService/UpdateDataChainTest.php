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
        parent::setUp(); // Call parent setup first
        Bus::fake();     // Then fake the bus

        $this->mockedTrackProperties = [
            'dem_data' => ['needs_processing' => true],
            'manual_data' => ['needs_processing' => true],
        ];

        // Use a PURE mock, not makePartial()
        $this->track = Mockery::mock(EcTrack::class);

        // --- Mocks for EcTrackService method calls on $track ---
        // For $track->properties['osmid'] or similar access:
        $this->track->shouldReceive('getAttribute')->with('properties')->andReturnUsing(function () {
            return $this->mockedTrackProperties;
        });
        // If you access $track->properties directly in your service:
        $this->track->shouldReceive('__get')->with('properties')->andReturnUsing(function () {
            return $this->mockedTrackProperties;
        });

        // Note: $track->wasChanged('geometry') will be mocked per-test as it varies.

        // --- Mocks for Laravel Queue Serialization (ModelIdentifier) ---
        // These are crucial for the BusFake inspection of jobs containing the model.
        $this->track->shouldReceive('getKey')->andReturn(1); // The model's ID
        $this->track->shouldReceive('getQueueableClass')->andReturn(EcTrack::class); // Force the REAL class name
        $this->track->shouldReceive('getQueueableRelations')->andReturn([]); // Standard for no relations to serialize
        $this->track->shouldReceive('getQueueableConnection')->andReturn('test_connection_name'); // Your test DB connection, or null for default

        // Also mock getAttribute('id') as it's a common way to get ID and might be used by jobs indirectly
        $this->track->shouldReceive('getAttribute')->with('id')->andReturn(1);

        // It's good practice to set an ID for the track if it's ever accessed as a public property,
        // but for pure mocks, rely on getKey() or getAttribute('id').
        // $this->track->id = 1; // Avoid direct public property assignment on pure mocks if possible
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
