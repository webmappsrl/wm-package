<?php

namespace Wm\WmPackage\Tests\Unit;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\TestCase;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\PostHog\AnalyticsService;

class AnalyticsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.posthog.host' => 'https://posthog.example.com',
            'services.posthog.project_id' => '1',
            'services.posthog.personal_api_key' => 'phx_test',
            'services.posthog.analytics_cache_ttl' => 900,
        ]);
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->app['db']->connection()->getSchemaBuilder()->create('ec_tracks', function ($table) {
            $table->id();
            $table->json('name')->nullable();
        });
    }

    // -------------------------------------------------------------------------
    // Cache
    // -------------------------------------------------------------------------

    public function test_second_call_uses_cache_and_does_not_hit_http(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push($this->fakePostHogResponses())
                ->push($this->fakePostHogResponses())
                ->push($this->fakePostHogResponses()),
        ]);

        $service = new AnalyticsService;
        $service->getLayerUsage(1);
        $service->getLayerUsage(1);

        // 3 query per la prima chiamata (daily, breakdown, unique_users), zero per la seconda
        Http::assertSentCount(3);
    }

    public function test_cache_key_is_scoped_per_model_id(): void
    {
        Http::fake(['*' => Http::response($this->fakePostHogResponses())]);

        Cache::flush();
        $service = new AnalyticsService;
        $service->getLayerUsage(1);
        $service->getLayerUsage(2);

        // 3 query per layer 1 + 3 query per layer 2 = 6 totali
        Http::assertSentCount(6);
    }

    // -------------------------------------------------------------------------
    // Output normalizzato
    // -------------------------------------------------------------------------

    public function test_get_layer_usage_returns_expected_structure(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['results' => [['2026-05-01', 'posthog-android', 10], ['2026-05-01', 'posthog-ios', 3]]])  // daily breakdown
                ->push(['results' => [['posthog-android', 10], ['posthog-ios', 3]]])                              // breakdown
                ->push(['results' => [[7]]]),                                                                      // unique users
        ]);

        $result = (new AnalyticsService)->getLayerUsage(55);

        $this->assertSame(55, $result['id']);
        $this->assertSame('layerOpened', $result['event']);
        $this->assertSame('last_30_days', $result['range']);
        $this->assertSame(13, $result['total']); // 10 + 3
        $this->assertSame(7, $result['unique_users']);
        $this->assertCount(2, $result['daily_breakdown']);
        $this->assertCount(2, $result['breakdown']);
    }

    public function test_daily_breakdown_rows_are_normalized_correctly(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['results' => [['2026-05-01', 'posthog-android', 10]]])
                ->push(['results' => []])
                ->push(['results' => [[0]]]),
        ]);

        $result = (new AnalyticsService)->getLayerUsage(1);

        $this->assertSame('2026-05-01', $result['daily_breakdown'][0]['date']);
        $this->assertSame('posthog-android', $result['daily_breakdown'][0]['lib']);
        $this->assertSame(10, $result['daily_breakdown'][0]['total']);
    }

    public function test_breakdown_rows_are_normalized_correctly(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['results' => []])
                ->push(['results' => [['posthog-ios', 42]]])
                ->push(['results' => [[0]]]),
        ]);

        $result = (new AnalyticsService)->getLayerUsage(1);

        $this->assertSame('posthog-ios', $result['breakdown'][0]['lib']);
        $this->assertSame(42, $result['breakdown'][0]['total']);
    }

    // -------------------------------------------------------------------------
    // Gestione errori HTTP
    // -------------------------------------------------------------------------

    public function test_failed_http_response_returns_empty_results_without_throwing(): void
    {
        Http::fake(['*' => Http::response('Internal Server Error', 500)]);
        Log::shouldReceive('error')->times(3); // una per ogni query

        $result = (new AnalyticsService)->getLayerUsage(1);

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['daily_breakdown']);
        $this->assertSame([], $result['breakdown']);
        $this->assertSame(0, $result['unique_users']);
    }

    public function test_failed_http_response_logs_error(): void
    {
        Http::fake(['*' => Http::response('Bad Request', 400)]);
        Log::shouldReceive('error')
            ->atLeast()->once()
            ->withArgs(fn ($msg) => $msg === 'PostHog query failed');

        (new AnalyticsService)->getLayerUsage(1);
    }

    // -------------------------------------------------------------------------
    // Query HTTP
    // -------------------------------------------------------------------------

    public function test_query_sends_correct_authorization_header(): void
    {
        Http::fake(['*' => Http::response($this->fakePostHogResponses())]);

        (new AnalyticsService)->getLayerUsage(1);

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer phx_test')
        );
    }

    public function test_query_posts_to_correct_endpoint(): void
    {
        Http::fake(['*' => Http::response($this->fakePostHogResponses())]);

        (new AnalyticsService)->getLayerUsage(1);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://posthog.example.com/api/projects/1/query'
        );
    }

    public function test_query_sends_hogql_kind(): void
    {
        Http::fake(['*' => Http::response($this->fakePostHogResponses())]);

        (new AnalyticsService)->getLayerUsage(1);

        Http::assertSent(fn (Request $request) => $request->data()['query']['kind'] === 'HogQLQuery'
        );
    }

    // -------------------------------------------------------------------------
    // Range dinamico
    // -------------------------------------------------------------------------

    public function test_range_is_included_in_cache_key(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['results' => []])
                ->push(['results' => []])
                ->push(['results' => [[0]]])
                ->push(['results' => []])
                ->push(['results' => []])
                ->push(['results' => [[0]]]),
        ]);

        Cache::flush();
        $service = new AnalyticsService;
        $service->getLayerUsage(1, 'last_30_days');
        $service->getLayerUsage(1, 'last_90_days');

        // 3 query per 30gg + 3 query per 90gg = 6 (nessuna cache hit tra range diversi)
        Http::assertSentCount(6);
    }

    public function test_same_range_second_call_uses_cache(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['results' => []])
                ->push(['results' => []])
                ->push(['results' => [[0]]]),
        ]);

        Cache::flush();
        $service = new AnalyticsService;
        $service->getLayerUsage(1, 'last_90_days');
        $service->getLayerUsage(1, 'last_90_days');

        Http::assertSentCount(3); // solo la prima chiamata va su PostHog
    }

    public function test_month_range_returns_correct_range_field(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['results' => []])
                ->push(['results' => []])
                ->push(['results' => [[0]]]),
        ]);

        $result = (new AnalyticsService)->getLayerUsage(1, 'month:2026-03');

        $this->assertSame('month:2026-03', $result['range']);
    }

    public function test_365_days_range_returns_correct_range_field(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['results' => []])
                ->push(['results' => []])
                ->push(['results' => [[0]]]),
        ]);

        $result = (new AnalyticsService)->getLayerUsage(1, 'last_365_days');

        $this->assertSame('last_365_days', $result['range']);
    }

    // -------------------------------------------------------------------------
    // Track downloads
    // -------------------------------------------------------------------------

    public function test_get_layer_track_downloads_returns_normalized_structure(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['results' => [['42', 15], ['7', 3]]]),
        ]);

        $layer = $this->createLayerMockWithTrackIds([42, 7]);

        $result = (new AnalyticsService)->getLayerTrackDownloads($layer, 'last_30_days');

        $this->assertCount(2, $result);
        $this->assertSame(42, $result[0]['track_id']);
        $this->assertSame(15, $result[0]['downloads']);
        $this->assertArrayHasKey('name', $result[0]);
        $this->assertSame(7, $result[1]['track_id']);
        $this->assertSame(3, $result[1]['downloads']);
        $this->assertArrayHasKey('name', $result[1]);
    }

    public function test_get_layer_track_downloads_returns_empty_when_no_tracks(): void
    {
        $layer = $this->createLayerMockWithTrackIds([]);

        $result = (new AnalyticsService)->getLayerTrackDownloads($layer, 'last_30_days');

        $this->assertSame([], $result);
        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function fakePostHogResponses(): array
    {
        return ['results' => []];
    }

    private function createLayerMockWithTrackIds(array $ids): object
    {
        $relation = \Mockery::mock(MorphToMany::class);
        $relation->shouldReceive('pluck')->with('ec_tracks.id')->andReturn(collect($ids));

        $layer = \Mockery::mock(Layer::class)->makePartial();
        $layer->shouldReceive('ecTracks')->andReturn($relation);
        $layer->id = 99;

        return $layer;
    }
}
