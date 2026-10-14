<?php

namespace Wm\WmPackage\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Jobs\MatchUgcTrackToEcTracksJob;
use Wm\WmPackage\Models\Abstracts\MultiLineString;
use Wm\WmPackage\Models\Interfaces\UserOwnedModelInterface;
use Wm\WmPackage\Observers\UgcObserver;
use Wm\WmPackage\Observers\UgcTrackGeometryCleanupObserver;
use Wm\WmPackage\Services\Models\ContextTrackBandsService;
use Wm\WmPackage\Services\Models\UgcTrackCleanupService;
use Wm\WmPackage\Services\Models\UgcTrackMatchingService;
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

    /**
     * Colore dei tratti ricostruiti sulla mappa (oc:8719), usato anche dalla legenda Nova.
     * Linea continua dal oc:8776: la legenda non cambia con lo zoom.
     */
    public const RECONSTRUCTED_SEGMENT_COLOR = 'rgba(234, 88, 12, 1)';

    /** Colore della traccia registrata sulla mappa, lo stesso della legenda Nova. */
    public const TRACK_COLOR = 'rgba(0, 0, 255, 1)';

    /**
     * Traccia sulla mappa con le tappe di contesto (oc:8776): sempre la stessa misura, a ogni
     * zoom, TRACK_WIDTH su un contorno bianco largo TRACK_CASING_WIDTH, che la stacca dai colori
     * dei cammini. Vale anche per i tratti ricostruiti.
     */
    public const TRACK_WIDTH = 1.5;

    public const TRACK_CASING_WIDTH = 2.5;

    public const TRACK_CASING_COLOR = 'rgba(255, 255, 255, 1)';

    /**
     * Tappe di contesto larghe CONTEXT_STAGE_WIDTH (oc:8776): accanto alla traccia, più sottile,
     * restano 1,25px per lato del colore della tappa.
     */
    public const CONTEXT_STAGE_WIDTH = 5;

    /**
     * Le tappe toccate si disegnano intere larghe CONTEXT_THIN_WIDTH, meno della traccia, e
     * CONTEXT_STAGE_WIDTH solo nei pezzi che la traccia tocca (oc:8776): una tappa incrociata per
     * poche centinaia di metri non si colora piena per tutti i suoi chilometri.
     */
    public const CONTEXT_THIN_WIDTH = 1.5;

    /** Pezzi di tappa toccati dalla traccia (oc:8776): 2,25px di colore per lato accanto alla traccia. */
    public const CONTEXT_TOUCHED_WIDTH = 7;

    /**
     * Mappa vista da lontano (oc:8776): quando la tolleranza della corrispondenza (oc:8773) sta in
     * ZOOMED_OUT_TOLERANCE_PIXELS, linee distanti meno di quella finiscono sugli stessi pixel, e
     * non si capisce più quali cammini passano lì. Sui tratti condivisi da più cammini si posa
     * allora sulla tappa un pezzo di sé stessa, a segmenti di ZOOMED_OUT_DASH_LENGTH pixel nei
     * colori di quei cammini. Avvicinandosi le linee si separano da sole e quel pezzo sparisce.
     */
    public const ZOOMED_OUT_TOLERANCE_PIXELS = 5;

    public const ZOOMED_OUT_DASH_LENGTH = 8;

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

    /**
     * Quanto dura il lock del rilancio del calcolo di corrispondenza all'apertura del dettaglio
     * (oc:8773): una traccia il cui calcolo fallisce si riaccoda al massimo una volta in questo
     * intervallo, non a ogni apertura. Basta che superi l'attesa tipica in coda più il calcolo
     * (pochi secondi); finché il job non parte lo rende comunque unico ShouldBeUniqueUntilProcessing.
     */
    public const MATCHING_LOCK_SECONDS = 300;

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

    /**
     * Origine della traccia (oc:8747): 'recorded' se registrata con l'app, 'imported' se
     * caricata da file. Verificato sui dati reali:
     * - l'uploader dell'app (wm-core modal-ugc-uploader) converte i GPX con @tmcw/togeojson,
     *   che aggiunge properties._gpxType ("trk"/"rte"); KML e GeoJSON non hanno _gpxType né
     *   distanceFilter (portano chiavi di stile come stroke, stroke-width);
     * - le registrazioni dell'app hanno sempre properties.distanceFilter; dalla 3.1.7 hanno
     *   anche properties.locations (le 3.1.2-3.1.6 hanno distanceFilter ma non locations).
     * Regola: _gpxType presente → importata; altrimenti locations non vuoto o distanceFilter
     * presente → registrata; altrimenti → importata (anche un GeoJSON caricato senza chiavi proprie).
     * Limite noto: un GeoJSON esportato a sua volta da una registrazione dell'app può portare
     * ancora locations/distanceFilter e risulta «registrata»: l'uploader non aggiunge alcun
     * marcatore suo.
     */
    public function origin(): string
    {
        $properties = $this->properties ?? [];
        if (isset($properties['_gpxType'])) {
            return 'imported';
        }
        if (! empty($properties['locations']) && is_array($properties['locations'])) {
            return 'recorded';
        }

        return isset($properties['distanceFilter']) ? 'recorded' : 'imported';
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Segmenti di EcTrack corrispondenti a questa traccia (oc:8773).
     *
     * @return HasMany<UgcTrackEcTrackMatch, $this>
     */
    public function ecTrackMatches(): HasMany
    {
        return $this->hasMany(UgcTrackEcTrackMatch::class);
    }

    /**
     * Stato del calcolo di corrispondenza (oc:8773): null se non è mai stato fatto.
     *
     * @return HasOne<UgcTrackMatchingRun, $this>
     */
    public function matchingRun(): HasOne
    {
        return $this->hasOne(UgcTrackMatchingRun::class);
    }

    /**
     * Serve (ri)calcolare la corrispondenza (oc:8773): stato assente, fallito, oppure calcolato con
     * segmento o distanza massima diversi dai parametri attuali della App. Stessa regola di
     * UgcTrackMatchingService::unmatchedUgcTrackIdsQuery(). Chi ha già letto stato e parametri
     * (il campo Nova) usa runNeedsMatching() e non rifà le query.
     */
    public function matchingNeeded(): bool
    {
        return self::runNeedsMatching(
            $this->matchingRun()->first(),
            UgcTrackMatchingService::make()->params((int) $this->app_id),
        );
    }

    /**
     * La regola di matchingNeeded() su stato e parametri già letti.
     *
     * @param  array{segment_length_meters:int, max_distance_meters:int}  $params
     */
    public static function runNeedsMatching(?UgcTrackMatchingRun $run, array $params): bool
    {
        if ($run === null || $run->status === UgcTrackMatchingRun::STATUS_FAILED) {
            return true;
        }

        return (int) $run->segment_length_meters !== $params['segment_length_meters']
            || (int) $run->max_distance_meters !== $params['max_distance_meters'];
    }

    /**
     * Accoda il calcolo di corrispondenza se serve (oc:8773), come
     * MultiLineString::dispatchDemIfMissing() per il DEM: lo chiama il dettaglio Nova. Il lock
     * su Redis evita di riaccodare a ogni apertura mentre il job è in coda o è appena fallito.
     * Con $needed già calcolato dal chiamante non rilegge stato e parametri.
     * Ritorna true se ha accodato.
     *
     * Con il driver di coda `sync` il calcolo gira dentro la richiesta Nova del dettaglio, e
     * un'eccezione del calcolo arriva fino alla pagina: il meccanismo presuppone una coda vera
     * (camminiditalia usa Horizon su redis).
     */
    public function dispatchMatchingIfNeeded(?bool $needed = null): bool
    {
        if (! ($needed ?? $this->matchingNeeded())) {
            return false;
        }
        if (! Cache::store('redis')->add('ugc-track-matching-lock:'.$this->getKey(), true, self::MATCHING_LOCK_SECONDS)) {
            return false;
        }
        MatchUgcTrackToEcTracksJob::dispatch((int) $this->getKey());

        return true;
    }

    /**
     * Id dei layer attraversati (oc:8773), ricavati dalla corrispondenza per segmenti e da layerables;
     * le righe di layerables che puntano a layer cancellati sono ignorate.
     *
     * @return array<int, int>
     */
    public function matchedLayerIds(): array
    {
        return DB::table('ugc_track_ec_track_matches as m')
            ->join('layerables as lb', function ($join) {
                $join->on('lb.layerable_id', '=', 'm.ec_track_id')
                    ->where('lb.layerable_type', UgcTrackMatchingService::make()->ecTrackMorphClass());
            })
            ->join('layers as l', 'l.id', '=', 'lb.layer_id')
            ->where('m.ugc_track_id', $this->id)
            ->distinct()->orderBy('lb.layer_id')
            ->pluck('lb.layer_id')->map(fn ($id) => (int) $id)->all();
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
        // oc:8776: la traccia, sottile con un contorno bianco; le tappe sottili, piene solo nei pezzi
        // che la traccia tocca, e da lontano a segmenti dove su quei pezzi passano più cammini.
        // Se quel calcolo non riesce, le tappe restano piene. L'ordine lo danno gli zIndex.
        $maxDistance = $this->matchingMaxDistance($this->computedMatchingRun());
        $minResolution = $maxDistance !== null ? $this->zoomedOutMinResolution($maxDistance) : null;
        $portions = $maxDistance !== null ? $this->touchedPortionFeatures($minResolution, $maxDistance) : null;
        $context = $this->layerContextFeatures(thin: $portions !== null);
        if ($context === []) {
            return $collection;
        }
        $context = [...$context, ...($portions ?? [])];

        foreach ($collection['features'] ?? [] as $i => $feature) {
            $isTrack = ($feature['properties']['slopeChart'] ?? false) === true;
            $isReconstructed = ($feature['properties']['strokeColor'] ?? null) === self::RECONSTRUCTED_SEGMENT_COLOR;
            if ($isTrack || $isReconstructed) {
                $collection['features'][$i]['properties']['strokeColor'] = $isTrack ? self::TRACK_COLOR : self::RECONSTRUCTED_SEGMENT_COLOR;
                $collection['features'][$i]['properties']['strokeWidth'] = self::TRACK_WIDTH;
                $collection['features'][$i]['properties']['casing'] = ['color' => self::TRACK_CASING_COLOR, 'width' => self::TRACK_CASING_WIDTH];
            }
            // Nel tooltip, fra le tappe, «Traccia registrata» come in legenda e non il nome dato
            // dall'utente, che non dice cosa sia quella linea.
            if ($isTrack) {
                $collection['features'][$i]['properties']['tooltip'] = __('Recorded track');
            }
            // Nel tooltip con tutte le linee: prima la traccia, poi i tratti ricostruiti, poi le tappe.
            if ($isTrack || $isReconstructed) {
                $collection['features'][$i]['properties']['tooltipOrder'] = $isTrack ? 0 : 1;
            }
        }
        $collection['features'] = [...$context, ...($collection['features'] ?? [])];

        return $collection;
    }

    /**
     * Resolution OpenLayers (unità EPSG:3857 per pixel) da cui la mappa è «vista da lontano»
     * (oc:8776): quando la tolleranza della corrispondenza sta in ZOOMED_OUT_TOLERANCE_PIXELS.
     * Un'unità EPSG:3857 vale cos(latitudine) metri a terra: la soglia si divide per quel valore.
     * Null se la traccia non ha geometria.
     */
    public function zoomedOutMinResolution(int $maxDistanceMeters): ?float
    {
        $lat = DB::selectOne(
            'SELECT ST_Y(ST_Centroid(geometry::geometry)) AS lat FROM ugc_tracks WHERE id = ? AND geometry IS NOT NULL',
            [$this->getKey()]
        )?->lat;
        if ($lat === null) {
            return null;
        }

        return $maxDistanceMeters / self::ZOOMED_OUT_TOLERANCE_PIXELS / max(cos(deg2rad((float) $lat)), 0.01);
    }

    /** Riga `computed` della corrispondenza (oc:8773), o null: con `failed` le righe vecchie non valgono. */
    private function computedMatchingRun(): ?object
    {
        return DB::table('ugc_track_matching_runs')
            ->where('ugc_track_id', $this->getKey())
            ->where('status', 'computed')
            ->first();
    }

    /** Tolleranza della corrispondenza: quella del calcolo fatto, altrimenti quella dell'App. */
    private function matchingMaxDistance(?object $run): ?int
    {
        if ($run !== null) {
            return (int) $run->max_distance_meters;
        }

        return $this->app_id ? UgcTrackMatchingService::make()->params((int) $this->app_id)['max_distance_meters'] : null;
    }

    /** Colore CSS rgba con opacità 1 (oc:8776): le linee vicine non devono mescolarsi. */
    private static function opaque(string $rgba): string
    {
        return preg_replace('/,\s*[\d.]+\)$/', ', 1)', $rgba);
    }

    /**
     * Righe di contesto mostrate sulla mappa (oc:8776): con la corrispondenza `computed` di oc:8773
     * solo le EcTrack toccate dalla traccia; senza, tutte quelle della zona (layerContextRows()),
     * perché non si sa ancora quali sono toccate. Feature, legenda e tratti condivisi usano
     * queste, quindi vedono le stesse tappe.
     *
     * @return list<object>
     */
    private function contextRows(bool $withGeometry): array
    {
        $rows = $this->layerContextRows($withGeometry);
        $traversed = $rows === [] ? null : $this->traversedEcTrackIds();
        if ($traversed === null) {
            return $rows;
        }

        return array_values(array_filter($rows, fn ($row) => in_array((int) $row->id, $traversed, true)));
    }

    /**
     * Id delle EcTrack toccate dalla traccia secondo la corrispondenza `computed` (oc:8773), o
     * null se la corrispondenza non c'è.
     *
     * @return list<int>|null
     */
    private function traversedEcTrackIds(): ?array
    {
        if ($this->computedMatchingRun() === null) {
            return null;
        }

        return DB::table('ugc_track_ec_track_matches')
            ->where('ugc_track_id', $this->getKey())
            ->pluck('ec_track_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Tratti ricostruiti al posto dei punti GPS scartati (oc:8719), continui dal oc:8776.
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
     * niente `slopeChart` e niente `lineLabel`: dal oc:8776 il nome della tappa sta solo nel
     * tooltip, scritto lungo la linea copriva la mappa.
     * Nessuna feature se la UGC non ha App o geometria.
     * Con la corrispondenza calcolata (oc:8773) solo le tappe toccate dalla traccia (oc:8776),
     * larghe CONTEXT_STAGE_WIDTH, o CONTEXT_THIN_WIDTH con $thin quando i pezzi toccati si
     * disegnano a parte (touchedPortionFeatures()).
     *
     * Gira solo dalla route del campo, cioè quando la mappa del dettaglio chiede il GeoJSON:
     * l'index non la chiama.
     *
     * @return list<array<string, mixed>>
     */
    public function layerContextFeatures(bool $thin = false): array
    {
        $rows = $this->contextRows(withGeometry: true);

        // Colore per cammino, assegnato su questa mappa: con più layer per traccia vale quello
        // con id più basso (color_layer_id).
        $colors = self::contextTrackColors(array_map(fn ($row) => (int) $row->color_layer_id, $rows));

        $features = [];
        foreach ($rows as $row) {
            [$layerLabel, $trackName] = $this->contextNames($row);

            $properties = [
                // Niente `id`: il campo indicizza le feature per id e quello di una EcTrack
                // potrebbe coincidere con quello della traccia UGC.
                'strokeColor' => $colors[(int) $row->color_layer_id],
                'strokeWidth' => $thin ? self::CONTEXT_THIN_WIDTH : self::CONTEXT_STAGE_WIDTH,
                'tooltip' => $layerLabel.' — '.$trackName,
                'context' => true,
            ];

            $features[] = [
                'type' => 'Feature',
                'geometry' => json_decode($row->geojson, true),
                'properties' => $properties,
            ];
        }

        return $features;
    }

    /**
     * Pezzi di tappa toccati dalla traccia (oc:8776): le porzioni delle tappe entro la tolleranza
     * della corrispondenza dalla traccia (ContextTrackBandsService), larghe CONTEXT_TOUCHED_WIDTH
     * nel colore del cammino, a ogni zoom, sopra le tappe sottili. Da lontano, dove su un pezzo
     * passano più cammini, quello del primo si colora a segmenti nei colori di tutti (dashColors).
     * Null se il calcolo non riesce; lista vuota senza tappe.
     *
     * @return list<array<string, mixed>>|null
     */
    private function touchedPortionFeatures(?float $minResolution, int $maxDistance): ?array
    {
        $rows = $this->contextRows(withGeometry: true);
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row->id] = $row;
        }
        $colors = self::contextTrackColors(array_map(fn ($row) => (int) $row->color_layer_id, $rows));
        $layerNames = [];
        foreach ($rows as $row) {
            $layerNames[(int) $row->color_layer_id] ??= (new Layer)->setRawAttributes(['name' => $row->color_layer_name], true)->getStringName();
        }

        if ($byId === []) {
            return [];
        }
        $bands = ContextTrackBandsService::make()->bands(
            array_map(fn ($row) => (int) $row->color_layer_id, $byId),
            $maxDistance,
            (int) $this->getKey()
        );
        if ($bands === null) {
            return null;
        }

        $features = [];
        foreach ($bands as $band) {
            $row = $byId[$band['ec_track_id']];
            [$layerLabel, $trackName] = $this->contextNames($row);
            $features[] = [
                'type' => 'Feature',
                'geometry' => $band['geometry'],
                'properties' => [
                    'strokeColor' => $colors[(int) $row->color_layer_id],
                    'strokeWidth' => self::CONTEXT_TOUCHED_WIDTH,
                    'tooltip' => $layerLabel.' — '.$trackName,
                    'context' => true,
                ],
            ];
            if ($minResolution === null || $band['rank'] !== 0 || $band['n'] < 2) {
                continue;
            }
            $others = array_map(fn (int $layerId) => $layerNames[$layerId], array_slice($band['cammini'], 1));
            $features[] = [
                'type' => 'Feature',
                'geometry' => $band['geometry'],
                'properties' => [
                    'strokeColor' => self::opaque($colors[(int) $row->color_layer_id]),
                    'strokeWidth' => self::CONTEXT_TOUCHED_WIDTH,
                    'tooltip' => implode("\n", [$layerLabel.' — '.$trackName, ...$others]),
                    'context' => true,
                    'onlyZoomedOut' => true,
                    'zoomedOutStyle' => [
                        'minResolution' => $minResolution,
                        'strokeWidth' => self::CONTEXT_TOUCHED_WIDTH,
                        // Sopra le tappe (zIndex -1) e sotto la traccia (0).
                        'zIndex' => -0.5,
                        'dashColors' => array_map(fn (int $layerId) => self::opaque($colors[$layerId]), $band['cammini']),
                        'dashLength' => self::ZOOMED_OUT_DASH_LENGTH,
                    ],
                ],
            ];
        }

        return $features;
    }

    /**
     * Nomi di layer e tappa di una riga di contesto, con la regola di fallback del nome layer
     * (lingua corrente → it → en → prima).
     *
     * @return array{0: string, 1: string}
     */
    private function contextNames(object $row): array
    {
        $layerNames = array_map(
            fn (?string $raw) => (new Layer)->setRawAttributes(['name' => $raw], true)->getStringName(),
            json_decode($row->layer_names, true) ?: []
        );

        return [
            implode(', ', array_filter($layerNames)),
            (new Layer)->setRawAttributes(['name' => $row->track_name], true)->getStringName(),
        ];
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
        $rows = $this->contextRows(withGeometry: false);
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
