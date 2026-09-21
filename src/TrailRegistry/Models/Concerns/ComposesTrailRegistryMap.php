<?php

namespace Wm\WmPackage\TrailRegistry\Models\Concerns;

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;

/**
 * I mattoni comuni alle mappe del catasto: i settori attraversati da una
 * traccia, un settore disegnato, la geometria di una riga.
 *
 * Estratto quando la scheda delle anomalie ha avuto bisogno della stessa
 * mappa della scheda dei codici: le due rispondono a domande diverse — «da
 * dove viene questo prefisso» contro «perché questo sentiero è rimasto senza
 * numero» — ma il modo di disegnare un settore e di leggere una geometria è
 * lo stesso, e tenerne due copie significherebbe correggerne sempre una sola.
 *
 * @internal
 */
trait ComposesTrailRegistryMap
{
    /**
     * I settori che una traccia attraversa, con la percentuale di percorso in
     * ciascuno, dal più attraversato in giù.
     *
     * Il filtro `ST_Intersects` lavora in `geography` **senza cast**, così usa
     * l'indice GiST; il cast a `::geometry` sta solo dentro `ST_Intersection`,
     * che in geography non esiste, e agisce sul sottoinsieme già ristretto.
     * Invertire i due significa passare da un Index Scan a una scansione
     * completa: misurato sui dati reali, 652 ms contro oltre due minuti.
     *
     * @return array<int, object>
     */
    protected function sectorsCrossedBy(string $table, int $id): array
    {
        return DB::select(
            <<<SQL
            WITH traccia AS (
                SELECT geometry FROM {$table} WHERE id = ? AND geometry IS NOT NULL
            )
            SELECT
                tw.id,
                tw.properties->>'full_code' AS full_code,
                ST_Length(
                    ST_Intersection(tw.geometry::geometry, t.geometry::geometry)::geography
                ) / NULLIF(ST_Length(t.geometry), 0) * 100 AS percentuale
            FROM taxonomy_wheres tw, traccia t
            WHERE tw.properties->>'source' = ?
              AND COALESCE(tw.properties->>'full_code', '') <> ''
              AND ST_Intersects(tw.geometry, t.geometry)
            ORDER BY percentuale DESC NULLS LAST
            SQL,
            [$id, (string) config('wm-package.features.trail_registry.sector_source', 'osm2cai')],
        );
    }

    /**
     * Un settore sulla mappa: quello in evidenza è pieno e marcato, gli altri
     * grigi e sottili, perché fanno da contesto e non devono competere con il
     * tracciato.
     *
     * Il riempimento dei non evidenziati è a 0.15 e non più basso: un primo
     * tentativo a 0.06 con bordo di un pixel risultava invisibile sullo sfondo
     * cartografico. Il riferimento è osm2cai, che per gli stessi poligoni usa
     * lo stesso valore.
     *
     * @return array<string, mixed>|null
     */
    protected function sectorFeature(
        string $novaPath,
        int $sectorId,
        ?float $percentage,
        bool $highlighted,
        ?string $note = null,
    ): ?array {
        $geometry = $this->geojsonFrom('taxonomy_wheres', $sectorId);

        if ($geometry === null) {
            return null;
        }

        $row = DB::selectOne(
            "SELECT properties->>'full_code' AS full_code FROM taxonomy_wheres WHERE id = ?",
            [$sectorId],
        );

        $tooltip = __('Settore').' '.($row === null ? '' : (string) $row->full_code);

        if ($percentage !== null) {
            $tooltip .= ' ('.number_format($percentage, 1, ',', '.').'%)';
        }

        if ($note !== null) {
            $tooltip .= ' — '.$note;
        }

        return [
            'type' => 'Feature',
            'geometry' => $geometry,
            'properties' => [
                'taxonomy_where_id' => $sectorId,
                'tooltip' => $tooltip,
                'strokeColor' => $highlighted ? 'rgba(37, 99, 235, 1)' : 'rgba(100, 116, 139, 1)',
                'strokeWidth' => $highlighted ? 4 : 2,
                'fillColor' => $highlighted ? 'rgba(37, 99, 235, 0.20)' : 'rgba(100, 116, 139, 0.15)',
                'link' => url($novaPath.'/resources/'.static::novaUriKey('taxonomy_where').'/'.$sectorId),
            ],
        ];
    }

    /**
     * La geometria di una riga, come GeoJSON decodificato.
     *
     * Restituisce null quando la riga non esiste più o non ha geometria: una
     * feature in meno sulla mappa, non un errore — la scheda deve aprirsi
     * comunque.
     *
     * @return array<string, mixed>|null
     */
    protected function geojsonFrom(string $table, int $id): ?array
    {
        $row = DB::selectOne(
            "SELECT ST_AsGeoJSON(geometry) AS geojson FROM {$table} WHERE id = ? AND geometry IS NOT NULL",
            [$id],
        );

        if ($row === null || $row->geojson === null) {
            return null;
        }

        return json_decode($row->geojson, true) ?: null;
    }

    /**
     * Gli altri codici attivi dello stesso settore, con la loro geometria: i
     * «vicini» che la mappa disegna perche' chi sceglie un numero possa vedere
     * quelli gia' presi li' intorno (oc:8568).
     *
     * **Una query sola**, con le geometrie gia' dentro: `geojsonFrom()` ne
     * farebbe una per riga, e in un settore popolato sono decine di giri al
     * database prima che la mappa compaia.
     *
     * Il settore si legge dalle colonne e non si ricalcola per intersezione:
     * il `full_code` scritto nel codice **e'** il risultato di quel calcolo,
     * fatto quando il codice e' nato.
     *
     * La geometria viene dal sentiero quando c'e', altrimenti dall'istanza:
     * `ec_track_id` e' nullable, e un codice riservato da un'istanza non
     * ancora approvata non ha sentiero. Senza il ripiego sparirebbe dalla
     * mappa proprio il vicino piu' recente, cioe' il piu' rilevante per chi
     * deve scegliere un numero.
     *
     * Gli stati sono quelli che **occupano** una posizione
     * ({@see TrailCodeStatus::active()}): gli stessi che popolano la select
     * del «sostituisci numero», e vanno tenuti allineati a quella.
     *
     * @return array<int, object>
     */
    protected function neighbourCodes(
        string $region,
        string $province,
        string $area,
        string $sector,
        int $excludeId,
    ): array {
        $ecTracks = (string) config('wm-package.ec_track_table', 'ec_tracks');

        $active = array_map(
            fn (TrailCodeStatus $status) => $status->value,
            TrailCodeStatus::active(),
        );

        $placeholders = implode(',', array_fill(0, count($active), '?'));

        return DB::select(
            <<<SQL
            SELECT
                c.id,
                c.number,
                c.variant,
                c.ec_track_id,
                c.trail_application_id,
                ST_AsGeoJSON(COALESCE(t.geometry, a.geometry)) AS geojson
            FROM trail_registry_codes c
            LEFT JOIN {$ecTracks} t ON t.id = c.ec_track_id
            LEFT JOIN trail_applications a ON a.id = c.trail_application_id
            WHERE c.region = ?
              AND c.province = ?
              AND c.area = ?
              AND c.sector = ?
              AND c.status IN ({$placeholders})
              AND c.id <> ?
              AND COALESCE(t.geometry, a.geometry) IS NOT NULL
            ORDER BY c.number, c.variant
            SQL,
            array_merge([$region, $province, $area, $sector], $active, [$excludeId]),
        );
    }

    protected function novaPath(): string
    {
        return '/'.trim(config('nova.path', '/nova'), '/');
    }

    /**
     * La chiave con cui Nova indirizza una Resource collegata.
     *
     * Configurabile perche' un consumer puo' sovrascrivere `uriKey()`: il
     * valore predefinito e' quello che Nova ricava dal nome della classe, e
     * finche' nessuno lo cambia i collegamenti funzionano da soli.
     */
    protected static function novaUriKey(string $which): string
    {
        $defaults = [
            'ec_track' => 'ec-tracks',
            'taxonomy_where' => 'taxonomy-wheres',
            'trail_application' => 'trail-applications',
            'trail_registry_code' => 'trail-registry-codes',
        ];

        return (string) config(
            "wm-package.features.trail_registry.nova_uri_keys.{$which}",
            $defaults[$which] ?? $which,
        );
    }
}
