<?php

declare(strict_types=1);

namespace Wm\WmPackage\Services\PostHog;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Exceptions\AnalyticsQueryException;
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

    public function getGlobalUsage(string $range = 'last_30_days'): array
    {
        return $this->getUsage('layerOpened', 'layer_id', null, $range);
    }

    public function getAllLayersUsage(string $range = 'last_30_days'): array
    {
        $cacheKey = "posthog:layerOpened:all:ranking:{$range}";
        $ttl = $this->ttlFor($range);

        $rows = Cache::remember(
            $cacheKey,
            now()->addSeconds($ttl),
            fn () => $this->queryAllLayersRanking($range)
        );

        $layerIds = array_column($rows, 'layer_id');
        $layers = Layer::whereIn('id', $layerIds)->get(['id', 'name'])->keyBy('id');

        $result = [];
        foreach ($rows as $row) {
            $layer = $layers->get($row['layer_id']);
            if (! $layer) {
                continue;
            }

            $name = $this->resolveLocalizedName($layer, 'Layer', $row['layer_id']);

            $result[] = [
                'layer_id' => $row['layer_id'],
                'name' => $name,
                'total' => $row['total'],
            ];

            if (count($result) >= 20) {
                break;
            }
        }

        return $result;
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
            $name = $track ? $this->resolveLocalizedName($track, 'Track', $row['track_id']) : "Track #{$row['track_id']}";

            return [
                'track_id' => $row['track_id'],
                'name' => $name,
                'downloads' => $row['downloads'],
            ];
        }, $rows);
    }

    public function getAllTracksDownloads(string $range = 'last_30_days'): array
    {
        $cacheKey = "posthog:trackDownloaded:all:downloads:{$range}";
        $ttl = $this->ttlFor($range);

        $rows = Cache::remember(
            $cacheKey,
            now()->addSeconds($ttl),
            fn () => $this->queryTrackDownloads(null, $range)
        );

        $ecTrackModel = config('wm-package.ec_track_model', EcTrack::class);
        $tracks = $ecTrackModel::whereIn('id', array_column($rows, 'track_id'))
            ->get(['id', 'name'])
            ->keyBy('id');

        $result = [];
        foreach ($rows as $row) {
            $track = $tracks->get($row['track_id']);
            if (! $track) {
                continue;
            }

            $result[] = [
                'track_id' => $row['track_id'],
                'name' => $this->resolveLocalizedName($track, 'Track', $row['track_id']),
                'downloads' => $row['downloads'],
            ];

            if (count($result) >= 20) {
                break;
            }
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Core generico
    // -------------------------------------------------------------------------

    private function getUsage(string $event, string $idProperty, ?int $id, string $range): array
    {
        $idSegment = $id ?? 'all';
        $cacheKey = "posthog:{$event}:{$idSegment}:usage:{$range}";
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

    private function fetchUsage(string $event, string $idProperty, ?int $id, string $range): array
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

    private function queryDailyBreakdown(string $event, string $idProperty, ?int $id, string $whereClause): array
    {
        $libs = $this->libList();
        $sql = <<<SQL
SELECT
    toDate(timestamp) AS day,
    properties.\$lib AS lib,
    count() AS total
FROM events
WHERE event = '{$event}'
  AND {$this->idFilterClause($idProperty, $id)}
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

    private function queryBreakdown(string $event, string $idProperty, ?int $id, string $whereClause): array
    {
        $libs = $this->libList();
        $sql = <<<SQL
SELECT
    properties.\$lib AS lib,
    count() AS total
FROM events
WHERE event = '{$event}'
  AND {$this->idFilterClause($idProperty, $id)}
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

    private function queryUniqueUsers(string $event, string $idProperty, ?int $id, string $whereClause): int
    {
        $libs = $this->libList();
        $sql = <<<SQL
SELECT
    count(DISTINCT person_id) AS unique_users
FROM events
WHERE event = '{$event}'
  AND {$this->idFilterClause($idProperty, $id)}
  AND properties.\$lib IN ({$libs})
  AND {$whereClause}
SQL;

        $rows = $this->runQuery($sql);

        return isset($rows[0][0]) ? (int) $rows[0][0] : 0;
    }

    private function queryTrackDownloads(?array $trackIds, string $range): array
    {
        $whereClause = $this->whereClause($range);
        $idFilter = $this->idInFilterClause('track_id', $trackIds);
        $limitClause = $trackIds === null ? 'LIMIT 50' : '';
        $strict = $trackIds === null;

        $sql = <<<SQL
SELECT
    properties.track_id AS track_id,
    count() AS downloads
FROM events
WHERE event = 'trackDownloaded'
  AND {$idFilter}
  AND {$whereClause}
GROUP BY track_id
ORDER BY downloads DESC
{$limitClause}
SQL;

        return array_map(fn ($row) => [
            'track_id' => (int) $row[0],
            'downloads' => (int) $row[1],
        ], $this->runQuery($sql, $strict));
    }

    private function queryAllLayersRanking(string $range): array
    {
        $whereClause = $this->whereClause($range);
        $libs = $this->libList();
        $idFilter = $this->idFilterClause('layer_id', null);

        $sql = <<<SQL
SELECT
    properties.layer_id AS layer_id,
    count() AS total
FROM events
WHERE event = 'layerOpened'
  AND {$idFilter}
  AND properties.\$lib IN ({$libs})
  AND {$whereClause}
GROUP BY layer_id
ORDER BY total DESC
LIMIT 50
SQL;

        return array_map(fn ($row) => [
            'layer_id' => (int) $row[0],
            'total' => (int) $row[1],
        ], $this->runQuery($sql, true));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function resolveLocalizedName($model, string $fallbackPrefix, int|string $fallbackId): string
    {
        foreach (['it', 'en', app()->getLocale()] as $locale) {
            $candidate = $model->getTranslation('name', $locale, false);
            if (! empty($candidate)) {
                return $candidate;
            }
        }

        return "{$fallbackPrefix} #{$fallbackId}";
    }

    private function idFilterClause(string $idProperty, ?int $id): string
    {
        if ($id === null) {
            return "properties.{$idProperty} IS NOT NULL AND properties.{$idProperty} != ''";
        }

        return "properties.{$idProperty} = '{$id}'";
    }

    private function idInFilterClause(string $idProperty, ?array $ids): string
    {
        if ($ids === null) {
            return "properties.{$idProperty} IS NOT NULL AND properties.{$idProperty} != ''";
        }

        $inList = implode(', ', array_map(fn ($v) => "'{$v}'", $ids));

        return "properties.{$idProperty} IN ({$inList})";
    }

    private function libList(): string
    {
        return implode(', ', array_map(fn ($l) => "'{$l}'", self::LIBS));
    }

    /** @return list<list<mixed>> */
    private function runQuery(string $sql, bool $strict = false): array
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

            if ($strict) {
                throw new AnalyticsQueryException("PostHog query failed with status {$response->status()}");
            }

            return [];
        }

        return $response->json('results', []);
    }
}
