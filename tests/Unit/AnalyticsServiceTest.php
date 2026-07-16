<?php

namespace Wm\WmPackage\Tests\Unit;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\TestCase;
use Wm\WmPackage\Models\EcTrack;
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

        $this->app['db']->connection()->getSchemaBuilder()->create('layers', function ($table) {
            $table->id();
            $table->json('name')->nullable();
            $table->timestamps();
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

    public function test_get_global_usage_aggregates_across_all_layers(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['results' => [['2026-05-01', 'posthog-android', 100], ['2026-05-01', 'posthog-ios', 40]]])
                ->push(['results' => [['posthog-android', 100], ['posthog-ios', 40]]])
                ->push(['results' => [[55]]]),
        ]);

        $result = (new AnalyticsService)->getGlobalUsage('last_30_days');

        $this->assertNull($result['id']);
        $this->assertSame('layerOpened', $result['event']);
        $this->assertSame(140, $result['total']);
        $this->assertSame(55, $result['unique_users']);
    }

    public function test_get_all_layers_ranking_query_has_no_id_equality_filter(): void
    {
        Http::fake(['*' => Http::response(['results' => []])]);

        $service = new AnalyticsService;
        $method = new \ReflectionMethod($service, 'getUsage');
        $method->setAccessible(true);
        $method->invoke($service, 'layerOpened', 'layer_id', null, 'last_30_days');

        Http::assertSent(function (Request $request) {
            $sql = $request->data()['query']['query'];

            return ! str_contains($sql, "properties.layer_id = '")
                && str_contains($sql, 'properties.layer_id IS NOT NULL');
        });
    }

    // -------------------------------------------------------------------------
    // Ranking globale layer (getAllLayersUsage)
    // -------------------------------------------------------------------------

    public function test_get_all_layers_usage_returns_ranked_layers_with_names(): void
    {
        $this->seedLayersTable([
            ['id' => 10, 'name' => 'Cammino di Santiago'],
            ['id' => 20, 'name' => 'Via Francigena'],
        ]);

        Http::fake(['*' => Http::response(['results' => [['10', 50], ['20', 30]]])]);

        $result = (new AnalyticsService)->getAllLayersUsage('last_30_days');

        $this->assertCount(2, $result);
        $this->assertSame(10, $result[0]['layer_id']);
        $this->assertSame('Cammino di Santiago', $result[0]['name']);
        $this->assertSame(50, $result[0]['total']);
    }

    public function test_get_all_layers_usage_excludes_deleted_layers(): void
    {
        $this->seedLayersTable([
            ['id' => 10, 'name' => 'Cammino di Santiago'],
        ]);

        // layer_id 999 non esiste nel DB locale (cancellato) — deve essere scartato
        Http::fake(['*' => Http::response(['results' => [['999', 80], ['10', 50]]])]);

        $result = (new AnalyticsService)->getAllLayersUsage('last_30_days');

        $this->assertCount(1, $result);
        $this->assertSame(10, $result[0]['layer_id']);
    }

    public function test_get_all_layers_usage_truncates_to_20_after_filtering_orphans(): void
    {
        $seed = [];
        for ($i = 1; $i <= 25; $i++) {
            $seed[] = ['id' => $i, 'name' => "Layer {$i}"];
        }
        $this->seedLayersTable($seed);

        // 25 righe valide (id 1..25, tutte seedate) + 5 righe orfane (layer_id inesistente nel DB
        // locale) intervallate ogni 5 righe valide — verifica che gli orfani vengano scartati
        // *durante* il conteggio verso il cap di 20, non solo in isolamento dal troncamento.
        $orphanIds = [901, 902, 903, 904, 905];
        $rows = [];
        $total = 100;
        for ($i = 1; $i <= 25; $i++) {
            $rows[] = [(string) $i, $total--];
            if ($i % 5 === 0) {
                $rows[] = [(string) array_shift($orphanIds), $total--];
            }
        }

        // 25 righe valide + 5 orfane = 30 righe totali.
        $this->assertCount(30, $rows);

        Http::fake(['*' => Http::response(['results' => $rows])]);

        $result = (new AnalyticsService)->getAllLayersUsage('last_30_days');

        $this->assertCount(20, $result);

        $resultIds = array_column($result, 'layer_id');
        foreach ([901, 902, 903, 904, 905] as $orphanId) {
            $this->assertNotContains($orphanId, $resultIds);
        }
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

    public function test_strict_query_throws_analytics_exception_on_http_failure(): void
    {
        Http::fake(['*' => Http::response('Internal Server Error', 500)]);
        Log::shouldReceive('error')->atLeast()->once();

        $this->expectException(\Wm\WmPackage\Exceptions\AnalyticsQueryException::class);

        $service = new AnalyticsService;
        $method = new \ReflectionMethod($service, 'runQuery');
        $method->setAccessible(true);
        $method->invoke($service, 'SELECT 1', true);
    }

    public function test_non_strict_query_still_returns_empty_array_on_failure(): void
    {
        // Non-regressione: il comportamento di default (usato dal path per-layer) resta invariato
        Http::fake(['*' => Http::response('Internal Server Error', 500)]);
        Log::shouldReceive('error')->atLeast()->once();

        $result = (new AnalyticsService)->getLayerUsage(1);

        $this->assertSame(0, $result['total']);
    }

    public function test_get_all_layers_usage_propagates_failure_when_query_fails(): void
    {
        // getAllLayersUsage() usa runQuery(..., strict: true) al contrario del path per-layer:
        // verifica che il metodo pubblico propaghi davvero l'eccezione end-to-end, non solo
        // runQuery() isolato via reflection (vedi test_strict_query_throws_analytics_exception_on_http_failure).
        Cache::flush();
        Http::fake(['*' => Http::response('Internal Server Error', 500)]);
        Log::shouldReceive('error')->atLeast()->once();

        $this->expectException(\Wm\WmPackage\Exceptions\AnalyticsQueryException::class);

        (new AnalyticsService)->getAllLayersUsage('last_30_days');
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
    // Ranking globale tracce (getAllTracksDownloads)
    // -------------------------------------------------------------------------

    public function test_get_all_tracks_downloads_returns_ranked_tracks_with_names(): void
    {
        $this->seedEcTracksTable([
            ['id' => 1, 'name' => 'Tappa 1'],
            ['id' => 2, 'name' => 'Tappa 2'],
        ]);

        Http::fake(['*' => Http::response(['results' => [['1', 40], ['2', 15]]])]);

        $result = (new AnalyticsService)->getAllTracksDownloads('last_30_days');

        $this->assertCount(2, $result);
        $this->assertSame(1, $result[0]['track_id']);
        $this->assertSame('Tappa 1', $result[0]['name']);
        $this->assertSame(40, $result[0]['downloads']);
        $this->assertSame(2, $result[1]['track_id']);
        $this->assertSame('Tappa 2', $result[1]['name']);
        $this->assertSame(15, $result[1]['downloads']);
    }

    public function test_get_all_tracks_downloads_excludes_deleted_tracks(): void
    {
        $this->seedEcTracksTable([
            ['id' => 1, 'name' => 'Tappa 1'],
        ]);

        // track_id 999 non esiste nel DB locale (cancellato) — deve essere scartato
        Http::fake(['*' => Http::response(['results' => [['999', 80], ['1', 40]]])]);

        $result = (new AnalyticsService)->getAllTracksDownloads('last_30_days');

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['track_id']);
    }

    public function test_get_all_tracks_downloads_truncates_to_20_after_filtering_orphans(): void
    {
        $seed = [];
        for ($i = 1; $i <= 25; $i++) {
            $seed[] = ['id' => $i, 'name' => "Tappa {$i}"];
        }
        $this->seedEcTracksTable($seed);

        // 25 righe valide (id 1..25, tutte seedate) + 5 righe orfane (track_id inesistente nel DB
        // locale) intervallate ogni 5 righe valide — verifica che gli orfani vengano scartati
        // *durante* il conteggio verso il cap di 20, non solo in isolamento dal troncamento.
        $orphanIds = [901, 902, 903, 904, 905];
        $rows = [];
        $total = 100;
        for ($i = 1; $i <= 25; $i++) {
            $rows[] = [(string) $i, $total--];
            if ($i % 5 === 0) {
                $rows[] = [(string) array_shift($orphanIds), $total--];
            }
        }

        $this->assertCount(30, $rows);

        Http::fake(['*' => Http::response(['results' => $rows])]);

        $result = (new AnalyticsService)->getAllTracksDownloads('last_30_days');

        $this->assertCount(20, $result);

        $resultIds = array_column($result, 'track_id');
        foreach ([901, 902, 903, 904, 905] as $orphanId) {
            $this->assertNotContains($orphanId, $resultIds);
        }
    }

    public function test_get_all_tracks_downloads_propagates_failure_when_query_fails(): void
    {
        // getAllTracksDownloads() usa runQuery(..., strict: true) al contrario del path per-layer:
        // verifica che il metodo pubblico propaghi davvero l'eccezione end-to-end.
        Cache::flush();
        Http::fake(['*' => Http::response('Internal Server Error', 500)]);
        Log::shouldReceive('error')->atLeast()->once();

        $this->expectException(\Wm\WmPackage\Exceptions\AnalyticsQueryException::class);

        (new AnalyticsService)->getAllTracksDownloads('last_30_days');
    }

    public function test_query_track_downloads_with_null_ids_omits_in_clause(): void
    {
        Http::fake(['*' => Http::response(['results' => []])]);

        $service = new AnalyticsService;
        $method = new \ReflectionMethod($service, 'queryTrackDownloads');
        $method->setAccessible(true);
        $method->invoke($service, null, 'last_30_days');

        Http::assertSent(function (Request $request) {
            $sql = $request->data()['query']['query'];

            return ! str_contains($sql, 'IN (')
                && str_contains($sql, 'properties.track_id IS NOT NULL')
                && str_contains($sql, 'LIMIT 50');
        });
    }

    public function test_query_track_downloads_with_concrete_ids_uses_in_clause_and_no_limit(): void
    {
        // Non-regressione: il path esistente (getLayerTrackDownloads) passa un array concreto
        // e non deve avere LIMIT né passare per lo strict mode.
        Http::fake(['*' => Http::response(['results' => []])]);

        $service = new AnalyticsService;
        $method = new \ReflectionMethod($service, 'queryTrackDownloads');
        $method->setAccessible(true);
        $method->invoke($service, [42, 7], 'last_30_days');

        Http::assertSent(function (Request $request) {
            $sql = $request->data()['query']['query'];

            return str_contains($sql, "properties.track_id IN ('42', '7')")
                && ! str_contains($sql, 'LIMIT 50');
        });
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function seedEcTracksTable(array $tracks): void
    {
        foreach ($tracks as $track) {
            EcTrack::query()->getConnection()->table('ec_tracks')->insert([
                'id' => $track['id'],
                'name' => json_encode(['it' => $track['name']]),
            ]);
        }
    }

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

    private function seedLayersTable(array $layers): void
    {
        // La tabella `layers` viene creata una sola volta in defineDatabaseMigrations()
        // per evitare "table already exists" quando più test la seedano nella stessa run.
        foreach ($layers as $layer) {
            Layer::query()->getConnection()->table('layers')->insert([
                'id' => $layer['id'],
                'name' => json_encode(['it' => $layer['name']]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
