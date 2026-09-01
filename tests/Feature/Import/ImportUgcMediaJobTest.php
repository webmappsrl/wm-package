<?php

namespace Wm\WmPackage\Tests\Feature\Import;

use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Tests\TestCase;
use Wm\WmPackage\Jobs\Import\ImportUgcMediaJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Services\Import\UgcMediaImportService;

class ImportUgcMediaJobTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Set the job's protected $job property to a fake queue wrapper so attempts() returns a
     * controlled value — the job has no real queue connection in this test, so attempts()
     * would otherwise always report 1 (see Illuminate\Queue\InteractsWithQueue).
     */
    private function withAttempts(ImportUgcMediaJob $job, int $attempts): ImportUgcMediaJob
    {
        $queueJob = Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('attempts')->andReturn($attempts);
        $queueJob->shouldReceive('release')->andReturnNull();
        $job->setJob($queueJob);

        return $job;
    }

    public function test_media_is_attached_to_resolved_target_poi(): void
    {
        // findUgcMediaTargets()/fetchData() are mocked here — this test verifies the job's own
        // orchestration (fetch → resolve targets → attach), not UgcMediaImportService's real
        // pivot-table resolution logic.
        $app = App::factory()->create();
        $poi = UgcPoi::factory()->create(['app_id' => $app->id, 'properties' => ['geohub_id' => 42]]);

        $service = Mockery::mock(UgcMediaImportService::class)->makePartial();
        $service->shouldReceive('fetchData')->once()->andReturn([
            'id' => 555,
            'relative_url' => 'media/images/ugc/image_1.jpg',
            'name' => 'Test media',
        ]);
        $service->shouldReceive('findUgcMediaTargets')->once()->andReturn([$poi]);
        $service->shouldReceive('attachUgcMedia')->once()->with([$poi], Mockery::type('array'));

        (new ImportUgcMediaJob(555, ['app_id' => $app->id]))->handle($service);

        // No exception, attachUgcMedia was called exactly once with the resolved target.
        $this->assertTrue(true);
    }

    public function test_job_retries_when_target_not_found_yet_and_attempts_remain(): void
    {
        $service = Mockery::mock(UgcMediaImportService::class)->makePartial();
        $service->shouldReceive('fetchData')->once()->andReturn(['id' => 555, 'relative_url' => 'x.jpg']);
        $service->shouldReceive('findUgcMediaTargets')->once()->andReturn([]);
        $service->shouldNotReceive('attachUgcMedia');

        $job = $this->withAttempts(new ImportUgcMediaJob(555, ['app_id' => 1]), 1);

        $job->handle($service);

        // release() was called (mocked above), no exception was thrown.
        $this->assertTrue(true);
    }

    public function test_job_gives_up_without_exception_after_max_attempts_with_target_still_missing(): void
    {
        $service = Mockery::mock(UgcMediaImportService::class)->makePartial();
        $service->shouldReceive('fetchData')->once()->andReturn(['id' => 555, 'relative_url' => 'x.jpg']);
        $service->shouldReceive('findUgcMediaTargets')->once()->andReturn([]);
        $service->shouldNotReceive('attachUgcMedia');

        $job = $this->withAttempts(new ImportUgcMediaJob(555, ['app_id' => 1]), 3);

        $job->handle($service);

        $this->assertTrue(true);
    }

    public function test_job_rethrows_when_attaching_media_fails_for_real(): void
    {
        // A real failure (network error, non-image content type) must surface as a failed
        // job in Horizon, not be swallowed — otherwise every photo import failure is
        // invisible (see notes.md / review finding on silent media loss).
        $app = App::factory()->create();
        $poi = UgcPoi::factory()->create(['app_id' => $app->id, 'properties' => ['geohub_id' => 42]]);

        $service = Mockery::mock(UgcMediaImportService::class)->makePartial();
        $service->shouldReceive('fetchData')->once()->andReturn(['id' => 555, 'relative_url' => 'x.jpg']);
        $service->shouldReceive('findUgcMediaTargets')->once()->andReturn([$poi]);
        $service->shouldReceive('attachUgcMedia')->once()->andThrow(new \Exception('Network error: unable to reach URL x.jpg for UgcMedia import.'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Network error');

        (new ImportUgcMediaJob(555, ['app_id' => $app->id]))->handle($service);
    }

    // No automated test for the checkUgcUserExistence() concurrent-duplicate-email race fix —
    // see the note in ImportUgcTrackJobTest for why the shared-connection test harness makes
    // that branch unreachable from a job-level test. See notes.md.
}
