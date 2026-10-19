<?php

namespace Wm\WmPackage\Services\Models;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\UgcTrackMatchingRun;
use Wm\WmPackage\Services\BaseService;

/**
 * Corrispondenza (matching) fra UgcTrack ed EcTrack per segmenti (oc:8773).
 *
 * Ogni EcTrack è divisa in segmenti di lunghezza fissa misurati su geography, numerati da 0
 * dall'inizio della geometria e in modo unico anche quando la EcTrack è fatta di più pezzi
 * (l'indice continua da un pezzo al successivo, nell'ordine in cui sono salvati). Un segmento
 * corrisponde a una UgcTrack se, preso come linea continua, passa entro la distanza massima dalla
 * traccia (ST_DWithin su geography). È la regola del passaporto di Cammini d'Italia (oc:8165)
 * presa traccia per traccia: l'unione delle tracce di un utente tocca un segmento se e solo se
 * almeno una traccia lo tocca, quindi il consumer ottiene i segmenti percorsi da un utente unendo
 * gli indici.
 */
class UgcTrackMatchingService extends BaseService
{
    public const SEGMENT_LENGTH_KEY = 'ugc_track_matching_segment_length_meters';

    public const MAX_DISTANCE_KEY = 'ugc_track_matching_max_distance_meters';

    public const SEGMENT_LENGTH_MIN = 50;

    public const SEGMENT_LENGTH_MAX = 2000;

    public const MAX_DISTANCE_MIN = 5;

    public const MAX_DISTANCE_MAX = 200;

    /**
     * Vertici per pezzo quando la UgcTrack viene spezzata prima del confronto coi segmenti.
     * Misurato su camminiditalia (UgcTrack 297, 20k vertici): 32, 64, 128 e 256 danno lo stesso
     * risultato in tempi equivalenti (~3 s); 64 è il valore scelto.
     */
    public const CHUNK_VERTICES = 64;

    /** @return array{segment_length_meters:int, max_distance_meters:int} */
    public function params(int $appId): array
    {
        $properties = App::query()->whereKey($appId)->value('properties');
        $properties = is_array($properties) ? $properties : (json_decode((string) $properties, true) ?? []);

        return [
            'segment_length_meters' => $this->numeric($properties[self::SEGMENT_LENGTH_KEY] ?? null, 'segment_length_meters'),
            'max_distance_meters' => $this->numeric($properties[self::MAX_DISTANCE_KEY] ?? null, 'max_distance_meters'),
        ];
    }

    public function ecTrackMorphClass(): string
    {
        $class = config('wm-package.ec_track_model', EcTrack::class);

        return class_exists($class) ? (new $class)->getMorphClass() : $class;
    }

    public function matchUgcTrack(int $ugcTrackId): void
    {
        $track = DB::table('ugc_tracks')->where('id', $ugcTrackId)->first(['id', 'app_id']);
        if (! $track) {
            return;
        }
        $params = $this->params((int) $track->app_id);
        // computed_at = inizio del calcolo: la guardia di markFailed() vale per "un calcolo
        // riuscito PARTITO dopo l'accodamento del job". Un calcolo partito prima può aver letto
        // una geometria vecchia, e il failed() del job successivo deve poterlo segnalare.
        $startedAt = now();

        DB::transaction(function () use ($ugcTrackId, $params, $startedAt) {
            DB::table('ugc_track_ec_track_matches')->where('ugc_track_id', $ugcTrackId)->delete();
            $this->disableJit();

            DB::statement($this->insertSql(
                ugcSource: 'SELECT g.id, g.app_id, g.geometry FROM ugc_tracks g WHERE g.id = :ugc',
                ecSource: 'SELECT e.id, e.geometry FROM '.$this->ecTable().' e, u WHERE e.app_id = u.app_id AND e.geometry && _ST_Expand(u.geometry, :max_distance)',
            ), ['ugc' => $ugcTrackId, 'seg' => $params['segment_length_meters'], 'max_distance' => $params['max_distance_meters']]);

            $this->markComputed($ugcTrackId, $params, $startedAt);
        });
    }

    public function matchEcTrack(int $ecTrackId): void
    {
        $appId = DB::table($this->ecTable())->where('id', $ecTrackId)->value('app_id');
        if ($appId === null) {
            return;
        }
        $params = $this->params((int) $appId);

        DB::transaction(function () use ($ecTrackId, $params) {
            DB::table('ugc_track_ec_track_matches')->where('ec_track_id', $ecTrackId)->delete();
            $this->disableJit();

            DB::statement($this->insertSql(
                ugcSource: 'SELECT g.id, g.app_id, g.geometry FROM ugc_tracks g, '.$this->ecTable().' t'
                    .' WHERE t.id = :ec AND g.app_id = t.app_id AND g.geometry && _ST_Expand(t.geometry, :max_distance)',
                ecSource: 'SELECT e.id, e.geometry FROM '.$this->ecTable().' e WHERE e.id = :ec',
            ), ['ec' => $ecTrackId, 'seg' => $params['segment_length_meters'], 'max_distance' => $params['max_distance_meters']]);
        });
    }

    /**
     * Segna fallito il calcolo di una UgcTrack. Con $notAfter (il momento in cui il job è stato
     * accodato) lo stato failed non sovrascrive un calcolo riuscito dopo quel momento: il caso è
     * un job andato in timeout mentre un altro calcolo, sincrono o di un job successivo, è
     * riuscito (UgcTrack 297 su camminiditalia). Il controllo e la scrittura sono una sola
     * istruzione, così un calcolo che si chiude nel frattempo non viene perso.
     * computed_at è il momento in cui il calcolo è PARTITO (matchUgcTrack), al secondo, e il
     * confronto è stretto: un calcolo partito nello stesso secondo dell'accodamento conta come
     * precedente e failed lo sovrascrive (scelta voluta: lo stato failed si rimedia da solo, al
     * dettaglio successivo o con il command).
     */
    public function markFailed(int $ugcTrackId, string $error, ?\DateTimeInterface $notAfter = null): void
    {
        $guard = $notAfter === null
            ? ''
            : 'WHERE NOT (ugc_track_matching_runs.status = :computed AND ugc_track_matching_runs.computed_at > CAST(:not_after AS timestamp))';
        $bindings = [
            'id' => $ugcTrackId,
            'failed' => UgcTrackMatchingRun::STATUS_FAILED,
            'error' => mb_substr($error, 0, 2000),
            'now' => now(),
        ];
        if ($notAfter !== null) {
            $bindings['computed'] = UgcTrackMatchingRun::STATUS_COMPUTED;
            $bindings['not_after'] = $notAfter->format('Y-m-d H:i:s');
        }

        // La SELECT da ugc_tracks salta le tracce cancellate nel frattempo (FK in cascata).
        DB::statement(<<<SQL
            INSERT INTO ugc_track_matching_runs
                (ugc_track_id, status, segment_length_meters, max_distance_meters, error, computed_at, created_at, updated_at)
            SELECT g.id, CAST(:failed AS text), NULL, NULL, CAST(:error AS text), CAST(:now AS timestamp), CAST(:now AS timestamp), CAST(:now AS timestamp)
            FROM ugc_tracks g WHERE g.id = :id
            ON CONFLICT (ugc_track_id) DO UPDATE SET
                status = EXCLUDED.status,
                segment_length_meters = NULL,
                max_distance_meters = NULL,
                error = EXCLUDED.error,
                computed_at = EXCLUDED.computed_at,
                updated_at = EXCLUDED.updated_at
            {$guard}
        SQL, $bindings);
    }

    /**
     * Numero di segmenti di ciascuna EcTrack con i parametri attuali della App (oc:8773), anche
     * per le EcTrack che nessuna UgcTrack tocca: serve il denominatore del valore aggregato per
     * layer. Stessa SQL di segmentazione di insertSql() (segmentCtes()), quindi per una EcTrack
     * toccata il valore coincide con total_segments. Le EcTrack inesistenti o a lunghezza zero
     * non compaiono nel risultato.
     *
     * @param  array<int, int>  $ecTrackIds
     * @param  array{segment_length_meters:int, max_distance_meters:int}|null  $params  parametri già letti con params($appId)
     * @return array<int, int> ec_track_id => numero di segmenti
     */
    public function totalSegmentsForEcTracks(array $ecTrackIds, int $appId, ?array $params = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $ecTrackIds)));
        if ($ids === []) {
            return [];
        }
        $params ??= $this->params($appId);

        $rows = DB::select(
            'WITH t AS (SELECT e.id, e.geometry FROM '.$this->ecTable().' e WHERE e.id = ANY(CAST(:ids AS bigint[]))), '
                .$this->segmentCtes().' SELECT id, total FROM totals',
            ['ids' => '{'.implode(',', $ids).'}', 'seg' => $params['segment_length_meters']]
        );

        $totals = [];
        foreach ($rows as $row) {
            $totals[(int) $row->id] = (int) $row->total;
        }

        return $totals;
    }

    public function unmatchedUgcTrackIdsQuery(int $appId): Builder
    {
        $params = $this->params($appId);

        return DB::table('ugc_tracks as g')
            ->leftJoin('ugc_track_matching_runs as r', 'r.ugc_track_id', '=', 'g.id')
            ->where('g.app_id', $appId)
            ->where(function ($q) use ($params) {
                $q->whereNull('r.ugc_track_id')
                    ->orWhere('r.status', UgcTrackMatchingRun::STATUS_FAILED)
                    ->orWhere('r.segment_length_meters', '<>', $params['segment_length_meters'])
                    ->orWhere('r.max_distance_meters', '<>', $params['max_distance_meters']);
            })
            ->orderBy('g.id')
            ->select('g.id');
    }

    /**
     * INSERT delle righe di corrispondenza. ugcSource definisce il CTE `u` (UgcTrack candidate),
     * ecSource il CTE `t` (EcTrack candidate, può riferirsi a `u`). Le tracce a lunghezza zero
     * restano fuori senza errori.
     *
     * Prestazioni (oc:8773, UgcTrack 297 da 20k vertici: da 95 s a ~3 s). La distanza geodetica
     * fra due geometrie lunghe costa quanto il prodotto dei loro vertici, quindi:
     * - le candidate (`u`, `t`) passano solo il controllo sul bounding box allargato della distanza
     *   massima (`&& _ST_Expand`, lo stesso che ST_DWithin usa sull'indice): il controllo esatto lo
     *   fanno i segmenti, e una coppia senza segmenti entro la distanza massima non produce righe,
     *   quindi il risultato non cambia;
     * - la UgcTrack è spezzata in pezzi di CHUNK_VERTICES vertici consecutivi (`up`), dove ogni
     *   pezzo ripete l'ultimo vertice del precedente: i lati restano esattamente quelli originali,
     *   senza vertici nuovi (ST_Subdivide invece taglia i lati e ne aggiunge), e un segmento è
     *   entro la distanza massima dalla traccia se e solo se lo è da almeno un pezzo;
     * - `up` e `segs` sono MATERIALIZED, altrimenti Postgres ricalcola ST_LineSubstring per ogni
     *   coppia segmento-pezzo del join;
     * - il box allargato di ogni pezzo (`up.ge`) si calcola una volta sola in `up`, e il join
     *   confronta prima i box (`&&`) e solo per le coppie rimaste chiama ST_DWithin.
     *
     * `_ST_Expand(geography, float8)` è una funzione interna di PostGIS (prefisso `_`): è quella
     * che la support function di ST_DWithin usa per trasformare la chiamata in una condizione
     * sull'indice. Lavora sulla sfera, quindi il box allargato contiene tutti i punti entro
     * `:max_distance` metri anche vicino ai poli e all'antimeridiano.
     * La segmentazione delle EcTrack (parts/lens/counted/totals) è in segmentCtes(), condivisa
     * con totalSegmentsForEcTracks().
     *
     * Tratti dritti lunghi (oc:8165). Dove il GPS non ha registrato, fra due punti consecutivi
     * resta un segmento dritto (oc:8719 sceglie di tenerlo). Un tratto più lungo della lunghezza
     * di segmento non è un percorso: marca solo il primo e l'ultimo segmento che incontra, non
     * quelli in mezzo. Due rami, uniti dall'INSERT:
     * - `grouped` apre un gruppo nuovo a ogni tratto lungo, e `up` raggruppa anche per quello:
     *   il tratto lungo non entra più in nessuna linea, e per tutto il resto pezzi e costo
     *   restano quelli di prima;
     * - `long_pick` tiene i soli segmenti **in cui cade uno dei due capi del tratto**, entro la
     *   distanza massima. Il mezzo del tratto non esiste: della linea dritta si sa solo che agli
     *   estremi la persona c'era. Una tappa attraversata di traverso, lontana da entrambi i capi,
     *   non prende quindi nulla — prima ne prendeva due, i più vicini al punto di attraversamento,
     *   e quel 10% si sommava poi ai segmenti percorsi davvero.
     * Le prestazioni reggono perché i tratti lunghi sono pochissimi (214 su 399.290 nei dati di
     * camminiditalia): il percorso normale resta a pezzi di CHUNK_VERTICES vertici, e andare
     * per singolo lato costerebbe 305 s contro 16,9 s a parità di risultato.
     */
    private function insertSql(string $ugcSource, string $ecSource): string
    {
        $chunk = self::CHUNK_VERTICES;
        $segmentCtes = $this->segmentCtes();

        return <<<SQL
            WITH u AS (
                SELECT x.id, x.app_id, x.geometry FROM ({$ugcSource}) x
                WHERE ST_Length(x.geometry) > 0
            ),
            t AS ({$ecSource}),
            pts AS (
                SELECT u.id, u.app_id, lp.path[1] AS part, pp.path[1] AS k, pp.geom
                FROM u, LATERAL ST_Dump(ST_Force2D(u.geometry::geometry)) lp,
                     LATERAL ST_DumpPoints(lp.geom) pp
            ),
            edges AS (
                SELECT p.id, p.app_id, p.part, p.k, p.geom,
                       LAG(p.geom) OVER (PARTITION BY p.id, p.part ORDER BY p.k) AS prev_geom,
                       ST_Distance(
                           p.geom::geography,
                           LAG(p.geom) OVER (PARTITION BY p.id, p.part ORDER BY p.k)::geography
                       ) AS d
                FROM pts p
            ),
            grouped AS (
                SELECT e.id, e.app_id, e.part, e.k, e.geom,
                       SUM(CASE WHEN e.d > :seg THEN 1 ELSE 0 END) OVER (
                           PARTITION BY e.id, e.part ORDER BY e.k ROWS UNBOUNDED PRECEDING
                       ) AS grp
                FROM edges e
            ),
            long_edges AS (
                SELECT e.id, e.app_id, e.part, e.k,
                       ST_MakeLine(e.prev_geom, e.geom)::geography AS g,
                       e.prev_geom::geography AS a,
                       e.geom::geography AS b
                FROM edges e
                WHERE e.d > :seg
            ),
            up AS MATERIALIZED (
                SELECT x.id, x.app_id, x.g, _ST_Expand(x.g, :max_distance) AS ge FROM (
                    SELECT p.id, p.app_id, ST_MakeLine(p.geom ORDER BY p.k)::geography AS g
                    FROM grouped p
                    CROSS JOIN LATERAL (VALUES
                        ((p.k - 1) / {$chunk}),
                        (CASE WHEN p.k > 1 AND (p.k - 1) % {$chunk} = 0 THEN (p.k - 1) / {$chunk} - 1 END)
                    ) c(chunk)
                    WHERE c.chunk IS NOT NULL
                    GROUP BY p.id, p.app_id, p.part, p.grp, c.chunk
                    HAVING COUNT(*) > 1
                ) x
            ),
            {$segmentCtes},
            segs AS MATERIALIZED (
                SELECT c.id, c.offs + i AS idx,
                       ST_LineSubstring(c.l::geography, i * :seg / c.len, LEAST((i + 1) * :seg / c.len, 1)) AS s
                FROM counted c CROSS JOIN LATERAL generate_series(0, c.n - 1) AS i
            ),
            long_pick AS (
                SELECT DISTINCT le.id AS ugc_id, le.app_id, s.id AS ec_id, s.idx
                FROM long_edges le
                JOIN segs s ON (s.s && _ST_Expand(le.a, :max_distance) AND ST_DWithin(s.s, le.a, :max_distance))
                            OR (s.s && _ST_Expand(le.b, :max_distance) AND ST_DWithin(s.s, le.b, :max_distance))
            ),
            hits AS (
                SELECT up.id AS ugc_id, up.app_id, s.id AS ec_id, s.idx
                FROM segs s
                JOIN up ON s.s && up.ge AND ST_DWithin(s.s, up.g, :max_distance)
                UNION
                SELECT lp.ugc_id, lp.app_id, lp.ec_id, lp.idx FROM long_pick lp
            )
            INSERT INTO ugc_track_ec_track_matches
                (ugc_track_id, ec_track_id, app_id, matched_segments, total_segments, segment_length_meters, max_distance_meters, created_at, updated_at)
            SELECT h.ugc_id, h.ec_id, h.app_id, jsonb_agg(DISTINCT h.idx ORDER BY h.idx), tt.total, :seg, :max_distance, NOW(), NOW()
            FROM hits h
            JOIN totals tt ON tt.id = h.ec_id
            GROUP BY h.ugc_id, h.ec_id, h.app_id, tt.total
            ON CONFLICT (ugc_track_id, ec_track_id) DO UPDATE SET
                app_id = EXCLUDED.app_id,
                matched_segments = EXCLUDED.matched_segments,
                total_segments = EXCLUDED.total_segments,
                segment_length_meters = EXCLUDED.segment_length_meters,
                max_distance_meters = EXCLUDED.max_distance_meters,
                updated_at = NOW()
        SQL;
    }

    /**
     * CTE della segmentazione delle EcTrack, dal CTE `t` (id, geometry) che il chiamante
     * definisce prima: `parts` (pezzi da ST_Dump), `lens` (lunghezza geography, pezzi a lunghezza
     * zero esclusi), `counted` (segmenti del pezzo, CEIL(len / :seg), e indice del primo
     * segmento del pezzo), `totals` (segmenti della EcTrack). Unica definizione per il calcolo
     * delle righe e per i totali delle EcTrack non toccate: le due query non possono divergere.
     *
     * Limite noto, non corretto (oc:8165): i segmenti sono di :seg esatti e l'avanzo finale
     * diventa un segmento a sé, anche di pochi metri. Siccome la copertura si misura
     * **contando** i segmenti e non la loro lunghezza, quell'avanzo pesa quanto un segmento
     * intero — su camminiditalia 153 tappe su 1262 ne hanno uno sotto i 50 m, una di 0,0 m, e
     * su una tappa da cinque segmenti vale il 20%. Chi percorre tutta la tappa tranne l'ultimo
     * metro si ferma all'80%. Si correggerebbe dividendo in parti uguali (`ROUND` invece di
     * `CEIL`), al prezzo di ricalcolare tutte le corrispondenze esistenti.
     */
    private function segmentCtes(): string
    {
        return <<<'SQL'
            parts AS (
                SELECT t.id, d.path[1] AS part, d.geom AS l
                FROM t, LATERAL ST_Dump(ST_Force2D(t.geometry::geometry)) d
            ),
            lens AS (
                SELECT id, part, l, ST_Length(l::geography) AS len FROM parts WHERE ST_Length(l::geography) > 0
            ),
            counted AS (
                SELECT id, part, l, len, CEIL(len / :seg)::int AS n,
                       COALESCE(SUM(CEIL(len / :seg)::int) OVER (
                           PARTITION BY id ORDER BY part ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING
                       ), 0)::int AS offs
                FROM lens
            ),
            totals AS (SELECT id, SUM(n)::int AS total FROM counted GROUP BY id)
        SQL;
    }

    /**
     * Le stime di righe di generate_series e ST_DumpPoints sono molto più alte del vero, e fanno
     * scattare il JIT di Postgres: compilarlo costa ~1,5 s per query, più del calcolo stesso
     * (misurato su camminiditalia). SET LOCAL vale solo fino alla fine della transazione.
     */
    private function disableJit(): void
    {
        DB::statement('SET LOCAL jit = off');
    }

    private function markComputed(int $ugcTrackId, array $params, \DateTimeInterface $startedAt): void
    {
        UgcTrackMatchingRun::query()->updateOrCreate(['ugc_track_id' => $ugcTrackId], [
            'status' => UgcTrackMatchingRun::STATUS_COMPUTED,
            'segment_length_meters' => $params['segment_length_meters'],
            'max_distance_meters' => $params['max_distance_meters'],
            'error' => null,
            'computed_at' => $startedAt,
        ]);
    }

    private function numeric(mixed $value, string $configKey): int
    {
        return is_numeric($value) && (int) $value > 0
            ? (int) $value
            : (int) config("wm-package.ugc_track_matching.{$configKey}");
    }

    private function ecTable(): string
    {
        return config('wm-package.ec_track_table', 'ec_tracks');
    }
}
