<?php

declare(strict_types=1);

namespace Wm\WmPackage\Services\PostHog;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;

class AnalyticsService
{
    private const LIBS = ['posthog-ios', 'posthog-android', 'web'];

    private const TTL_MAP = [
        'last_30_days' => 900,
        'last_90_days' => 3600,
        'last_365_days' => 21600,
    ];

    private const LOCK_RANGES = ['last_90_days', 'last_365_days'];

    private string $host;

    private string $projectId;

    private string $apiKey;

    public function __construct()
    {
        $this->host = rtrim(config('services.posthog.host'), '/');
        $this->projectId = (string) config('services.posthog.project_id');
        $this->apiKey = (string) config('services.posthog.personal_api_key');
    }

    // -------------------------------------------------------------------------
    // Metodi pubblici per modello
    // -------------------------------------------------------------------------

    public function getLayerUsage(int $id, string $range = 'last_30_days'): array
    {
        return $this->getUsage('layerOpened', 'layer_id', $id, $range);
    }

    public function getLayerTrackDownloads(Layer $layer, string $range = 'last_30_days'): array
    {
        $trackIds = $layer->ecTracks()->pluck('ec_tracks.id')->toArray();

        if (empty($trackIds)) {
            return [];
        }

        $cacheKey = 'posthog:trackDownloaded:layer:'.$layer->id.':downloads:'.$range;
        $ttl = $this->ttlFor($range);

        $rows = Cache::remember(
            $cacheKey,
            now()->addSeconds($ttl),
            fn () => $this->queryTrackDownloads($trackIds, $range)
        );

        $ecTrackModel = config('wm-package.ec_track_model', EcTrack::class);
        $tracks = $ecTrackModel::whereIn('id', array_column($rows, 'track_id'))
            ->get(['id', 'name'])
            ->keyBy('id');

        return array_map(function ($row) use ($tracks) {
            $track = $tracks->get($row['track_id']);
            $name = null;
            if ($track) {
                foreach (['it', 'en', app()->getLocale()] as $locale) {
                    $candidate = $track->getTranslation('name', $locale, false);
                    if (! empty($candidate)) {
                        $name = $candidate;
                        break;
                    }
                }
            }

            return [
                'track_id' => $row['track_id'],
                'name' => $name ?? "Track #{$row['track_id']}",
                'downloads' => $row['downloads'],
            ];
        }, $rows);
    }

    // -------------------------------------------------------------------------
    // Core generico
    // -------------------------------------------------------------------------

    private function getUsage(string $event, string $idProperty, int $id, string $range): array
    {
        $cacheKey = "posthog:{$event}:{$id}:usage:{$range}";
        $ttl = $this->ttlFor($range);

        if (in_array($range, self::LOCK_RANGES, true)) {
            $lock = Cache::lock("lock:{$cacheKey}", 15);

            return $lock->block(15, fn () => Cache::remember(
                $cacheKey,
                now()->addSeconds($ttl),
                fn () => $this->fetchUsage($event, $idProperty, $id, $range)
            ));
        }

        return Cache::remember(
            $cacheKey,
            now()->addSeconds($ttl),
            fn () => $this->fetchUsage($event, $idProperty, $id, $range)
        );
    }

    private function ttlFor(string $range): int
    {
        return self::TTL_MAP[$range] ?? 21600;
    }

    private function fetchUsage(string $event, string $idProperty, int $id, string $range): array
    {
        $whereClause = $this->whereClause($range);

        $dailyBreakdown = $this->queryDailyBreakdown($event, $idProperty, $id, $whereClause);
        $breakdown = $this->queryBreakdown($event, $idProperty, $id, $whereClause);
        $uniqueUsers = $this->queryUniqueUsers($event, $idProperty, $id, $whereClause);
        $total = array_sum(array_column($breakdown, 'total'));

        return [
            'id' => $id,
            'event' => $event,
            'range' => $range,
            'total' => $total,
            'daily_breakdown' => $dailyBreakdown,
            'breakdown' => $breakdown,
            'unique_users' => $uniqueUsers,
        ];
    }

    private function whereClause(string $range): string
    {
        if (str_starts_with($range, 'month:')) {
            $month = substr($range, 6); // es. '2026-05'
            $start = $month.'-01';
            $end = Carbon::parse($start)->addMonth()->format('Y-m-d');

            return "timestamp >= '{$start}' AND timestamp < '{$end}'";
        }

        $days = match ($range) {
            'last_90_days' => 90,
            'last_365_days' => 365,
            default => 30,
        };

        return "timestamp >= now() - INTERVAL {$days} DAY";
    }

    private function queryDailyBreakdown(string $event, string $idProperty, int $id, string $whereClause): array
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
  AND {$whereClause}
GROUP BY day, lib
ORDER BY day
SQL;

        return array_map(fn ($row) => [
            'date' => (string) $row[0],
            'lib' => (string) $row[1],
            'total' => (int) $row[2],
        ], $this->runQuery($sql));
    }

    private function queryBreakdown(string $event, string $idProperty, int $id, string $whereClause): array
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
  AND {$whereClause}
GROUP BY lib
ORDER BY total DESC
SQL;

        return array_map(fn ($row) => [
            'lib' => (string) $row[0],
            'total' => (int) $row[1],
        ], $this->runQuery($sql));
    }

    private function queryUniqueUsers(string $event, string $idProperty, int $id, string $whereClause): int
    {
        $libs = $this->libList();
        $sql = <<<SQL
SELECT
    count(DISTINCT person_id) AS unique_users
FROM events
WHERE event = '{$event}'
  AND properties.{$idProperty} = '{$id}'
  AND properties.\$lib IN ({$libs})
  AND {$whereClause}
SQL;

        $rows = $this->runQuery($sql);

        return isset($rows[0][0]) ? (int) $rows[0][0] : 0;
    }

    private function queryTrackDownloads(array $trackIds, string $range): array
    {
        $whereClause = $this->whereClause($range);
        $inList = implode(', ', array_map(fn ($id) => "'{$id}'", $trackIds));

        $sql = <<<SQL
SELECT
    properties.track_id AS track_id,
    count() AS downloads
FROM events
WHERE event = 'trackDownloaded'
  AND properties.track_id IN ({$inList})
  AND {$whereClause}
GROUP BY track_id
ORDER BY downloads DESC
SQL;

        return array_map(fn ($row) => [
            'track_id' => (int) $row[0],
            'downloads' => (int) $row[1],
        ], $this->runQuery($sql));
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
