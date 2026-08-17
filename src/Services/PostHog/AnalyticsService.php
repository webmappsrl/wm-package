<?php

declare(strict_types=1);

namespace Wm\WmPackage\Services\PostHog;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    /** Cap punti GPS processati dal match PostGIS bulk per il KPI (rete di sicurezza — il volume è già contenuto dal pre-filtro per bounding box geografico del layer, non da un'aggregazione temporale). */
    private const MAX_USER_PRESENCE_POINTS = 5000;

    /** Cap analogo per il ranking globale "Cammini più frequentati" — più alto perché il bbox qui è l'unione di tutte le tracce, non di un solo layer, quindi il volume atteso è naturalmente più alto. */
    private const MAX_GLOBAL_USER_PRESENCE_POINTS = 20000;

    /** Cap sulle persone distinte mostrate come marker live su un singolo layer (rete di sicurezza, coerente con gli altri cap di questa classe — con Log::warning se raggiunto). */
    private const MAX_RECENT_POSITIONS = 500;

    /** Timeout dedicato più aggressivo del default 10s: query bulk più pesante, non deve bloccare l'intera risposta della card se PostHog è lento. */
    private const USER_PRESENCE_TIMEOUT_SECONDS = 5;

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

    /**
     * Ranking di tutti i layer per "utenti sul cammino" (stesso concetto di getUserMovedStats(),
     * ma per tutti i layer in un colpo — mostrato nell'index Nova, non nel detail di un layer).
     * A differenza di getAllLayersUsage() (che raggruppa `layerOpened` per `layer_id`, già presente
     * come property flat sull'evento) qui non esiste una scorciatoia: `userMoved` non porta un
     * layer_id affidabile, il match resta geografico. Bbox dell'unione di tutte le tracce (non per
     * singolo layer) per contenere il volume, poi un'unica query PostGIS bulk che risale al layer
     * di ogni traccia via la pivot `layerables` — una query, non un loop per layer (un loop su
     * ~100+ layer sarebbe troppo lento per una risposta HTTP, anche con cache).
     *
     * @return list<array{layer_id: int, name: string, total: int}>
     */
    public function getAllLayersUserPresence(string $range = 'last_30_days'): array
    {
        $cacheKey = "posthog:userMoved:all:presence:ranking:{$range}";

        $perLayer = $this->rememberWithLock($cacheKey, $range, function () use ($range) {
            $points = $this->queryAllUserMovedPointsNearAnyTrack($range);

            if (empty($points)) {
                return [];
            }

            return $this->countPersonsPerLayerNearTracks($points);
        });

        if (empty($perLayer)) {
            return [];
        }

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

    /**
     * Conteggio utenti unici che hanno effettivamente percorso il layer (match GPS↔traccia via
     * PostGIS, non semplice interesse in-app su properties.layer_id). Ritorna null su fallimento/
     * timeout PostHog — mai un'eccezione: il chiamante (AnalyticsController) deve poter mostrare
     * le altre metriche della card anche se questa singola query bulk più pesante fallisce.
     */
    public function getUserMovedStats(Layer $layer, string $range = 'last_30_days'): ?int
    {
        $cacheKey = "posthog:userMoved:{$layer->id}:presence:{$range}";

        try {
            return $this->rememberWithLock($cacheKey, $range, function () use ($layer, $range) {
                $points = $this->queryUserMovedPointsNearLayer($layer, $range);

                if (empty($points)) {
                    return 0;
                }

                return $this->countPersonsNearLayerTracks($layer, $points);
            });
        } catch (\Throwable $e) {
            // \Throwable, non solo AnalyticsQueryException|LockTimeoutException: la query bulk
            // PostGIS (countPersonsNearLayerTracks(), DB::selectOne/DB::select raw) può lanciare
            // \Illuminate\Database\QueryException su un errore Postgres transitorio — non
            // catturato dalle due eccezioni originarie (AnalyticsQueryException estende Exception
            // piatta, nessuna relazione con QueryException). Senza questo catch ampio, un errore DB
            // qui farebbe fallire l'intera risposta di AnalyticsController::layer(), esattamente il
            // comportamento che questo metodo promette di evitare (vedi docblock sopra).
            Log::error('getUserMovedStats() failed', [
                'layer_id' => $layer->id,
                'range' => $range,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Posizione più recente di ogni persona nella finestra `$minutesWindow` ("dove è questa
     * persona ADESSO", non uno storico) — `argMax(..., timestamp)` è un fix GPS realmente
     * accaduto, non una media che su un tornante potrebbe cadere fuori dal sentiero anche se ogni
     * punto reale era sul sentiero. Pre-filtrata solo per bounding box: la prossimità reale alle
     * EcTrack è responsabilità del chiamante (filterPointsNearLayerTracks()), non di questo metodo.
     *
     * @return list<array{person_id: string, lat: float, lng: float, user_id: ?int}>
     */
    private function queryRecentUserMovedPoints(Layer $layer, array $bbox, int $minutesWindow): array
    {
        $limit = self::MAX_RECENT_POSITIONS;
        $temporalClause = "timestamp >= now() - INTERVAL {$minutesWindow} MINUTE";
        $rows = $this->fetchUserMovedPointsRows($bbox, $temporalClause, $limit, aggregatePerPerson: true, includeUserId: true);

        if (count($rows) >= $limit) {
            Log::warning('getRecentUserPositions() hit the safety cap — some live positions may be missing from the map', [
                'layer_id' => $layer->id,
                'limit' => $limit,
            ]);
        }

        return $this->mapUserMovedRowsWithUserId($rows);
    }

    /**
     * Posizioni GPS recenti (finestra `$minutesWindow`, default 30 min — "dove è questa persona
     * ADESSO", non uno storico) per la mappa live del layer. Per ogni persona prendo la sua
     * posizione più recente nella finestra (`argMax(..., timestamp)`, un fix GPS realmente
     * accaduto — non una media, che su un tornante potrebbe cadere fuori dal sentiero anche se
     * ogni punto reale era sul sentiero). Pre-filtrata per bounding box del layer (stesso motivo
     * di queryUserMovedPointsNearLayer()) e poi per prossimità reale alle EcTrack via
     * filterPointsNearLayerTracks() — senza quest'ultimo passo i punti sarebbero solo "vicini
     * all'area del layer", non "vicini al sentiero" (bbox è un rettangolo, non la traccia).
     * `user_id` (nullable) è l'id applicativo dello user, non l'id anonimo PostHog `person_id`
     * (quest'ultimo è solo una chiave di join interna, scartata prima del return) — usato dal
     * chiamante (Layer::getFeatureCollectionMap()) per mostrare nominativo e link invece del
     * marker anonimo di default, quando disponibile.
     *
     * @return list<array{lat: float, lng: float, user_id: ?int}>
     */
    public function getRecentUserPositions(Layer $layer, int $minutesWindow = 30): array
    {
        // $minutesWindow nella cache key: senza, una futura chiamata con finestra non-default per
        // lo stesso layer dentro i 90s di TTL leggerebbe/scriverebbe la stessa entry di una chiamata
        // a 30 minuti, restituendo posizioni calcolate per la finestra sbagliata (bug latente,
        // oggi inerte perché l'unico chiamante usa sempre il default).
        $cacheKey = "posthog:userMoved:{$layer->id}:recent_positions:{$minutesWindow}";

        try {
            return Cache::remember($cacheKey, now()->addSeconds(90), function () use ($layer, $minutesWindow) {
                $bbox = $this->layerTracksBoundingBox($layer);

                if ($bbox === null) {
                    return [];
                }

                $points = $this->queryRecentUserMovedPoints($layer, $bbox, $minutesWindow);
                $matched = $this->filterPointsNearLayerTracks($layer, $points);

                $userIdByPersonId = array_column($points, 'user_id', 'person_id');

                return array_map(fn ($point) => [
                    'lat' => $point['lat'],
                    'lng' => $point['lng'],
                    'user_id' => $userIdByPersonId[$point['person_id']] ?? null,
                ], $matched);
            });
        } catch (\Throwable $e) {
            // \Throwable, non solo AnalyticsQueryException: le query bulk PostGIS invocate qui
            // (layerTracksBoundingBox(), filterPointsNearLayerTracks()) possono lanciare
            // \Illuminate\Database\QueryException su un errore Postgres transitorio — senza questo
            // catch ampio, un errore DB qui farebbe fallire l'intero getFeatureCollectionMap() del
            // layer (tracce, POI e taxonomy_wheres compresi), non solo i marker live.
            Log::error('getRecentUserPositions() failed', ['layer_id' => $layer->id, 'error' => $e->getMessage()]);

            return [];
        }
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
     * @param  \Closure(): ?string|null  $extraFilter  clausola WHERE aggiuntiva, valutata pigramente
     *                                                 solo su cache miss (evita di ricalcolarla se il risultato è già in cache) — usata da
     *                                                 getGlobalUsage() per escludere layer cancellati dal totale aggregato (vedi validLayerIdsClause).
     *                                                 null (default) per il path per-layer: nessuna clausola extra, SQL identica a prima.
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
     *
     * @param  \Closure(): T  $callback
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

            $clause = "(timestamp >= '{$start}' AND timestamp < '{$end}')";
        } else {
            $days = match ($range) {
                'last_90_days' => 90,
                'last_365_days' => 365,
                default => 30,
            };

            $clause = "(timestamp >= now() - INTERVAL {$days} DAY)";
        }

        return $clause.$this->shardNameClause();
    }

    /**
     * Filtra gli eventi per shard_name (config('wm-package.shard_name')) per evitare che eventi
     * di altri consumer wm-package sullo stesso progetto PostHog condiviso si mescolino nelle
     * metriche. La property ha due forme osservate empiricamente su dati reali di produzione:
     * annidata (properties.shard_name._value, formato attuale eventi mobile camminiditalia) e
     * flat (properties.shard_name, osservato su eventi storici di altri shard) — l'OR copre
     * entrambe. Config vuoto disabilita il filtro (fail-open, comportamento pre-fix) invece di
     * produrre una clausola che non fa mai match e azzererebbe silenziosamente ogni metrica.
     */
    private function shardNameClause(): string
    {
        $shardName = trim((string) config('wm-package.shard_name'));

        if ($shardName === '') {
            Log::warning('AnalyticsService: wm-package.shard_name non configurato, filtro shard disabilitato');

            return '';
        }

        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $shardName);

        return " AND (properties.shard_name._value = '{$escaped}' OR properties.shard_name = '{$escaped}')";
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
     * Bounding box (con margine) delle EcTrack del layer, in gradi — usato per restringere la
     * query HogQL ai soli punti geograficamente plausibili, PRIMA di portarli via da PostHog.
     * Senza questo pre-filtro, per rispondere correttamente a "questa persona ha mai toccato la
     * traccia?" servirebbe testare ogni singolo punto GPS grezzo di *tutti* gli utenti del mondo
     * (volume enorme) oppure aggregare nel tempo (media/ultimo punto per bucket) — ma qualunque
     * aggregazione geometrica introduce punti "sintetici" che possono cadere fuori dal sentiero
     * anche quando ogni punto reale era sul sentiero (tipicamente sui tornanti): scoperto in
     * verifica manuale reale (oc:8159), non solo un rischio teorico. Filtrando per area PRIMA
     * di interrogare PostHog, posso invece testare ogni punto individualmente senza esplodere il
     * volume, perché il pre-filtro riduce il pool da "tutti gli utenti ovunque" a "chi si trovava
     * fisicamente in quest'area".
     *
     * @return array{min_lat: float, max_lat: float, min_lng: float, max_lng: float}|null null se il layer non ha tracce con geometria
     */
    private function layerTracksBoundingBox(Layer $layer): ?array
    {
        $trackTable = config('wm-package.ec_track_table', 'ec_tracks');
        $trackIds = $layer->ecTracks()->pluck("{$trackTable}.id")->toArray();

        if (empty($trackIds)) {
            return null;
        }

        return $this->tracksBoundingBox($trackIds);
    }

    /**
     * Bounding box (con margine) dell'unione di *tutte* le EcTrack con geometria — usato dal
     * ranking globale "Cammini più frequentati" per restringere la query HogQL all'area
     * complessiva dove esistono cammini, non a un singolo layer. Stesso motivo di
     * layerTracksBoundingBox(): senza pre-filtro geografico, "tutti i punti userMoved dello
     * shard su 365gg" sarebbe un volume ingestibile in una singola query bulk.
     *
     * @return array{min_lat: float, max_lat: float, min_lng: float, max_lng: float}|null
     */
    private function allTracksBoundingBox(): ?array
    {
        return $this->tracksBoundingBox(null);
    }

    /**
     * @param  list<int>|null  $trackIds  null = tutte le tracce con geometria, senza filtro per ID
     * @return array{min_lat: float, max_lat: float, min_lng: float, max_lng: float}|null
     */
    private function tracksBoundingBox(?array $trackIds): ?array
    {
        $trackTable = config('wm-package.ec_track_table', 'ec_tracks');
        $distanceMeters = (int) config('wm-package.layer_user_presence_distance_meters', 50);
        // Margine di sicurezza attorno al bbox: soglia di matching + 200m extra (1 grado di
        // latitudine ~= 111km, approssimazione conservativa valida anche in longitudine alle
        // latitudini italiane) — evita di scartare punti realmente vicini alla traccia ma appena
        // fuori dal bounding box stretto delle sole coordinate della traccia.
        $marginDegrees = ($distanceMeters + 200) / 111000;
        $bindings = [$marginDegrees, $marginDegrees, $marginDegrees, $marginDegrees];

        $idFilter = 'TRUE';
        if ($trackIds !== null) {
            if (empty($trackIds)) {
                return null;
            }
            $placeholders = implode(', ', array_fill(0, count($trackIds), '?'));
            $idFilter = "id IN ({$placeholders})";
            $bindings = array_merge($bindings, $trackIds);
        }

        /** @var object{min_lat: ?float, max_lat: ?float, min_lng: ?float, max_lng: ?float}|null $row */
        $row = DB::selectOne("
            SELECT
                ST_YMin(ST_Extent(geometry::geometry)) - ? AS min_lat,
                ST_YMax(ST_Extent(geometry::geometry)) + ? AS max_lat,
                ST_XMin(ST_Extent(geometry::geometry)) - ? AS min_lng,
                ST_XMax(ST_Extent(geometry::geometry)) + ? AS max_lng
            FROM {$trackTable}
            WHERE {$idFilter} AND geometry IS NOT NULL
        ", $bindings);

        if (! $row || $row->min_lat === null) {
            return null;
        }

        return [
            'min_lat' => (float) $row->min_lat,
            'max_lat' => (float) $row->max_lat,
            'min_lng' => (float) $row->min_lng,
            'max_lng' => (float) $row->max_lng,
        ];
    }

    /**
     * Clausola SQL per il filtro bbox in HogQL. Usa `>=`/`<=` incatenati, non `BETWEEN`: `BETWEEN`
     * su un'espressione calcolata (`toFloatOrDefault(...)`, non una colonna semplice) fa fallire
     * la query con un HTTP 500 lato PostHog — bug/limite del backend HogQL confermato empiricamente
     * (oc:8159): stessa condizione logica, ma `BETWEEN` rompe, la catena di comparazioni no.
     *
     * @param  array{min_lat: float, max_lat: float, min_lng: float, max_lng: float}  $bbox
     */
    private function bboxFilterClause(array $bbox): string
    {
        return "toFloatOrDefault(properties.user_location.latitude, 0.0) >= {$bbox['min_lat']}
  AND toFloatOrDefault(properties.user_location.latitude, 0.0) <= {$bbox['max_lat']}
  AND toFloatOrDefault(properties.user_location.longitude, 0.0) >= {$bbox['min_lng']}
  AND toFloatOrDefault(properties.user_location.longitude, 0.0) <= {$bbox['max_lng']}";
    }

    /**
     * Esegue il fetch HogQL condiviso dai 3 varianti di query userMoved (KPI per-layer, ranking
     * globale, mappa live): stesso FROM/WHERE su shard+bbox+user_location, differiscono solo per
     * la clausola temporale, il limit e se aggregare per persona (argMax sull'ultimo fix GPS,
     * per "dov'è ADESSO") o restituire ogni punto grezzo (per "è mai passato di qui"). Nessuna
     * aggregazione qui significa niente media lat/lng — un punto sintetico su un tornante
     * potrebbe cadere fuori dal sentiero anche se ogni fix reale era sul sentiero.
     * `properties.shard_name._value` (non piatto) e `toFloatOrDefault()` (non `toFloat64OrNull`,
     * inesistente in HogQL) verificati contro dati reali PostHog (oc:8159 Task 0).
     *
     * `$includeUserId` aggiunge `user_id` (id applicativo dello user che ha registrato il punto,
     * proprietà nuova sull'evento — assente su ogni dato storico e non garantita su ogni evento
     * futuro) come quarta colonna, usata solo dalla mappa live per rendere il marker cliccabile
     * verso la pagina Nova dello user. Le altre 2 query (KPI, ranking globale) non la richiedono.
     *
     * @return list<list<mixed>> righe raw da runQuery(), non ancora mappate a person_id/lat/lng
     */
    private function fetchUserMovedPointsRows(array $bbox, string $temporalClause, int $limit, bool $aggregatePerPerson, bool $includeUserId = false): array
    {
        $shardName = (string) config('wm-package.shard_name');
        $bboxClause = $this->bboxFilterClause($bbox);

        $selectExpr = $aggregatePerPerson
            ? "argMax(toFloatOrDefault(properties.user_location.latitude, 0.0), timestamp) AS lat,\n    argMax(toFloatOrDefault(properties.user_location.longitude, 0.0), timestamp) AS lng"
            : "toFloatOrDefault(properties.user_location.latitude, 0.0) AS lat,\n    toFloatOrDefault(properties.user_location.longitude, 0.0) AS lng";

        if ($includeUserId) {
            $selectExpr .= $aggregatePerPerson
                ? ",\n    argMax(toInt64OrNull(properties.user_id), timestamp) AS user_id"
                : ",\n    toInt64OrNull(properties.user_id) AS user_id";
        }

        $groupBy = $aggregatePerPerson ? "\nGROUP BY person_id" : '';

        $sql = <<<SQL
SELECT
    person_id,
    {$selectExpr}
FROM events
WHERE event = 'userMoved'
  AND properties.shard_name._value = '{$shardName}'
  AND properties.user_location IS NOT NULL
  AND {$bboxClause}
  AND {$temporalClause}{$groupBy}
LIMIT {$limit}
SQL;

        return $this->runQuery($sql, true, self::USER_PRESENCE_TIMEOUT_SECONDS);
    }

    /**
     * Mappa le righe raw di fetchUserMovedPointsRows() nella shape person_id/lat/lng condivisa
     * dai 3 varianti di query userMoved.
     *
     * @param  list<list<mixed>>  $rows
     * @return list<array{person_id: string, lat: float, lng: float}>
     */
    private function mapUserMovedRows(array $rows): array
    {
        return array_map(fn ($row) => [
            'person_id' => (string) $row[0],
            'lat' => (float) $row[1],
            'lng' => (float) $row[2],
        ], $rows);
    }

    /**
     * Come mapUserMovedRows(), con la quarta colonna `user_id` (nullable — assente se lo user non
     * era autenticato o se l'evento non porta ancora questa property).
     *
     * @param  list<list<mixed>>  $rows
     * @return list<array{person_id: string, lat: float, lng: float, user_id: ?int}>
     */
    private function mapUserMovedRowsWithUserId(array $rows): array
    {
        return array_map(fn ($row) => [
            'person_id' => (string) $row[0],
            'lat' => (float) $row[1],
            'lng' => (float) $row[2],
            'user_id' => isset($row[3]) ? (int) $row[3] : null,
        ], $rows);
    }

    /**
     * Punti GPS grezzi dell'evento userMoved, pre-filtrati per bounding box geografico del layer
     * (nessuna aggregazione temporale/spaziale) — risponde a "questa persona ha mai avuto un punto
     * vicino alla traccia nel range?", non "qual è la sua posizione media/più recente".
     *
     * @return list<array{person_id: string, lat: float, lng: float}>
     */
    private function queryUserMovedPointsNearLayer(Layer $layer, string $range): array
    {
        $bbox = $this->layerTracksBoundingBox($layer);

        if ($bbox === null) {
            return [];
        }

        $limit = self::MAX_USER_PRESENCE_POINTS;
        $rows = $this->fetchUserMovedPointsRows($bbox, $this->whereClause($range), $limit, aggregatePerPerson: false);

        if (count($rows) >= $limit) {
            Log::warning('queryUserMovedPointsNearLayer() hit the safety cap — presence count may be underestimated', [
                'layer_id' => $layer->id,
                'range' => $range,
                'limit' => $limit,
            ]);
        }

        return $this->mapUserMovedRows($rows);
    }

    /**
     * Come queryUserMovedPointsNearLayer(), ma con il bbox dell'unione di tutte le tracce (non di
     * un singolo layer) — usato dal ranking globale getAllLayersUserPresence().
     *
     * @return list<array{person_id: string, lat: float, lng: float}>
     */
    private function queryAllUserMovedPointsNearAnyTrack(string $range): array
    {
        $bbox = $this->allTracksBoundingBox();

        if ($bbox === null) {
            return [];
        }

        $limit = self::MAX_GLOBAL_USER_PRESENCE_POINTS;
        $rows = $this->fetchUserMovedPointsRows($bbox, $this->whereClause($range), $limit, aggregatePerPerson: false);

        if (count($rows) >= $limit) {
            Log::warning('queryAllUserMovedPointsNearAnyTrack() hit the safety cap — global ranking may be underestimated', [
                'range' => $range,
                'limit' => $limit,
            ]);
        }

        return $this->mapUserMovedRows($rows);
    }

    /**
     * Blocco `VALUES (...)` + bindings per il set di punti GPS person_id/lat/lng — condiviso dalle
     * 3 query bulk PostGIS sottostanti (countPersonsNearLayerTracks(), countPersonsPerLayerNearTracks(),
     * filterPointsNearLayerTracks()), identico nelle tre salvo il numero di punti.
     *
     * @param  list<array{person_id: string, lat: float, lng: float}>  $points
     * @return array{0: string, 1: list<mixed>}
     */
    private function pointsValuesClause(array $points): array
    {
        $valuesSql = implode(', ', array_fill(0, count($points), '(?, ?::float8, ?::float8)'));

        $bindings = [];
        foreach ($points as $point) {
            $bindings[] = $point['person_id'];
            $bindings[] = $point['lat'];
            $bindings[] = $point['lng'];
        }

        return [$valuesSql, $bindings];
    }

    /**
     * Conta le persone il cui punto GPS (da queryUserMovedPointsNearLayer()) cade entro
     * layer_user_presence_distance_meters da almeno una EcTrack del layer. Match bulk in
     * un'unica query (VALUES + EXISTS/ST_DWithin), non un round-trip per punto — stessa nozione
     * geografica di UgcService::resolveLayerByProximity(), ma qui su un intero set di punti.
     *
     * @param  list<array{person_id: string, lat: float, lng: float}>  $points
     */
    private function countPersonsNearLayerTracks(Layer $layer, array $points): int
    {
        if (empty($points)) {
            return 0;
        }

        $trackTable = config('wm-package.ec_track_table', 'ec_tracks');
        $trackIds = $layer->ecTracks()->pluck("{$trackTable}.id")->toArray();

        if (empty($trackIds)) {
            return 0;
        }

        [$valuesSql, $bindings] = $this->pointsValuesClause($points);
        $trackPlaceholders = implode(', ', array_fill(0, count($trackIds), '?'));
        $distanceMeters = (int) config('wm-package.layer_user_presence_distance_meters', 50);

        $sql = <<<SQL
SELECT count(DISTINCT v.person_id) AS total
FROM (VALUES {$valuesSql}) AS v(person_id, lat, lng)
WHERE EXISTS (
    SELECT 1 FROM {$trackTable}
    WHERE id IN ({$trackPlaceholders})
      AND geometry IS NOT NULL
      AND ST_DWithin(
          geometry::geography,
          ST_SetSRID(ST_MakePoint(v.lng, v.lat), 4326)::geography,
          ?
      )
)
SQL;

        $bindings = array_merge($bindings, $trackIds, [$distanceMeters]);

        /** @var object{total: int}|null $row */
        $row = DB::selectOne($sql, $bindings);

        return (int) ($row->total ?? 0);
    }

    /**
     * Come countPersonsNearLayerTracks(), ma per *tutti* i layer in un'unica query — usato dal
     * ranking globale getAllLayersUserPresence(). Nessun filtro `id IN (...)` sulle tracce: ogni
     * punto viene confrontato con tutte le EcTrack con geometria, e il layer si risolve via la
     * pivot `layerables` (join, non subquery EXISTS — qui serve sapere *quale* layer, non solo
     * se un match esiste). Il morph-type per `layerables.layerable_type` va risolto dinamicamente
     * (config + Relation::morphMap(), stesso pattern di EcPoiEcTrack::poiStillLinkedToLayerViaOtherTrack())
     * — non hardcoded: `wm-package` è multi-consumer (maphub, osm2cai2, ecc.), un valore letterale
     * funziona solo per progetti dove `ec_track_model` risolve esattamente a quella stringa.
     *
     * @param  list<array{person_id: string, lat: float, lng: float}>  $points
     * @return list<array{layer_id: int, total: int}>
     */
    private function countPersonsPerLayerNearTracks(array $points): array
    {
        if (empty($points)) {
            return [];
        }

        $trackTable = config('wm-package.ec_track_table', 'ec_tracks');
        $distanceMeters = (int) config('wm-package.layer_user_presence_distance_meters', 50);
        $ecTrackModelClass = config('wm-package.ec_track_model', EcTrack::class);
        $ecTrackMorphType = array_search($ecTrackModelClass, Relation::morphMap()) ?: $ecTrackModelClass;
        [$valuesSql, $bindings] = $this->pointsValuesClause($points);

        $sql = <<<SQL
SELECT lb.layer_id AS layer_id, count(DISTINCT v.person_id) AS total
FROM (VALUES {$valuesSql}) AS v(person_id, lat, lng)
JOIN {$trackTable} et ON et.geometry IS NOT NULL
  AND ST_DWithin(
      et.geometry::geography,
      ST_SetSRID(ST_MakePoint(v.lng, v.lat), 4326)::geography,
      ?
  )
JOIN layerables lb ON lb.layerable_id = et.id AND lb.layerable_type = ?
GROUP BY lb.layer_id
SQL;

        $bindings[] = $distanceMeters;
        $bindings[] = $ecTrackMorphType;

        $rows = DB::select($sql, $bindings);

        return array_map(fn ($row) => [
            'layer_id' => (int) $row->layer_id,
            'total' => (int) $row->total,
        ], $rows);
    }

    /**
     * Come countPersonsNearLayerTracks(), ma ritorna i punti che superano il match invece del
     * solo conteggio — usato da getRecentUserPositions() per mostrare sulla mappa solo posizioni
     * realmente vicine alle EcTrack del layer, non a livello di shard.
     *
     * @param  list<array{person_id: string, lat: float, lng: float}>  $points
     * @return list<array{person_id: string, lat: float, lng: float}>
     */
    private function filterPointsNearLayerTracks(Layer $layer, array $points): array
    {
        if (empty($points)) {
            return [];
        }

        $trackTable = config('wm-package.ec_track_table', 'ec_tracks');
        $trackIds = $layer->ecTracks()->pluck("{$trackTable}.id")->toArray();

        if (empty($trackIds)) {
            return [];
        }

        [$valuesSql, $bindings] = $this->pointsValuesClause($points);
        $trackPlaceholders = implode(', ', array_fill(0, count($trackIds), '?'));
        $distanceMeters = (int) config('wm-package.layer_user_presence_distance_meters', 50);

        $sql = <<<SQL
SELECT DISTINCT v.person_id, v.lat, v.lng
FROM (VALUES {$valuesSql}) AS v(person_id, lat, lng)
WHERE EXISTS (
    SELECT 1 FROM {$trackTable}
    WHERE id IN ({$trackPlaceholders})
      AND geometry IS NOT NULL
      AND ST_DWithin(
          geometry::geography,
          ST_SetSRID(ST_MakePoint(v.lng, v.lat), 4326)::geography,
          ?
      )
)
SQL;

        $bindings = array_merge($bindings, $trackIds, [$distanceMeters]);

        $rows = DB::select($sql, $bindings);

        return array_map(fn ($row) => [
            'person_id' => (string) $row->person_id,
            'lat' => (float) $row->lat,
            'lng' => (float) $row->lng,
        ], $rows);
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
        $effectiveLayerId = $this->effectiveLayerIdExpression();

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
    {$effectiveLayerId} AS layer_id,
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
        $expr = $idProperty === 'layer_id' ? $this->effectiveLayerIdExpression() : "properties.{$idProperty}";

        if ($id === null) {
            return "{$expr} IS NOT NULL AND {$expr} != ''";
        }

        return "{$expr} = '{$id}'";
    }

    /**
     * La maggior parte degli eventi layerOpened reali non porta più `layer_id` (verificato:
     * solo 12%/2%/23% degli eventi Android/iOS/web negli ultimi 30 giorni) — porta invece
     * `layer_label`, formato "{id} - {titolo}" (es. "56 - Cammino Minerario di Santa Barbara"),
     * verificato 100% consistente su 90 giorni di dati reali e mai in contraddizione con
     * layer_id quando entrambe le property sono presenti sullo stesso evento. Usa layer_id se
     * presente, altrimenti estrae l'ID numerico da layer_label.
     */
    private function effectiveLayerIdExpression(): string
    {
        return "coalesce(nullIf(properties.layer_id, ''), nullIf(extract(properties.layer_label, '^([0-9]+)'), ''))";
    }

    private function idInFilterClause(string $idProperty, ?array $ids): string
    {
        $expr = $idProperty === 'layer_id' ? $this->effectiveLayerIdExpression() : "properties.{$idProperty}";

        if ($ids === null) {
            return "{$expr} IS NOT NULL AND {$expr} != ''";
        }

        $inList = implode(', ', array_map(fn ($v) => "'{$v}'", $ids));

        return "{$expr} IN ({$inList})";
    }

    private function libList(): string
    {
        return implode(', ', array_map(fn ($l) => "'{$l}'", self::LIBS));
    }

    /** @return list<list<mixed>> */
    private function runQuery(string $sql, bool $strict = false, int $timeoutSeconds = 10): array
    {
        $url = "{$this->host}/api/projects/{$this->projectId}/query";

        $response = Http::withToken($this->apiKey)
            ->timeout($timeoutSeconds)
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
