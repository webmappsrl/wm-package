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
            // valore non vuoto: evita che il guard fail-open in shardNameClause() chiami Log::warning()
            // inaspettatamente, cosa che farebbe fallire i test che mockano Log in modo strict (Log::shouldReceive('error'))
            'wm-package.shard_name' => 'default',
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
        $this->seedLayersTable([
            ['id' => 10, 'name' => 'Cammino di Santiago'],
        ]);

        Http::fake([
            // 1a chiamata: ranking layer, usata per risolvere il filtro "solo layer validi".
            '*' => Http::sequence()
                ->push(['results' => [['10', 'web', 140]]])
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

    public function test_get_global_usage_excludes_deleted_layers_from_total(): void
    {
        // Layer 10 esiste nel DB locale, layer 999 no (cancellato) — la query di
        // ranking li vede entrambi in PostHog, ma il filtro extra deve restringere
        // daily_breakdown/breakdown/unique_users al solo layer 10, escludendo 999.
        $this->seedLayersTable([
            ['id' => 10, 'name' => 'Cammino di Santiago'],
        ]);

        Http::fake([
            '*' => Http::sequence()
                ->push(['results' => [['10', 'web', 50], ['999', 'web', 30]]])
                ->push(['results' => [['2026-05-01', 'web', 50]]])
                ->push(['results' => [['web', 50]]])
                ->push(['results' => [[7]]]),
        ]);

        (new AnalyticsService)->getGlobalUsage('last_30_days');

        $requests = Http::recorded();
        $this->assertCount(4, $requests);

        // Le 3 query successive alla ranking (daily breakdown, breakdown, unique users)
        // devono includere il filtro sul solo layer valido ed escludere l'orfano.
        // Il filtro ora usa l'espressione di fallback per layer_id (coalesce + extract).
        for ($i = 1; $i < 4; $i++) {
            [$request] = $requests[$i];
            $sql = $request->data()['query']['query'];
            $this->assertStringContainsString(
                "coalesce(nullIf(properties.layer_id, ''), nullIf(extract(properties.layer_label, '^([0-9]+)'), '')) IN ('10')",
                $sql
            );
            $this->assertStringNotContainsString('999', $sql);
        }
    }

    public function test_get_global_usage_forces_zero_when_all_layers_deleted(): void
    {
        // Nessun layer del ranking esiste più nel DB locale: il filtro deve forzare
        // "1 = 0" (0 risultati) invece di omettere il filtro o generare "IN ()" non valido.
        Http::fake([
            '*' => Http::sequence()
                ->push(['results' => [['999', 'web', 30]]])
                ->push(['results' => [['2026-05-01', 'web', 30]]])
                ->push(['results' => [['web', 30]]])
                ->push(['results' => [[5]]]),
        ]);

        (new AnalyticsService)->getGlobalUsage('last_30_days');

        $requests = Http::recorded();
        $this->assertCount(4, $requests);

        for ($i = 1; $i < 4; $i++) {
            [$request] = $requests[$i];
            $sql = $request->data()['query']['query'];
            $this->assertStringContainsString('1 = 0', $sql);
        }
    }

    public function test_get_layer_usage_sql_is_unaffected_by_extra_filter_mechanism(): void
    {
        // Regressione: il path per-layer non deve mai ricevere il filtro extra
        // (getLayerUsage() non lo passa) — SQL identica a prima di questa fix.
        Http::fake(['*' => Http::response(['results' => []])]);

        (new AnalyticsService)->getLayerUsage(10, 'last_30_days');

        $requests = Http::recorded();
        foreach ($requests as [$request]) {
            $sql = $request->data()['query']['query'];
            $this->assertStringNotContainsString('1 = 0', $sql);
            $this->assertStringNotContainsString(')) IN (', $sql);
        }
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

            // Il filtro per layer_id con $id=null (ranking globale) usa l'espressione fallback coalesce.
            // Non contiene un'uguaglianza per un ID specifico (come = '56'), ma contiene l'espressione
            // fallback che usa IS NOT NULL e != '' (per il filtro nullo).
            return ! str_contains($sql, "')) = '")
                && str_contains($sql, 'coalesce(nullIf(properties.layer_id,')
                && str_contains($sql, 'IS NOT NULL');
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

        Http::fake(['*' => Http::response(['results' => [['10', 'web', 50], ['20', 'web', 30]]])]);

        $result = (new AnalyticsService)->getAllLayersUsage('last_30_days');

        $this->assertCount(2, $result);
        $this->assertSame(10, $result[0]['layer_id']);
        $this->assertSame('Cammino di Santiago', $result[0]['name']);
        $this->assertSame(50, $result[0]['total']);
        $this->assertSame([['lib' => 'web', 'total' => 50]], $result[0]['breakdown']);
    }

    public function test_get_all_layers_usage_excludes_deleted_layers(): void
    {
        $this->seedLayersTable([
            ['id' => 10, 'name' => 'Cammino di Santiago'],
        ]);

        // layer_id 999 non esiste nel DB locale (cancellato) — deve essere scartato
        Http::fake(['*' => Http::response(['results' => [['999', 'web', 80], ['10', 'web', 50]]])]);

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
            $rows[] = [(string) $i, 'web', $total--];
            if ($i % 5 === 0) {
                $rows[] = [(string) array_shift($orphanIds), 'web', $total--];
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

    public function test_get_global_usage_propagates_failure_when_query_fails(): void
    {
        // getGlobalUsage() (id: null) usa runQuery(..., strict: true) sulle 3 query
        // condivise con il path per-layer — verifica che l'aggregato KPI propaghi
        // davvero il fallimento invece di tornare 0 silenziosamente (vedi
        // test_non_strict_query_still_returns_empty_array_on_failure per la
        // non-regressione sul path per-layer, che deve restare lenient).
        Cache::flush();
        Http::fake(['*' => Http::response('Internal Server Error', 500)]);
        Log::shouldReceive('error')->atLeast()->once();

        $this->expectException(\Wm\WmPackage\Exceptions\AnalyticsQueryException::class);

        (new AnalyticsService)->getGlobalUsage('last_30_days');
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
    // Filtro shard_name (oc:8354)
    // -------------------------------------------------------------------------

    public function test_where_clause_filters_by_configured_shard_name(): void
    {
        config(['wm-package.shard_name' => 'camminiditalia']);
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);

        (new AnalyticsService)->getLayerUsage(1);

        Http::assertSent(function (Request $request) {
            $sql = $request->data()['query']['query'];

            return str_contains($sql, "properties.shard_name._value = 'camminiditalia'")
                && str_contains($sql, "properties.shard_name = 'camminiditalia'");
        });
    }

    public function test_where_clause_uses_analytics_shard_name_when_configured(): void
    {
        config([
            'wm-package.shard_name' => 'camminiditaliadev',
            'wm-package.analytics_shard_name' => 'camminiditalia',
        ]);
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);

        (new AnalyticsService)->getLayerUsage(1);

        Http::assertSent(function (Request $request) {
            $sql = $request->data()['query']['query'];

            return str_contains($sql, "properties.shard_name._value = 'camminiditalia'")
                && str_contains($sql, "properties.shard_name = 'camminiditalia'")
                && ! str_contains($sql, 'camminiditaliadev');
        });
    }

    public function test_where_clause_falls_back_to_shard_name_when_analytics_shard_name_not_configured(): void
    {
        config([
            'wm-package.shard_name' => 'camminiditalia',
            'wm-package.analytics_shard_name' => null,
        ]);
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);

        (new AnalyticsService)->getLayerUsage(1);

        Http::assertSent(function (Request $request) {
            $sql = $request->data()['query']['query'];

            return str_contains($sql, "properties.shard_name._value = 'camminiditalia'")
                && str_contains($sql, "properties.shard_name = 'camminiditalia'");
        });
    }

    public function test_where_clause_falls_back_to_shard_name_when_analytics_shard_name_is_whitespace(): void
    {
        config([
            'wm-package.shard_name' => 'camminiditalia',
            'wm-package.analytics_shard_name' => '   ',
        ]);
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);

        (new AnalyticsService)->getLayerUsage(1);

        Http::assertSent(fn (Request $request) => str_contains(
            $request->data()['query']['query'],
            "properties.shard_name._value = 'camminiditalia'"
        ));
    }

    public function test_where_clause_disables_shard_filter_and_logs_warning_when_shard_name_not_configured(): void
    {
        config(['wm-package.shard_name' => '']);
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);
        Log::shouldReceive('warning')
            ->atLeast()->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'shard_name non configurato'));

        (new AnalyticsService)->getLayerUsage(1);

        Http::assertSent(function (Request $request) {
            $sql = $request->data()['query']['query'];

            return ! str_contains($sql, 'shard_name');
        });
    }

    public function test_get_global_usage_sql_includes_shard_name_filter(): void
    {
        config(['wm-package.shard_name' => 'camminiditalia']);
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);

        (new AnalyticsService)->getGlobalUsage();

        Http::assertSent(fn (Request $request) => str_contains(
            $request->data()['query']['query'],
            "properties.shard_name._value = 'camminiditalia'"
        ));
    }

    public function test_get_all_layers_usage_sql_includes_shard_name_filter(): void
    {
        config(['wm-package.shard_name' => 'camminiditalia']);
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);

        (new AnalyticsService)->getAllLayersUsage();

        Http::assertSent(fn (Request $request) => str_contains(
            $request->data()['query']['query'],
            "properties.shard_name._value = 'camminiditalia'"
        ));
    }

    public function test_get_layer_track_downloads_sql_includes_shard_name_filter(): void
    {
        config(['wm-package.shard_name' => 'camminiditalia']);
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);
        $layer = $this->createLayerMockWithTrackIds([1, 2]);

        (new AnalyticsService)->getLayerTrackDownloads($layer);

        Http::assertSent(fn (Request $request) => str_contains(
            $request->data()['query']['query'],
            "properties.shard_name._value = 'camminiditalia'"
        ));
    }

    public function test_get_all_tracks_downloads_sql_includes_shard_name_filter(): void
    {
        config(['wm-package.shard_name' => 'camminiditalia']);
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);

        (new AnalyticsService)->getAllTracksDownloads();

        Http::assertSent(fn (Request $request) => str_contains(
            $request->data()['query']['query'],
            "properties.shard_name._value = 'camminiditalia'"
        ));
    }

    public function test_get_all_tracks_shares_sql_includes_shard_name_filter(): void
    {
        config(['wm-package.shard_name' => 'camminiditalia']);
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);

        (new AnalyticsService)->getAllTracksShares();

        Http::assertSent(fn (Request $request) => str_contains(
            $request->data()['query']['query'],
            "properties.shard_name._value = 'camminiditalia'"
        ));
    }

    public function test_get_total_searches_sql_includes_shard_name_filter(): void
    {
        config(['wm-package.shard_name' => 'camminiditalia']);
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);

        (new AnalyticsService)->getTotalSearches();

        Http::assertSent(fn (Request $request) => str_contains(
            $request->data()['query']['query'],
            "properties.shard_name._value = 'camminiditalia'"
        ));
    }

    public function test_get_top_search_queries_sql_includes_shard_name_filter(): void
    {
        config(['wm-package.shard_name' => 'camminiditalia']);
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);

        (new AnalyticsService)->getTopSearchQueries();

        Http::assertSent(fn (Request $request) => str_contains(
            $request->data()['query']['query'],
            "properties.shard_name._value = 'camminiditalia'"
        ));
    }

    public function test_where_clause_escapes_quotes_and_backslashes_in_shard_name(): void
    {
        $shardName = "cammini d'italia\\";
        config(['wm-package.shard_name' => $shardName]);
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);

        (new AnalyticsService)->getLayerUsage(1);

        // Valore atteso scritto a mano (non ricalcolato con l'algoritmo di produzione) per non
        // mascherare un eventuale bug di escaping dietro un'asserzione tautologica: "cammini
        // d'italia\" -> backslash escapato prima dell'apice -> "cammini d\'italia\\".
        $escaped = 'cammini d\\\'italia\\\\';

        Http::assertSent(function (Request $request) use ($escaped) {
            $sql = $request->data()['query']['query'];

            return str_contains($sql, "AND (properties.shard_name._value = '{$escaped}'")
                && str_contains(
                    $sql,
                    "properties.shard_name._value = '{$escaped}' OR properties.shard_name = '{$escaped}'"
                );
        });
    }

    // -------------------------------------------------------------------------
    // Fallback layer_id -> layer_label (oc:8354)
    // -------------------------------------------------------------------------

    public function test_get_layer_usage_filter_falls_back_to_layer_label_when_layer_id_missing(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);

        (new AnalyticsService)->getLayerUsage(56);

        Http::assertSent(function (Request $request) {
            $sql = $request->data()['query']['query'];

            return str_contains(
                $sql,
                "coalesce(nullIf(properties.layer_id, ''), nullIf(extract(properties.layer_label, '^([0-9]+)'), '')) = '56'"
            );
        });
    }

    public function test_query_all_layers_ranking_sql_uses_layer_label_fallback_in_select_and_group_by(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);

        (new AnalyticsService)->getAllLayersUsage();

        Http::assertSent(function (Request $request) {
            $sql = $request->data()['query']['query'];

            return str_contains(
                $sql,
                "coalesce(nullIf(properties.layer_id, ''), nullIf(extract(properties.layer_label, '^([0-9]+)'), '')) AS layer_id"
            ) && str_contains($sql, 'GROUP BY layer_id, lib');
        });
    }

    public function test_get_global_usage_sql_uses_layer_label_fallback_in_id_filter(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);

        (new AnalyticsService)->getGlobalUsage();

        Http::assertSent(function (Request $request) {
            $sql = $request->data()['query']['query'];

            return str_contains(
                $sql,
                "coalesce(nullIf(properties.layer_id, ''), nullIf(extract(properties.layer_label, '^([0-9]+)'), '')) IS NOT NULL AND coalesce(nullIf(properties.layer_id, ''), nullIf(extract(properties.layer_label, '^([0-9]+)'), '')) != ''"
            );
        });
    }

    public function test_get_global_usage_extra_filter_uses_layer_label_fallback_in_in_clause(): void
    {
        $this->seedLayersTable([
            ['id' => 10, 'name' => 'Cammino di Santiago'],
        ]);
        Cache::flush();

        Http::fake([
            '*' => Http::sequence()
                ->push(['results' => [['10', 'web', 5]]]) // ranking: un layer valido
                ->push(['results' => []]) // daily breakdown
                ->push(['results' => []]) // breakdown
                ->push(['results' => [[0]]]), // unique users
        ]);

        (new AnalyticsService)->getGlobalUsage('last_30_days');

        $requests = Http::recorded();
        // le 3 richieste successive alla ranking (daily, breakdown, unique_users) devono contenere il filtro IN con l'espressione di fallback
        for ($i = 1; $i < 4; $i++) {
            [$request] = $requests[$i];
            $sql = $request->data()['query']['query'];
            $this->assertStringContainsString(
                "coalesce(nullIf(properties.layer_id, ''), nullIf(extract(properties.layer_label, '^([0-9]+)'), '')) IN ('10')",
                $sql
            );
        }
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

    // -------------------------------------------------------------------------
    // Ranking globale condivisioni tracce (getAllTracksShares)
    // -------------------------------------------------------------------------

    public function test_get_all_tracks_shares_returns_ranked_tracks_with_names(): void
    {
        $this->seedEcTracksTable([
            ['id' => 1, 'name' => 'Tappa 1'],
            ['id' => 2, 'name' => 'Tappa 2'],
        ]);

        Http::fake(['*' => Http::response(['results' => [['1', 25], ['2', 10]]])]);

        $result = (new AnalyticsService)->getAllTracksShares('last_30_days');

        $this->assertCount(2, $result);
        $this->assertSame(1, $result[0]['track_id']);
        $this->assertSame('Tappa 1', $result[0]['name']);
        $this->assertSame(25, $result[0]['shares']);
        $this->assertSame(2, $result[1]['track_id']);
        $this->assertSame('Tappa 2', $result[1]['name']);
        $this->assertSame(10, $result[1]['shares']);
    }

    public function test_get_all_tracks_shares_excludes_deleted_tracks(): void
    {
        $this->seedEcTracksTable([
            ['id' => 1, 'name' => 'Tappa 1'],
        ]);

        // track_id 999 non esiste nel DB locale (cancellato) — deve essere scartato
        Http::fake(['*' => Http::response(['results' => [['999', 80], ['1', 25]]])]);

        $result = (new AnalyticsService)->getAllTracksShares('last_30_days');

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['track_id']);
    }

    public function test_get_all_tracks_shares_truncates_to_20_after_filtering_orphans(): void
    {
        $seed = [];
        for ($i = 1; $i <= 25; $i++) {
            $seed[] = ['id' => $i, 'name' => "Tappa {$i}"];
        }
        $this->seedEcTracksTable($seed);

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

        $result = (new AnalyticsService)->getAllTracksShares('last_30_days');

        $this->assertCount(20, $result);

        $resultIds = array_column($result, 'track_id');
        foreach ([901, 902, 903, 904, 905] as $orphanId) {
            $this->assertNotContains($orphanId, $resultIds);
        }
    }

    public function test_get_all_tracks_shares_propagates_failure_when_query_fails(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response('Internal Server Error', 500)]);
        Log::shouldReceive('error')->atLeast()->once();

        $this->expectException(\Wm\WmPackage\Exceptions\AnalyticsQueryException::class);

        (new AnalyticsService)->getAllTracksShares('last_30_days');
    }

    public function test_query_track_shares_filters_mobile_libs_only(): void
    {
        Http::fake(['*' => Http::response(['results' => []])]);

        $service = new AnalyticsService;
        $method = new \ReflectionMethod($service, 'queryTrackShares');
        $method->setAccessible(true);
        $method->invoke($service, 'last_30_days');

        Http::assertSent(function (Request $request) {
            $sql = $request->data()['query']['query'];

            return str_contains($sql, "event = 'contentShared'")
                && str_contains($sql, "properties.content_type = 'track'")
                && str_contains($sql, "'posthog-ios', 'posthog-android'")
                && ! str_contains($sql, "'web'");
        });
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
    // Ricerche (getTotalSearches / getTopSearchQueries)
    // -------------------------------------------------------------------------

    public function test_get_total_searches_matches_sum_of_top_search_queries(): void
    {
        // Entrambe le sessioni valide (results_count > 0, query di almeno 4 caratteri)
        // devono contribuire sia al totale sia alla classifica, con lo stesso filtro:
        // il totale non deve contare sessioni a zero risultati o frammenti di digitazione
        // che la classifica sotto scarta, altrimenti i due numeri non tornano.
        Http::fake([
            '*' => Http::sequence()
                ->push(['results' => [[2]]])
                ->push(['results' => [['cammino di santiago', 2]]]),
        ]);

        $service = new AnalyticsService;

        $total = $service->getTotalSearches('last_30_days');
        $topQueries = $service->getTopSearchQueries('last_30_days');

        $this->assertSame(2, $total);
        $this->assertSame(2, array_sum(array_column($topQueries, 'total')));
    }

    public function test_get_total_searches_query_applies_same_filters_as_top_search_queries(): void
    {
        Http::fake(['*' => Http::response(['results' => [[0]]])]);

        (new AnalyticsService)->getTotalSearches('last_30_days');

        Http::assertSent(function (Request $request) {
            $sql = $request->data()['query']['query'];

            return str_contains($sql, 'results_count > 0')
                && str_contains($sql, 'length(query) >= 4')
                && str_contains($sql, 'row_number() OVER (PARTITION BY session_id ORDER BY timestamp DESC)');
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
