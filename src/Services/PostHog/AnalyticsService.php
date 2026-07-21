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

    private const MOBILE_LIBS = ['posthog-ios', 'posthog-android'];

    private const TTL_MAP = [
        'last_30_days' => 900,
        'last_90_days' => 3600,
        'last_365_days' => 21600,
    ];

    private const LOCK_RANGES = ['last_90_days', 'last_365_days'];

    /** Cap righe finali per le classifiche aggregate (dopo filtro orfani/troncamento). */
    private const RANKING_LIMIT = 20;

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
        return $this->getUsage('layerOpened', 'layer_id', null, $range, fn () => $this->validLayerIdsClause($range));
    }

    public function getAllLayersUsage(string $range = 'last_30_days'): array
    {
        $cacheKey = "posthog:layerOpened:all:ranking:{$range}";

        $rows = $this->rememberWithLock($cacheKey, $range, fn () => $this->queryAllLayersRanking($range));

        $perLayer = [];
        foreach ($rows as $row) {
            $lid = $row['layer_id'];
            if (! isset($perLayer[$lid])) {
                $perLayer[$lid] = ['layer_id' => $lid, 'total' => 0, 'breakdown' => []];
            }
            $perLayer[$lid]['total'] += $row['total'];
            $perLayer[$lid]['breakdown'][] = ['lib' => $row['lib'], 'total' => $row['total']];
        }

        $perLayer = array_values($perLayer);
        usort($perLayer, fn ($a, $b) => $b['total'] <=> $a['total']);

        $layerIds = array_column($perLayer, 'layer_id');
        $layers = Layer::whereIn('id', $layerIds)->get(['id', 'name'])->keyBy('id');

        $result = [];
        foreach ($perLayer as $entry) {
            $layer = $layers->get($entry['layer_id']);
            if (! $layer) {
                continue;
            }

            $result[] = [
                'layer_id' => $entry['layer_id'],
                'name' => $this->resolveLocalizedName($layer, 'Layer', $entry['layer_id']),
                'total' => $entry['total'],
                'breakdown' => $entry['breakdown'],
            ];

            if (count($result) >= self::RANKING_LIMIT) {
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

        $rows = $this->rememberWithLock($cacheKey, $range, fn () => $this->queryTrackDownloads(null, $range));

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

            if (count($result) >= self::RANKING_LIMIT) {
                break;
            }
        }

        return $result;
    }

    public function getAllTracksShares(string $range = 'last_30_days'): array
    {
        $cacheKey = "posthog:contentShared:all:track:{$range}";

        $rows = $this->rememberWithLock($cacheKey, $range, fn () => $this->queryTrackShares($range));

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
                'shares' => $row['shares'],
            ];

            if (count($result) >= self::RANKING_LIMIT) {
                break;
            }
        }

        return $result;
    }

    public function getTotalSearches(string $range = 'last_30_days'): int
    {
        $cacheKey = "posthog:searchPerformed:all:total:{$range}";

        return $this->rememberWithLock($cacheKey, $range, fn () => $this->queryTotalSearches($range));
    }

    public function getTopSearchQueries(string $range = 'last_30_days'): array
    {
        $cacheKey = "posthog:searchPerformed:all:queries:{$range}";

        return $this->rememberWithLock($cacheKey, $range, fn () => $this->queryTopSearchQueries($range));
    }

    // -------------------------------------------------------------------------
    // Core generico
    // -------------------------------------------------------------------------

    /**
     * @param \Closure(): ?string|null $extraFilter clausola WHERE aggiuntiva, valutata pigramente
     *   solo su cache miss (evita di ricalcolarla se il risultato è già in cache) — usata da
     *   getGlobalUsage() per escludere layer cancellati dal totale aggregato (vedi validLayerIdsClause).
     *   null (default) per il path per-layer: nessuna clausola extra, SQL identica a prima.
     */
    private function getUsage(string $event, string $idProperty, ?int $id, string $range, ?\Closure $extraFilter = null): array
    {
        $idSegment = $id ?? 'all';
        $cacheKey = "posthog:{$event}:{$idSegment}:usage:{$range}";

        return $this->rememberWithLock($cacheKey, $range, fn () => $this->fetchUsage($event, $idProperty, $id, $range, $extraFilter));
    }

    private function ttlFor(string $range): int
    {
        return self::TTL_MAP[$range] ?? 21600;
    }

    /**
     * Cache::remember con Cache::lock anti-stampede sui range più costosi
     * (LOCK_RANGES) — evita che più Administrator sulla stessa index Nova
     * lancino la stessa query PostHog in parallelo dopo la scadenza cache.
     *
     * @template T
     * @param \Closure(): T $callback
     * @return T il tipo è quello ritornato da $callback (array per usage/ranking, int per i conteggi) — mixed qui è solo per il vincolo del linguaggio, i chiamanti hanno return type stretti che PHP verifica comunque a runtime
     */
    private function rememberWithLock(string $cacheKey, string $range, \Closure $callback): mixed
    {
        $ttl = $this->ttlFor($range);

        if (in_array($range, self::LOCK_RANGES, true)) {
            $lock = Cache::lock("lock:{$cacheKey}", 15);

            return $lock->block(15, fn () => Cache::remember($cacheKey, now()->addSeconds($ttl), $callback));
        }

        return Cache::remember($cacheKey, now()->addSeconds($ttl), $callback);
    }

    private function fetchUsage(string $event, string $idProperty, ?int $id, string $range, ?\Closure $extraFilter = null): array
    {
        $whereClause = $this->whereClause($range);

        if ($extraFilter !== null) {
            $clause = $extraFilter();
            if ($clause !== null) {
                $whereClause .= " AND {$clause}";
            }
        }

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
        ], $this->runQuery($sql, $id === null));
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
        ], $this->runQuery($sql, $id === null));
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

        $rows = $this->runQuery($sql, $id === null);

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

    private function queryTrackShares(string $range): array
    {
        $whereClause = $this->whereClause($range);
        $mobileLibs = implode(', ', array_map(fn ($l) => "'{$l}'", self::MOBILE_LIBS));

        $sql = <<<SQL
SELECT
    properties.track_id AS track_id,
    count() AS shares
FROM events
WHERE event = 'contentShared'
  AND properties.content_type = 'track'
  AND properties.\$lib IN ({$mobileLibs})
  AND {$whereClause}
GROUP BY track_id
ORDER BY shares DESC
LIMIT 50
SQL;

        return array_map(fn ($row) => [
            'track_id' => (int) $row[0],
            'shares' => (int) $row[1],
        ], $this->runQuery($sql, true));
    }

    private function queryTotalSearches(string $range): int
    {
        $whereClause = $this->whereClause($range);
        $libs = $this->libList();

        // Stessa deduplica per sessione e stesso filtro (results_count > 0,
        // length(query) >= 4) di queryTopSearchQueries(): il KPI deve contare
        // esattamente le sessioni che possono comparire nella classifica sotto,
        // altrimenti i due numeri non tornano (residuo atteso solo dal LIMIT 20
        // della classifica, non da criteri di filtro diversi).
        $sql = <<<SQL
SELECT count() AS total
FROM (
    SELECT
        lower(trim(properties.query)) AS query,
        properties.\$session_id AS session_id,
        properties.results_count AS results_count,
        timestamp,
        row_number() OVER (PARTITION BY session_id ORDER BY timestamp DESC) AS rn
    FROM events
    WHERE event = 'searchPerformed'
      AND properties.query IS NOT NULL AND properties.query != ''
      AND properties.\$lib IN ({$libs})
      AND {$whereClause}
)
WHERE rn = 1 AND results_count > 0 AND length(query) >= 4
SQL;

        $rows = $this->runQuery($sql, true);

        return isset($rows[0][0]) ? (int) $rows[0][0] : 0;
    }

    private function queryTopSearchQueries(string $range): array
    {
        $whereClause = $this->whereClause($range);
        $libs = $this->libList();

        // Il search-as-you-type invia un evento ad ogni tasto premuto: senza deduplica,
        // la classifica sarebbe dominata dai prefissi intermedi ("c", "ca", "cam", ...)
        // invece che dalla ricerca effettiva. Si tiene solo l'ultimo evento per sessione
        // (per timestamp) — la query più completa che l'utente ha effettivamente digitato —
        // e si normalizza maiuscole/spazi per unire varianti dello stesso termine.
        // results_count > 0: solo ricerche andate a buon fine (coerente col nome "più frequenti").
        //
        // length(query) >= 4 scarta il rumore più evidente (frammenti di 1-3 caratteri
        // rimasti come ultimo evento di sessione, es. "ka", "c", "ca"). Non elimina ogni
        // frammento (es. "gran" da "gran sasso" può sopravvivere): un collasso corretto
        // dei prefissi richiederebbe leadInFrame() per sessione, che in questo ambiente
        // HogQL risulta non affidabile (ritorna sempre vuoto). Limite noto, accettato.
        $limit = self::RANKING_LIMIT;
        $sql = <<<SQL
SELECT query, count() AS total
FROM (
    SELECT
        lower(trim(properties.query)) AS query,
        properties.\$session_id AS session_id,
        properties.results_count AS results_count,
        timestamp,
        row_number() OVER (PARTITION BY session_id ORDER BY timestamp DESC) AS rn
    FROM events
    WHERE event = 'searchPerformed'
      AND properties.query IS NOT NULL AND properties.query != ''
      AND properties.\$lib IN ({$libs})
      AND {$whereClause}
)
WHERE rn = 1 AND results_count > 0 AND length(query) >= 4
GROUP BY query
ORDER BY total DESC
LIMIT {$limit}
SQL;

        return array_map(fn ($row) => [
            'query' => (string) $row[0],
            'total' => (int) $row[1],
        ], $this->runQuery($sql, true));
    }

    /**
     * Clausola WHERE che restringe il KPI aggregato ("Aperture totali") ai soli
     * layer ancora presenti nel DB locale — altrimenti eventi storici di layer
     * cancellati (fino a 365gg) gonfiano il totale senza comparire nella
     * classifica "Cammini più aperti" (che li scarta già), disallineando i due
     * numeri. Riusa la stessa query/cache di getAllLayersUsage() (stessa
     * cache key) invece di interrogare PostHog una seconda volta.
     */
    private function validLayerIdsClause(string $range): ?string
    {
        $cacheKey = "posthog:layerOpened:all:ranking:{$range}";
        $rows = $this->rememberWithLock($cacheKey, $range, fn () => $this->queryAllLayersRanking($range));

        if (empty($rows)) {
            return null;
        }

        $layerIds = array_unique(array_column($rows, 'layer_id'));
        $validIds = Layer::whereIn('id', $layerIds)->pluck('id')->all();

        if (empty($validIds)) {
            // Tutti i layer visti in PostHog per questo range sono stati cancellati
            // dal DB locale: forza 0 risultati invece di "IN ()" (SQL non valido)
            // o di omettere il filtro (che li includerebbe di nuovo nel totale).
            return '1 = 0';
        }

        return $this->idInFilterClause('layer_id', $validIds);
    }

    private function queryAllLayersRanking(string $range): array
    {
        $whereClause = $this->whereClause($range);
        $libs = $this->libList();
        $idFilter = $this->idFilterClause('layer_id', null);

        // Nessun cap sensato a livello di riga qui: ogni riga è una coppia
        // (layer_id, lib), non un layer — il ranking vero (top RANKING_LIMIT
        // per layer) si calcola in PHP sommando le righe per layer_id, quindi
        // un LIMIT stretto taglierebbe arbitrariamente prima dell'aggregazione.
        // Il LIMIT sotto è solo una rete di sicurezza contro layer_id corrotti/
        // enormi (oggi ~118 layer x 3 lib = ~354 righe reali, ben sotto il cap).
        // ATTENZIONE: se mai raggiunto, ORDER BY total DESC taglia righe
        // (layer_id, lib) singole, non layer interi — un layer al margine
        // potrebbe perdere il breakdown di una sola piattaforma (es. resta
        // Android ma sparisce iOS) restando comunque in classifica con un
        // totale incompleto, senza errori visibili. Log::warning sotto come
        // tripwire per accorgersene prima che diventi un problema silenzioso.
        $sql = <<<SQL
SELECT
    properties.layer_id AS layer_id,
    properties.\$lib AS lib,
    count() AS total
FROM events
WHERE event = 'layerOpened'
  AND {$idFilter}
  AND properties.\$lib IN ({$libs})
  AND {$whereClause}
GROUP BY layer_id, lib
ORDER BY total DESC
LIMIT 1000
SQL;

        $rows = $this->runQuery($sql, true);

        if (count($rows) >= 1000) {
            Log::warning('queryAllLayersRanking() hit the 1000-row safety cap — ranking may be incomplete', ['range' => $range]);
        }

        return array_map(fn ($row) => [
            'layer_id' => (int) $row[0],
            'lib' => (string) $row[1],
            'total' => (int) $row[2],
        ], $rows);
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
