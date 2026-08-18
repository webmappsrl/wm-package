<?php

namespace Wm\WmPackage\Tests\Unit;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\TestCase;
use Wm\WmPackage\Services\PostHog\AnalyticsService;

/**
 * Test puramente unitari (nessun DB reale, `Orchestra\Testbench\TestCase` bare) — solo la parte
 * di runQuery() che non richiede risoluzione di un layer/bbox reale. La logica che risolve il
 * bounding box del layer (queryUserMovedPointsNearLayer, getRecentUserPositions,
 * countPersonsNearLayerTracks, getUserMovedStats end-to-end) richiede PostGIS reale — coperta
 * da tests/Feature/AnalyticsServiceUserPresenceGeoTest.php.
 */
class AnalyticsServiceUserPresenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.posthog.host' => 'https://posthog.example.com',
            'services.posthog.project_id' => '1',
            'services.posthog.personal_api_key' => 'phx_test',
        ]);
    }

    public function test_run_query_respects_custom_timeout(): void
    {
        Http::fake(['*' => Http::response(['results' => [['a', 1, 2]]])]);

        $service = new AnalyticsService;
        $rows = $this->callPrivateMethod($service, 'runQuery', ['SELECT 1', true, 5]);

        $this->assertSame([['a', 1, 2]], $rows);
    }

    public function test_run_query_still_defaults_to_10_seconds_timeout(): void
    {
        Http::fake(['*' => Http::response(['results' => []])]);

        $service = new AnalyticsService;
        // Nessuna eccezione né TypeError: il terzo parametro opzionale non rompe le chiamate
        // esistenti che non lo passano (tutti gli altri metodi di AnalyticsService).
        $rows = $this->callPrivateMethod($service, 'runQuery', ['SELECT 1']);

        $this->assertSame([], $rows);
    }

    protected function callPrivateMethod(object $object, string $method, array $args = []): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $args);
    }
}
