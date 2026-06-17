<?php

declare(strict_types=1);

namespace Wm\WmPackage\Services\PostHog;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnalyticsService
{
    private const LIBS = ['posthog-ios', 'posthog-android', 'web'];

    private string $host;

    private string $projectId;

    private string $apiKey;

    private int $cacheTtl;

    public function __construct()
    {
        $this->host = rtrim(config('services.posthog.host'), '/');
        $this->projectId = (string) config('services.posthog.project_id');
        $this->apiKey = (string) config('services.posthog.personal_api_key');
        $this->cacheTtl = (int) config('services.posthog.analytics_cache_ttl', 900);
    }

    // -------------------------------------------------------------------------
    // Metodi pubblici per modello
    // -------------------------------------------------------------------------

    public function getLayerUsage(int $id): array
    {
        return $this->getUsage('layerOpened', 'layer_id', $id);
    }

    // Aggiungere qui altri modelli quando necessario, es:
    // public function getEcTrackUsage(int $id): array
    // {
    //     return $this->getUsage('trackViewed', 'track_id', $id);
    // }

    // -------------------------------------------------------------------------
    // Core generico
    // -------------------------------------------------------------------------

    private function getUsage(string $event, string $idProperty, int $id): array
    {
        $cacheKey = "posthog:{$event}:{$id}:usage:last_30_days";

        return Cache::remember(
            $cacheKey,
            now()->addSeconds($this->cacheTtl),
            fn () => $this->fetchUsage($event, $idProperty, $id)
        );
    }

    private function fetchUsage(string $event, string $idProperty, int $id): array
    {
        $dailyBreakdown = $this->queryDailyBreakdown($event, $idProperty, $id);
        $breakdown = $this->queryBreakdown($event, $idProperty, $id);
        $uniqueUsers = $this->queryUniqueUsers($event, $idProperty, $id);
        $total = array_sum(array_column($breakdown, 'total'));

        return [
            'id' => $id,
            'event' => $event,
            'range' => 'last_30_days',
            'total' => $total,
            'daily_breakdown' => $dailyBreakdown,
            'breakdown' => $breakdown,
            'unique_users' => $uniqueUsers,
        ];
    }

    private function queryDailyBreakdown(string $event, string $idProperty, int $id): array
    {
        $libs = $this->libList();
        $sql = <<<SQL
SELECT
    toDate(timestamp) AS day,
    properties.\$lib AS lib,
    count() AS total
FROM events
WHERE event = '{$event}'
  AND properties.{$idProperty} = '{$id}'
  AND properties.\$lib IN ({$libs})
  AND timestamp >= now() - INTERVAL 30 DAY
GROUP BY day, lib
ORDER BY day
SQL;

        return array_map(fn ($row) => [
            'date' => (string) $row[0],
            'lib' => (string) $row[1],
            'total' => (int) $row[2],
        ], $this->runQuery($sql));
    }

    private function queryBreakdown(string $event, string $idProperty, int $id): array
    {
        $libs = $this->libList();
        $sql = <<<SQL
SELECT
    properties.\$lib AS lib,
    count() AS total
FROM events
WHERE event = '{$event}'
  AND properties.{$idProperty} = '{$id}'
  AND properties.\$lib IN ({$libs})
  AND timestamp >= now() - INTERVAL 30 DAY
GROUP BY lib
ORDER BY total DESC
SQL;

        return array_map(fn ($row) => [
            'lib' => (string) $row[0],
            'total' => (int) $row[1],
        ], $this->runQuery($sql));
    }

    private function queryUniqueUsers(string $event, string $idProperty, int $id): int
    {
        $libs = $this->libList();
        $sql = <<<SQL
SELECT
    count(DISTINCT person_id) AS unique_users
FROM events
WHERE event = '{$event}'
  AND properties.{$idProperty} = '{$id}'
  AND properties.\$lib IN ({$libs})
  AND timestamp >= now() - INTERVAL 30 DAY
SQL;

        $rows = $this->runQuery($sql);

        return isset($rows[0][0]) ? (int) $rows[0][0] : 0;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function libList(): string
    {
        return implode(', ', array_map(fn ($l) => "'{$l}'", self::LIBS));
    }

    /** @return list<list<mixed>> */
    private function runQuery(string $sql): array
    {
        $url = "{$this->host}/api/projects/{$this->projectId}/query";

        $response = Http::withToken($this->apiKey)
            ->timeout(10)
            ->post($url, [
                'query' => [
                    'kind' => 'HogQLQuery',
                    'query' => $sql,
                ],
            ]);

        if (! $response->successful()) {
            Log::error('PostHog query failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'sql' => $sql,
            ]);

            return [];
        }

        return $response->json('results', []);
    }
}
