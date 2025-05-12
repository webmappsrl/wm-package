<?php

namespace Tests\Unit\Services\EcTrackService;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;

class UpdateDataChainTest extends AbstractEcTrackServiceTest
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        App::factory()->createQuietly();
    }

    public function test_update_data_chain_dispatches_at_least_one_job()
    {
        // Create track first
        $track = EcTrack::factory()->createQuietly();

        // Now update geometry to trigger wasChanged (use 3D coordinates)
        $track->geometry = 'LINESTRING(1 1 0, 2 2 0)'; // New 3D geometry
        $track->saveQuietly(); // Use saveQuietly to avoid triggering observers if any

        // Pass the $track instance directly, preserving its wasChanged state
        $this->ecTrackService->updateDataChain($track);

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
        $track = EcTrack::factory()->createQuietly(); // Create without specific osmid initially

        // Get properties, modify, and set back
        $properties = $track->properties ?? []; // Get current properties or default to empty array
        $properties['osmid'] = 123; // Add/update osmid
        $track->properties = $properties; // Assign the modified array back

        $track->saveQuietly(); // Save the changes

        // Fetch the instance again to be sure, though $track should be updated
        $updatedTrack = EcTrack::find($track->id);

        $this->ecTrackService->updateDataChain($updatedTrack);

        // Check that UpdateEcTrackFromOsmJob was dispatched (not necessarily chained)
        Bus::assertDispatched(UpdateEcTrackFromOsmJob::class, function ($job) use ($updatedTrack) {
            return $job->getEcTrack()->id === $updatedTrack->id;
        });
    }
}
