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
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\App;

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
        $track = EcTrack::factory()->createQuietly([
            'geometry' => null
        ]);
        $track->geometry = 'LINESTRING(1 1, 2 2)';
        $track->save();

        $updatedTrack = EcTrack::find($track->id);

        $this->ecTrackService->updateDataChain($updatedTrack);

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
        $track = EcTrack::factory()->createQuietly([
            'properties' => json_encode(['osmid' => 123]),
            'geometry' => 'LINESTRING(3 3, 4 4)'
        ]);

        $this->ecTrackService->updateDataChain($track);

        Bus::assertDispatched(UpdateEcTrackFromOsmJob::class, function ($job) use ($track) {
            return $job->track->id === $track->id;
        });
    }
}
