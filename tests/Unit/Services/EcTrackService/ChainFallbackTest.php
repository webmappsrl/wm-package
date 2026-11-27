<?php

namespace Tests\Unit\Services\EcTrackService;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Wm\WmPackage\Jobs\Pbf\GenerateEcTrackPBFBatch;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAppRelationsInfoJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackDemJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackOrderRelatedPoi;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;

/**
 * Pubblicazione di ripiego delle catene di EcTrack (oc:9050): se un job della catena fallisce, il
 * `catch()` accoda comunque PBF (dove la catena ha i job della geometria) e pubblicazione.
 *
 * `Bus::fake()` non esegue le catene: il `catch()` si prende dal primo job accodato
 * (`chainCatchCallbacks`) e si invoca a mano, come farebbe il worker al fallimento.
 */
class ChainFallbackTest extends AbstractEcTrackServiceTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        App::factory()->createQuietly();
    }

    private function track(array $properties = []): EcTrack
    {
        return EcTrack::factory()->createQuietly([
            'geometry' => DB::raw("ST_GeomFromText('MULTILINESTRING Z((0 0 0, 1 1 100, 2 2 200))', 4326)"),
            'properties' => $properties,
            'osmid' => null,
        ]);
    }

    /**
     * Prende il primo job della catena, controlla che porti un solo `catch()`, lo invoca con un
     * errore e verifica la catena di ripiego.
     */
    private function assertFallbackChain(string $firstJobClass, array $expected): void
    {
        $job = Bus::dispatched($firstJobClass)->first();
        $this->assertNotNull($job, "{$firstJobClass} non accodato");
        $this->assertCount(1, $job->chainCatchCallbacks);

        Bus::fake();
        $job->invokeChainCatchCallbacks(new RuntimeException('boom'));

        Bus::assertChained($expected);
    }

    public function test_update_chain_with_geometry_falls_back_to_pbf_and_publication()
    {
        $track = $this->track();
        $track->geometry = 'LINESTRING(1 1 0, 2 2 0)';
        $track->saveQuietly();

        $this->ecTrackService->updateDataChain($track);

        $this->assertFallbackChain(UpdateEcTrackDemJob::class, [
            GenerateEcTrackPBFBatch::class,
            UpdateEcTrackAwsJob::class,
            UpdateEcTrackAppRelationsInfoJob::class,
            UpdateEcTrackOrderRelatedPoi::class,
        ]);
    }

    public function test_update_chain_without_geometry_falls_back_to_publication()
    {
        $track = $this->track();

        $this->ecTrackService->updateDataChain($track);

        $this->assertFallbackChain(UpdateEcTrackAwsJob::class, [
            UpdateEcTrackAwsJob::class,
            UpdateEcTrackAppRelationsInfoJob::class,
            UpdateEcTrackOrderRelatedPoi::class,
        ]);
    }

    public function test_dem_chain_falls_back_to_pbf_and_publication()
    {
        $track = $this->track();

        $this->ecTrackService->dispatchDemChain($track);

        $this->assertFallbackChain(UpdateEcTrackDemJob::class, [
            GenerateEcTrackPBFBatch::class,
            UpdateEcTrackAwsJob::class,
            UpdateEcTrackAppRelationsInfoJob::class,
            UpdateEcTrackOrderRelatedPoi::class,
        ]);
    }

    public function test_create_chain_falls_back_to_its_own_publication()
    {
        $track = $this->track();

        $this->ecTrackService->createDataChain($track);

        $this->assertFallbackChain(UpdateEcTrackDemJob::class, [
            UpdateEcTrackAwsJob::class,
            UpdateEcTrackOrderRelatedPoi::class,
        ]);
    }

    public function test_reverse_with_geometry_falls_back_to_pbf_and_publication()
    {
        $track = $this->track();

        $this->ecTrackService->reverse($track, true, []);

        $this->assertFallbackChain(UpdateEcTrackDemJob::class, [
            GenerateEcTrackPBFBatch::class,
            UpdateEcTrackAwsJob::class,
            UpdateEcTrackAppRelationsInfoJob::class,
            UpdateEcTrackOrderRelatedPoi::class,
        ]);
    }

    public function test_reverse_swap_only_falls_back_to_pbf_and_aws()
    {
        $track = $this->track(['from' => 'A', 'to' => 'B']);

        $this->ecTrackService->reverse($track, false, ['from_to']);

        $this->assertFallbackChain(GenerateEcTrackPBFBatch::class, [
            GenerateEcTrackPBFBatch::class,
            UpdateEcTrackAwsJob::class,
        ]);
    }

    public function test_fallback_does_nothing_when_track_was_deleted()
    {
        $this->ecTrackService->dispatchFallbackJobs(999999999, [UpdateEcTrackAwsJob::class]);

        Bus::assertNothingDispatched();
    }

    public function test_fallback_chain_has_no_catch_callback()
    {
        $track = $this->track();

        $this->ecTrackService->dispatchFallbackJobs($track->id, [
            UpdateEcTrackAwsJob::class,
            UpdateEcTrackOrderRelatedPoi::class,
        ]);

        $job = Bus::dispatched(UpdateEcTrackAwsJob::class)->first();
        $this->assertNotNull($job);
        $this->assertEmpty($job->chainCatchCallbacks ?? []);
    }

    public function test_chain_with_fallback_is_serializable()
    {
        $track = $this->track();

        $this->ecTrackService->updateDataChain($track);

        // Il worker riceve il job deserializzato dal payload: la closure del `catch()` deve
        // ricostruirsi e accodare il ripiego anche dopo serialize/unserialize.
        $job = unserialize(serialize(Bus::dispatched(UpdateEcTrackAwsJob::class)->first()));

        Bus::fake();
        $job->invokeChainCatchCallbacks(new RuntimeException('boom'));

        Bus::assertChained([
            UpdateEcTrackAwsJob::class,
            UpdateEcTrackAppRelationsInfoJob::class,
            UpdateEcTrackOrderRelatedPoi::class,
        ]);
    }
}
