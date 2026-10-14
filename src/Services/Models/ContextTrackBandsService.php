<?php

namespace Wm\WmPackage\Services\Models;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Services\BaseService;

/**
 * Tratti dei cammini sulla mappa della UGC track vista da lontano (oc:8776): dove più cammini
 * passano sullo stesso sentiero, la mappa disegna una linea sola a segmenti alternati nei colori
 * di quei cammini.
 *
 * Le tappe (EcTrack) si tagliano in pezzi di al più max_distance_meters; per ogni pezzo si
 * contano i cammini con una tappa entro max_distance_meters, la stessa tolleranza della
 * corrispondenza di oc:8773. Ne escono, per ogni tappa, tratti con `cammini` (le chiavi dei
 * cammini in quel punto, in ordine), `n` (quanti sono) e `rank` (posizione del cammino della
 * tappa fra quelli: chi disegna il tratto condiviso usa quello in posizione 0).
 * Due tappe dello stesso cammino non si contano due volte.
 *
 * Ogni tratto esce orientato da ovest a est (da sud a nord se è più verticale che orizzontale),
 * così le tappe disegnate in versi opposti hanno lo stesso verso anche sulla mappa.
 *
 * Il risultato è in cache: la chiave cambia da sola quando cambia una delle tappe (updated_at),
 * l'insieme delle tappe o la tolleranza.
 */
class ContextTrackBandsService extends BaseService
{
    private const CACHE_TTL_DAYS = 30;

    /**
     * @param  array<int, int>  $camminoByEcTrack  id EcTrack => chiave del cammino (ordina le fasce)
     * @param  int|null  $nearUgcTrackId  se c'è, solo i pezzi di tappa entro la tolleranza da quella
     *                                    UgcTrack: le porzioni che la traccia tocca davvero
     * @return list<array{ec_track_id: int, cammini: list<int>, rank: int, n: int, geometry: array}>|null geometry è una
     *                                                                                                    MultiLineString; null = calcolo non riuscito
     */
    public function bands(array $camminoByEcTrack, int $maxDistanceMeters, ?int $nearUgcTrackId = null): ?array
    {
        if ($camminoByEcTrack === []) {
            return [];
        }
        ksort($camminoByEcTrack);
        $key = sprintf(
            'context-track-bands:v4:%s:%d:%s:%s',
            md5(json_encode($camminoByEcTrack)),
            $maxDistanceMeters,
            (string) DB::table('ec_tracks')->whereIn('id', array_keys($camminoByEcTrack))->max('updated_at'),
            $nearUgcTrackId === null ? '-' : $nearUgcTrackId.'@'.DB::table('ugc_tracks')->where('id', $nearUgcTrackId)->value('updated_at')
        );

        try {
            return Cache::remember($key, now()->addDays(self::CACHE_TTL_DAYS), fn () => $this->compute($camminoByEcTrack, $maxDistanceMeters, $nearUgcTrackId));
        } catch (\Throwable $e) {
            // Un errore non deve togliere la mappa: chi chiama disegna le tappe come da vicino.
            Log::warning('ContextTrackBandsService: calcolo non riuscito', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Pezzi in una tabella temporanea con indice GiST: senza indice il confronto fra tutti i pezzi
     * (decine di migliaia su una UGC lunga) è quadratico. Sulla UGC 321 di camminiditalia,
     * 29 tappe: ~35.000 pezzi, ~1,6 s.
     *
     * @param  array<int, int>  $camminoByEcTrack
     * @return list<array{ec_track_id: int, cammini: list<int>, rank: int, n: int, geometry: array}>
     */
    private function compute(array $camminoByEcTrack, int $maxDistanceMeters, ?int $nearUgcTrackId): array
    {
        if ($maxDistanceMeters <= 0) {
            throw new \InvalidArgumentException("max_distance_meters non valido: {$maxDistanceMeters}");
        }

        $rows = DB::transaction(function () use ($camminoByEcTrack, $maxDistanceMeters, $nearUgcTrackId) {
            // Stesso motivo di UgcTrackMatchingService::disableJit(): il JIT costa più del calcolo.
            DB::statement('SET LOCAL jit = off');
            DB::statement(<<<'SQL'
                CREATE TEMP TABLE context_track_band_pieces ON COMMIT DROP AS
                WITH x AS (
                    SELECT unnest(CAST(:ids AS int[])) AS id, unnest(CAST(:cammini AS int[])) AS c
                ),
                parts AS (
                    SELECT ec.id, x.c, d.path[1] AS p, d.geom AS l
                    FROM ec_tracks ec
                    JOIN x ON x.id = ec.id,
                    LATERAL ST_Dump(ST_Force2D(ec.geometry::geometry)) AS d
                    WHERE ST_Length(d.geom::geography) > 0
                )
                SELECT parts.id, parts.c, parts.p::bigint * 1000000 + s.path[1] AS i, s.geom AS s
                FROM parts,
                LATERAL ST_DumpSegments(ST_Segmentize(parts.l::geography, :max_distance)::geometry) AS s
                SQL, [
                'ids' => '{'.implode(',', array_keys($camminoByEcTrack)).'}',
                'cammini' => '{'.implode(',', array_values($camminoByEcTrack)).'}',
                'max_distance' => $maxDistanceMeters,
            ]);
            DB::statement('CREATE INDEX ON context_track_band_pieces USING gist (s)');
            DB::statement('ANALYZE context_track_band_pieces');

            if ($nearUgcTrackId !== null) {
                // Pezzi della traccia, con indice, e via i pezzi di tappa lontani da tutti.
                DB::statement(<<<'SQL'
                    CREATE TEMP TABLE context_track_band_ugc ON COMMIT DROP AS
                    SELECT s.geom AS s
                    FROM ugc_tracks u,
                    LATERAL ST_Dump(ST_Force2D(u.geometry::geometry)) AS d,
                    LATERAL ST_DumpSegments(ST_Segmentize(d.geom::geography, :max_distance)::geometry) AS s
                    WHERE u.id = :ugc
                    SQL, ['ugc' => $nearUgcTrackId, 'max_distance' => $maxDistanceMeters]);
                DB::statement('CREATE INDEX ON context_track_band_ugc USING gist (s)');
                DB::statement('ANALYZE context_track_band_ugc');
                DB::statement(<<<'SQL'
                    DELETE FROM context_track_band_pieces a
                    WHERE NOT EXISTS (
                        SELECT 1 FROM context_track_band_ugc u
                        WHERE u.s && ST_Expand(a.s, :expand)
                          AND ST_DWithin(a.s::geography, u.s::geography, :max_distance)
                    )
                    SQL, ['expand' => $maxDistanceMeters / 40000, 'max_distance' => $maxDistanceMeters]);
            }

            // Il filtro && in gradi deve contenere max_distance_meters anche in longitudine:
            // 1° di longitudine vale 111 km × cos(lat), sopra i 60° meno di 55 km, da cui il /40000.
            return DB::select(<<<'SQL'
                WITH tagged AS (
                    SELECT a.id, a.c, a.i, a.s,
                           (SELECT array_agg(DISTINCT b.c ORDER BY b.c)
                            FROM context_track_band_pieces b
                            WHERE b.s && ST_Expand(a.s, :expand)
                              AND (b.c = a.c OR ST_DWithin(a.s::geography, b.s::geography, :max_distance))) AS cammini
                    FROM context_track_band_pieces a
                ),
                merged AS (
                    SELECT id,
                           cammini,
                           array_position(cammini, c) - 1 AS rank,
                           array_length(cammini, 1) AS n,
                           ST_LineMerge(ST_Collect(s ORDER BY i)) AS g
                    FROM tagged
                    GROUP BY id, cammini, rank, n
                ),
                parts AS (
                    SELECT m.id, m.cammini, m.rank, m.n, d.path[1] AS k, d.geom AS g,
                           ST_X(ST_EndPoint(d.geom)) - ST_X(ST_StartPoint(d.geom)) AS dx,
                           ST_Y(ST_EndPoint(d.geom)) - ST_Y(ST_StartPoint(d.geom)) AS dy
                    FROM merged m, LATERAL ST_Dump(m.g) AS d
                )
                SELECT id, array_to_json(cammini) AS cammini, rank, n,
                       ST_AsGeoJSON(ST_Multi(ST_Collect(
                           CASE WHEN (abs(dx) >= abs(dy) AND dx < 0) OR (abs(dx) < abs(dy) AND dy < 0)
                                THEN ST_Reverse(g) ELSE g END
                           ORDER BY k)), 6) AS geojson
                FROM parts
                GROUP BY id, cammini, rank, n
                ORDER BY id, rank, n
                SQL, [
                'expand' => $maxDistanceMeters / 40000,
                'max_distance' => $maxDistanceMeters,
            ]);
        });

        return array_map(fn ($row) => [
            'ec_track_id' => (int) $row->id,
            'cammini' => array_map('intval', json_decode($row->cammini, true)),
            'rank' => (int) $row->rank,
            'n' => (int) $row->n,
            'geometry' => json_decode($row->geojson, true),
        ], $rows);
    }
}
