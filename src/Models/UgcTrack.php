<?php

namespace Wm\WmPackage\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\Abstracts\MultiLineString;
use Wm\WmPackage\Models\Interfaces\UserOwnedModelInterface;
use Wm\WmPackage\Observers\UgcObserver;
use Wm\WmPackage\Observers\UgcTrackGeometryCleanupObserver;
use Wm\WmPackage\Services\Models\UgcTrackCleanupService;
use Wm\WmPackage\Traits\OwnedByUserModel;
use Wm\WmPackage\Traits\TaxonomyAbleModel;
use Wm\WmPackage\Traits\TaxonomyWhereAbleModel;

/**
 * Class UgcTrack
 *
 *
 * @property int    id
 * @property array sku
 * @property string relative_url
 * @property string geometry
 * @property string name
 * @property string description
 * @property string raw_data
 */
class UgcTrack extends MultiLineString implements UserOwnedModelInterface
{
    use OwnedByUserModel, TaxonomyAbleModel, TaxonomyWhereAbleModel;

    /** Colore e tratteggio dei tratti ricostruiti sulla mappa (oc:8719), usati anche dalla legenda Nova. */
    public const RECONSTRUCTED_SEGMENT_COLOR = 'rgba(234, 88, 12, 1)';

    public const RECONSTRUCTED_SEGMENT_DASH = [8, 8];

    /**
     * Colori delle EcTrack di contesto sulla mappa Nova (oc:8747), uno per cammino (layer).
     * Si assegnano per mappa: i cammini presenti, in ordine di id, prendono i colori in sequenza,
     * quindi fino a 8 cammini sulla stessa mappa non si ripetono mai.
     * Tinte che risaltano sulle tile OSM e diverse dal blu della traccia (#0000ff) e
     * dall'arancio dei tratti ricostruiti, in un ordine che alterna famiglie di colore perché
     * due cammini vicini nella sequenza si distinguano bene: viola, rosso, quasi nero, rosa,
     * marrone, violetto scuro, cremisi, fucsia. Opacità 0,9.
     */
    public const CONTEXT_TRACK_PALETTE = [
        'rgba(147, 51, 234, 0.9)',
        'rgba(220, 38, 38, 0.9)',
        'rgba(31, 41, 55, 0.9)',
        'rgba(219, 39, 119, 0.9)',
        'rgba(124, 45, 18, 0.9)',
        'rgba(76, 29, 149, 0.9)',
        'rgba(190, 18, 60, 0.9)',
        'rgba(162, 28, 175, 0.9)',
    ];

    /**
     * Margine della vista iniziale della mappa Nova (oc:8747), in frazione del bbox per lato:
     * lo usano sia ->extentMargin() del campo in Nova\UgcTrack sia l'area della query di
     * contesto, così i cammini cercati sono quelli che la vista iniziale mostra.
     */
    public const MAP_EXTENT_MARGIN = 0.3;

    /** Tetto delle EcTrack di contesto sulla mappa Nova (oc:8747). */
    public const CONTEXT_TRACKS_LIMIT = 200;

    protected $fillable = [
        'user_id',
        'app_id',
        'name',
        'geometry',
        'properties',
        'created_by',
    ];

    protected $casts = [
        'properties' => 'array',
    ];

    /** Righe di contesto già lette su questa istanza (oc:8747), per chiave 'geometry'/'light'. */
    private array $layerContextRowsMemo = [];

    protected static function booted()
    {
        parent::booted();
        UgcTrack::observe(UgcObserver::class);
        UgcTrack::observe(UgcTrackGeometryCleanupObserver::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Persisted final Stories share image (oc:8183, third revision) — needed so the public
     * `GET /share/ugc-track/{uuid}` page (see ShareUgcTrackController) can serve it to OG
     * crawlers (WhatsApp/Facebook/Twitter) asynchronously, potentially long after the share
     * request that generated it. `singleFile()`: re-sharing the same track replaces the
     * previous snapshot image rather than accumulating one per share.
     *
     * Overrides (does not replace) the parent's `registerMediaCollections()` — GeometryModel
     * registers a generic `default` collection used elsewhere for UGC photos; that one is
     * kept as-is.
     */
    public function registerMediaCollections(): void
    {
        parent::registerMediaCollections();

        $this->addMediaCollection('share_image')->singleFile();
    }

    /**
     * Mappa Nova: oltre alla geometria, i tratti ricostruiti al posto dei punti GPS scartati
     * (oc:8719), tratteggiati. I punti scartati non si disegnano: sono a chilometri dalla traccia
     * e allargherebbero la mappa.
     *
     * La linea della traccia porta `slopeChart: true` (meccanismo di oc:8662): con i tratti
     * ricostruiti le linee sono più d'una e senza il segno il campo non mostrerebbe il profilo
     * altimetrico (oc:8742).
     */
    public function getFeatureCollectionMap(): array
    {
        $collection = parent::getFeatureCollectionMap();
        foreach ($collection['features'] ?? [] as $i => $feature) {
            if (in_array($feature['geometry']['type'] ?? null, ['LineString', 'MultiLineString'], true)) {
                $collection['features'][$i]['properties']['slopeChart'] = true;
            }
        }
        $cleanup = UgcTrackCleanupService::make();
        $locations = $cleanup->locationsOf($this);

        if ($locations !== null) {
            $collection = $this->addReconstructedSegments($collection, $cleanup, $locations);
        }

        // oc:8747: i percorsi dell'App che passano nella zona, come contesto. In testa alla
        // collection: il campo disegna le feature nell'ordine ricevuto, e così la traccia UGC
        // resta sopra, anche per il tooltip al passaggio del mouse.
        $context = $this->layerContextFeatures();
        if ($context !== []) {
            $collection['features'] = [...$context, ...($collection['features'] ?? [])];
        }

        return $collection;
    }

    /**
     * Tratti ricostruiti al posto dei punti GPS scartati (oc:8719).
     */
    private function addReconstructedSegments(array $collection, UgcTrackCleanupService $cleanup, array $locations): array
    {
        foreach ($cleanup->gaps($locations) as $gap) {
            $collection['features'][] = [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'LineString',
                    'coordinates' => [
                        [(float) $gap['from']['longitude'], (float) $gap['from']['latitude']],
                        [(float) $gap['to']['longitude'], (float) $gap['to']['latitude']],
                    ],
                ],
                'properties' => [
                    'strokeColor' => self::RECONSTRUCTED_SEGMENT_COLOR,
                    'strokeWidth' => 4,
                    'strokeDash' => self::RECONSTRUCTED_SEGMENT_DASH,
                    'tooltip' => __('Reconstructed segment: :discarded points discarded in :minutes min (max accuracy :accuracy m)', [
                        'discarded' => $gap['discarded'],
                        'minutes' => (int) ceil($gap['seconds'] / 60),
                        'accuracy' => (int) round($gap['max_accuracy']),
                    ]),
                ],
            ];
        }

        return $collection;
    }

    /**
     * Contesto della mappa Nova (oc:8747): le EcTrack dei layer dell'App della UGC che passano
     * nella zona della traccia, un colore per cammino, per vedere come la UGC sta rispetto ai percorsi.
     *
     * Zona = bbox della UGC allargato di MAP_EXTENT_MARGIN per lato (in proporzione a larghezza
     * e altezza, in gradi EPSG:4326), lo stesso margine dell'inquadratura iniziale del campo.
     * Basta che una traccia tocchi la zona. Sull'asse che l'aspetto della mappa allarga la vista
     * mostra un po' di più della zona: lì un percorso visibile può non essere disegnato.
     * Una sola query PostGIS, al massimo CONTEXT_TRACKS_LIMIT tracce, geometrie 2D.
     * Una EcTrack in più layer dell'App compare una volta, con i nomi dei layer uniti nel tooltip.
     * Le feature portano `context: true` (il fit iniziale del campo le ignora e le disegna sotto),
     * `lineLabel` col nome della EcTrack (scritto lungo la linea nel suo colore) e niente `slopeChart`.
     * Nessuna feature se la UGC non ha App o geometria.
     *
     * Gira solo dalla route del campo, cioè quando la mappa del dettaglio chiede il GeoJSON:
     * l'index non la chiama.
     *
     * @return list<array<string, mixed>>
     */
    public function layerContextFeatures(): array
    {
        $rows = $this->layerContextRows(withGeometry: true);

        // Colore per cammino, assegnato su questa mappa: con più layer per traccia vale quello
        // con id più basso (color_layer_id).
        $colors = self::contextTrackColors(array_map(fn ($row) => (int) $row->color_layer_id, $rows));

        $features = [];
        foreach ($rows as $row) {
            $layerNames = array_map(
                fn (?string $raw) => (new Layer)->setRawAttributes(['name' => $raw], true)->getStringName(),
                json_decode($row->layer_names, true) ?: []
            );
            $layerLabel = implode(', ', array_filter($layerNames));
            // Stessa regola di fallback del nome layer (lingua corrente → it → en → prima).
            $trackName = (new Layer)->setRawAttributes(['name' => $row->track_name], true)->getStringName();

            $features[] = [
                'type' => 'Feature',
                'geometry' => json_decode($row->geojson, true),
                'properties' => [
                    // Niente `id`: il campo indicizza le feature per id e quello di una EcTrack
                    // potrebbe coincidere con quello della traccia UGC.
                    'strokeColor' => $colors[(int) $row->color_layer_id],
                    'strokeWidth' => 3,
                    // Nome della tappa (EcTrack) scritto lungo la linea, dove ci sta: il cammino
                    // si riconosce dal colore e dalla legenda.
                    'lineLabel' => $trackName,
                    'tooltip' => $layerLabel.' — '.$trackName,
                    'context' => true,
                ],
            ];
        }

        return $features;
    }

    /**
     * Voci di legenda dei cammini sulla mappa Nova (oc:8747): una per layer, con lo stesso
     * colore e nello stesso ordine di layerContextFeatures(). Il layer di una voce è quello che
     * colora le tracce (id più basso per traccia): un layer che compare solo come secondo di
     * una traccia condivisa non ha voce, perché nessuna linea ha il suo colore.
     * Stessa query delle feature ma senza geometrie: la legenda nasce in fields() del dettaglio,
     * le feature nella richiesta separata del campo.
     *
     * @return list<array{label: string, color: string, dash: bool}>
     */
    public function contextLegendItems(): array
    {
        $rows = $this->layerContextRows(withGeometry: false);
        $colors = self::contextTrackColors(array_map(fn ($row) => (int) $row->color_layer_id, $rows));

        $names = [];
        foreach ($rows as $row) {
            $names[(int) $row->color_layer_id] ??= (new Layer)->setRawAttributes(['name' => $row->color_layer_name], true)->getStringName();
        }

        $items = [];
        foreach ($colors as $layerId => $color) {
            $items[] = ['label' => $names[$layerId], 'color' => $color, 'dash' => false];
        }

        return $items;
    }

    /**
     * Righe delle EcTrack di contesto (oc:8747), condivise da feature e legenda. Memorizzate
     * sull'istanza: le righe con geometria valgono anche per la legenda.
     *
     * @return list<object>
     */
    private function layerContextRows(bool $withGeometry): array
    {
        if (! $this->app_id || ! $this->getKey()) {
            return [];
        }
        if (isset($this->layerContextRowsMemo['geometry'])) {
            return $this->layerContextRowsMemo['geometry'];
        }
        $memoKey = $withGeometry ? 'geometry' : 'light';
        if (isset($this->layerContextRowsMemo[$memoKey])) {
            return $this->layerContextRowsMemo[$memoKey];
        }

        // layerable_type come lo scrive Layer::ecTracks(): il morph class del modello configurato.
        // Se la classe non esiste (package senza shard) il valore di config è già l'alias del morphMap.
        $ecTrackClass = config('wm-package.ec_track_model', EcTrack::class);
        $morphType = class_exists($ecTrackClass) ? (new $ecTrackClass)->getMorphClass() : $ecTrackClass;
        $geojson = $withGeometry ? 'ST_AsGeoJSON(ST_Force2D(ec.geometry::geometry), 6)' : 'NULL';

        $rows = DB::select(<<<SQL
            WITH area AS (
                SELECT ST_Expand(e, (ST_XMax(e) - ST_XMin(e)) * ?, (ST_YMax(e) - ST_YMin(e)) * ?)::geography AS box
                FROM (SELECT ST_Envelope(geometry::geometry) AS e FROM ugc_tracks WHERE id = ? AND geometry IS NOT NULL) u
            )
            SELECT ec.id,
                   ec.name::text AS track_name,
                   array_to_json(array_agg(DISTINCT l.name::text)) AS layer_names,
                   MIN(l.id) AS color_layer_id,
                   (array_agg(l.name::text ORDER BY l.id))[1] AS color_layer_name,
                   {$geojson} AS geojson
            FROM area
            JOIN ec_tracks ec ON ec.geometry && area.box AND ST_Intersects(ec.geometry, area.box)
            JOIN layerables lb ON lb.layerable_id = ec.id AND lb.layerable_type = ?
            JOIN layers l ON l.id = lb.layer_id AND l.app_id = ?
            GROUP BY ec.id
            ORDER BY ec.id
            LIMIT ?
            SQL, [self::MAP_EXTENT_MARGIN, self::MAP_EXTENT_MARGIN, $this->getKey(), $morphType, $this->app_id, self::CONTEXT_TRACKS_LIMIT]);

        return $this->layerContextRowsMemo[$memoKey] = $rows;
    }

    /**
     * Colori di contesto dei cammini di una mappa (oc:8747): id distinti in ordine crescente,
     * l'i-esimo prende CONTEXT_TRACK_PALETTE[i % 8]. Stessa mappa, stessi colori.
     *
     * @param  array<int, int>  $layerIds
     * @return array<int, string> id layer => colore
     */
    public static function contextTrackColors(array $layerIds): array
    {
        $ids = array_values(array_unique($layerIds));
        sort($ids);
        $palette = self::CONTEXT_TRACK_PALETTE;

        $colors = [];
        foreach ($ids as $i => $id) {
            $colors[$id] = $palette[$i % count($palette)];
        }

        return $colors;
    }
}
