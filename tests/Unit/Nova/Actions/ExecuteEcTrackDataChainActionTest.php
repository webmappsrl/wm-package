<?php

namespace Wm\WmPackage\Tests\Unit\Nova\Actions;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Mockery;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackDemJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Nova\Actions\ExecuteEcTrackDataChainAction;
use Wm\WmPackage\Services\Models\EcTrackService;
use Wm\WmPackage\Tests\TestCase;

/**
 * @method void setUp()
 * @method void tearDown()
 * @method void assertTrue(bool $condition, string $message = '')
 * @method void assertEquals(mixed $expected, mixed $actual, string $message = '')
 */
class ExecuteEcTrackDataChainActionTest extends TestCase
{
    use RefreshDatabase;

    protected ExecuteEcTrackDataChainAction $action;

    protected EcTrackService $ecTrackService;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        config(['wm-package.shard_name' => 'test_shard']);
        $this->ecTrackService = app(EcTrackService::class);

        // Configure database connection if environment variables are provided
        if (env('DB_HOST') && env('DB_DATABASE')) {
            config([
                'database.connections.pgsql' => [
                    'driver' => 'pgsql',
                    'host' => env('DB_HOST'),
                    'port' => env('DB_PORT', '5432'),
                    'database' => env('DB_DATABASE'),
                    'username' => env('DB_USERNAME'),
                    'password' => env('DB_PASSWORD'),
                    'charset' => 'utf8',
                    'prefix' => '',
                    'prefix_indexes' => true,
                    'search_path' => 'public',
                    'sslmode' => 'prefer',
                ],
                'database.default' => 'pgsql',
            ]);
        }
    }

    public function test_action_with_empty_chain_calls_init_data_chain()
    {
        $action = new ExecuteEcTrackDataChainAction([]);
        $track = EcTrack::factory()->createQuietly();
        $models = collect([$track]);

        $ecTrackServiceMock = Mockery::mock(EcTrackService::class);
        $ecTrackServiceMock->shouldReceive('createDataChain')
            ->once()
            ->with($track);

        app()->instance(EcTrackService::class, $ecTrackServiceMock);

        $result = $action->handle(new ActionFields(collect(), collect()), $models);

        $ecTrackServiceMock->shouldHaveReceived('createDataChain');
        $this->assertInstanceOf(ActionResponse::class, $result);
    }

    public function test_action_with_custom_chain_dispatches_jobs()
    {
        $track = EcTrack::factory()->createQuietly();
        $chain = [
            fn ($track) => new UpdateEcTrackDemJob($track),
            fn ($track) => new UpdateEcTrackAwsJob($track),
        ];

        $action = new ExecuteEcTrackDataChainAction($chain);
        $models = collect([$track]);

        $action->handle(new ActionFields(collect(), collect()), $models);

        Bus::assertChained([
            UpdateEcTrackDemJob::class,
            UpdateEcTrackAwsJob::class,
        ]);
    }

    public function test_action_processes_app_tracks()
    {
        $app = App::factory()->createQuietly();
        $track1 = EcTrack::factory()->createQuietly(['app_id' => $app->id]);
        $track2 = EcTrack::factory()->createQuietly(['app_id' => $app->id]);

        $action = new ExecuteEcTrackDataChainAction([]);
        $models = collect([$app]);

        $ecTrackServiceMock = Mockery::mock(EcTrackService::class);
        $ecTrackServiceMock->shouldReceive('createDataChain')
            ->twice()
            ->with(Mockery::on(function ($track) use ($track1, $track2) {
                return $track->id === $track1->id || $track->id === $track2->id;
            }));

        app()->instance(EcTrackService::class, $ecTrackServiceMock);

        $result = $action->handle(new ActionFields(collect(), collect()), $models);

        $ecTrackServiceMock->shouldHaveReceived('createDataChain');
        $this->assertInstanceOf(ActionResponse::class, $result);
        $this->assertStringContainsString('2', json_encode($result));
    }

    public function test_action_processes_layer_tracks()
    {
        $app = App::factory()->createQuietly();
        $layer = Layer::factory()->createQuietly(['app_id' => $app->id]);
        $track1 = EcTrack::factory()->createQuietly();
        $track2 = EcTrack::factory()->createQuietly();

        $layer->ecTracks()->attach([$track1->id, $track2->id]);

        $action = new ExecuteEcTrackDataChainAction([]);
        $models = collect([$layer]);

        $ecTrackServiceMock = Mockery::mock(EcTrackService::class);
        $ecTrackServiceMock->shouldReceive('createDataChain')
            ->twice()
            ->with(Mockery::on(function ($track) use ($track1, $track2) {
                return $track->id === $track1->id || $track->id === $track2->id;
            }));

        app()->instance(EcTrackService::class, $ecTrackServiceMock);

        $result = $action->handle(new ActionFields(collect(), collect()), $models);

        $ecTrackServiceMock->shouldHaveReceived('createDataChain');
        $this->assertInstanceOf(ActionResponse::class, $result);
        $this->assertStringContainsString('2', json_encode($result));
    }

    public function test_action_processes_single_track()
    {
        $track = EcTrack::factory()->createQuietly();
        $action = new ExecuteEcTrackDataChainAction([]);
        $models = collect([$track]);

        $ecTrackServiceMock = Mockery::mock(EcTrackService::class);
        $ecTrackServiceMock->shouldReceive('createDataChain')
            ->once()
            ->with($track);

        app()->instance(EcTrackService::class, $ecTrackServiceMock);

        $result = $action->handle(new ActionFields(collect(), collect()), $models);

        $ecTrackServiceMock->shouldHaveReceived('createDataChain');
        $this->assertInstanceOf(ActionResponse::class, $result);
        $this->assertStringContainsString('1', json_encode($result));
    }

    public function test_action_with_custom_name()
    {
        $customName = 'Custom Action Name';
        $action = new ExecuteEcTrackDataChainAction([], $customName);

        $this->assertEquals($customName, $action->name());
    }

    public function test_action_with_default_name()
    {
        $action = new ExecuteEcTrackDataChainAction;

        $this->assertEquals(__('Process Track Data'), $action->name());
    }

    public function test_action_uses_custom_chunk_size()
    {
        $app = App::factory()->createQuietly();
        EcTrack::factory()->count(250)->createQuietly(['app_id' => $app->id]);

        $action = new ExecuteEcTrackDataChainAction([], null, 50);
        $models = collect([$app]);

        $ecTrackServiceMock = Mockery::mock(EcTrackService::class);
        $ecTrackServiceMock->shouldReceive('createDataChain')
            ->times(250);

        app()->instance(EcTrackService::class, $ecTrackServiceMock);

        $result = $action->handle(new ActionFields(collect(), collect()), $models);

        $ecTrackServiceMock->shouldHaveReceived('createDataChain');
        $this->assertInstanceOf(ActionResponse::class, $result);
        $this->assertStringContainsString('250', json_encode($result));
    }

    public function test_action_handles_errors_gracefully()
    {
        $track1 = EcTrack::factory()->createQuietly();
        $track2 = EcTrack::factory()->createQuietly();

        $action = new ExecuteEcTrackDataChainAction([]);
        $models = collect([$track1, $track2]);

        $ecTrackServiceMock = Mockery::mock(EcTrackService::class);
        $ecTrackServiceMock->shouldReceive('createDataChain')
            ->once()
            ->with($track1)
            ->andThrow(new \Exception('Test error'));

        $ecTrackServiceMock->shouldReceive('createDataChain')
            ->once()
            ->with($track2);

        app()->instance(EcTrackService::class, $ecTrackServiceMock);

        $result = $action->handle(new ActionFields(collect(), collect()), $models);

        // Should still process the second track
        $this->assertInstanceOf(ActionResponse::class, $result);
        $this->assertStringContainsString('1', json_encode($result));
    }

    public function test_action_skips_invalid_jobs_in_chain()
    {
        $track = EcTrack::factory()->createQuietly();
        $chain = [
            fn ($track) => new UpdateEcTrackDemJob($track),
            'invalid_job_class', // Invalid job
            fn ($track) => new UpdateEcTrackAwsJob($track),
        ];

        $action = new ExecuteEcTrackDataChainAction($chain);
        $models = collect([$track]);

        $action->handle(new ActionFields(collect(), collect()), $models);

        // Should only dispatch valid jobs
        Bus::assertChained([
            UpdateEcTrackDemJob::class,
            UpdateEcTrackAwsJob::class,
        ]);
    }

    public function test_custom_chain_falls_back_to_its_publication_jobs()
    {
        // oc:9050: «Regenerate Taxonomy Where» accoda [SyncModelTaxonomyWhereJob, UpdateEcTrackAwsJob]:
        // se il primo fallisce, il JSON su AWS va riscritto lo stesso.
        $track = EcTrack::factory()->createQuietly(['osmid' => null]);
        $action = new ExecuteEcTrackDataChainAction([
            fn ($track) => new UpdateEcTrackDemJob($track),
            fn ($track) => new UpdateEcTrackAwsJob($track),
        ]);

        $action->handle(new ActionFields(collect(), collect()), collect([$track]));

        $job = Bus::dispatched(UpdateEcTrackDemJob::class)->first();
        $this->assertCount(1, $job->chainCatchCallbacks);

        Bus::fake();
        $job->invokeChainCatchCallbacks(new \RuntimeException('boom'));
        Bus::assertDispatched(UpdateEcTrackAwsJob::class);
    }

    public function test_custom_chain_without_publication_jobs_has_no_fallback()
    {
        $track = EcTrack::factory()->createQuietly(['osmid' => null]);
        $action = new ExecuteEcTrackDataChainAction([
            fn ($track) => new UpdateEcTrackDemJob($track),
        ]);

        $action->handle(new ActionFields(collect(), collect()), collect([$track]));

        $job = Bus::dispatched(UpdateEcTrackDemJob::class)->first();
        $this->assertEmpty($job->chainCatchCallbacks ?? []);
    }

    public function test_custom_chain_made_only_of_publication_jobs_has_no_fallback()
    {
        // «Update Tracks on AWS» = [UpdateEcTrackAwsJob]: il ripiego rifarebbe lo stesso job appena
        // fallito, raddoppiando i tentativi con lo storage irraggiungibile (oc:9050).
        $track = EcTrack::factory()->createQuietly(['osmid' => null]);
        $action = new ExecuteEcTrackDataChainAction([
            fn ($track) => new UpdateEcTrackAwsJob($track),
        ]);

        $action->handle(new ActionFields(collect(), collect()), collect([$track]));

        $job = Bus::dispatched(UpdateEcTrackAwsJob::class)->first();
        $this->assertNotNull($job);
        $this->assertEmpty($job->chainCatchCallbacks ?? []);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
