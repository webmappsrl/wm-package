<?php

namespace Wm\WmPackage\Services;

use Exception;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Jobs\Pbf\GenerateOptimizedPBFChainJob;
use Wm\WmPackage\Jobs\Pbf\GeneratePBFByZoomJob;
use Wm\WmPackage\Jobs\Pbf\RegeneratePBFForTrackJob;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Models\EcTrackService;

class PBFGeneratorService extends BaseService
{
    protected $app_id;

    protected $author_id;

    protected $format;

    protected StorageService $cloudStorageService;

    protected GeometryComputationService $geometryComputationService;

    protected EcTrackService $ecTrackService;

    public function __construct(
        StorageService $cloudStorageService,
        GeometryComputationService $geometryComputationService,
        EcTrackService $ecTrackService
    ) {
        $this->cloudStorageService = $cloudStorageService;
        $this->geometryComputationService = $geometryComputationService;
        $this->ecTrackService = $ecTrackService;
    }

    public function getZoomTreshold(): int
    {
        return config('wm-package.services.pbf.zoom_treshold', 6);
    }

    /**
     * Ottiene il nome della classe del modello delle tracce dalla configurazione
     *
     * @return string Nome della classe (es. 'EcTrack', 'HikingRoute')
     */
    private function getTrackModelClassName(): string
    {
        $modelClass = config('wm-package.ec_track_model', 'App\Models\EcTrack');

        return class_basename($modelClass);
    }

    public function generate($app_id, $z, $x, $y, $isLayerJob = false)
    {
        $boundingBox = $this->geometryComputationService->tileToBoundingBox(['zoom' => $z, 'x' => $x, 'y' => $y]);

        // Scegli la query appropriata in base al tipo di job
        if ($isLayerJob) {
            $sql = $this->generateLayerSQL($boundingBox, $app_id, $z);
        } else {
            $sql = $this->generateSQL($boundingBox, $app_id, $z);
        }

        $pbf = DB::select($sql);
        $path = false;
        $pbfContent = stream_get_contents($pbf[0]->st_asmvt) ?? null;

        if (! empty($pbfContent)) {
            $path = $this->cloudStorageService->storePBF($app_id, $z, $x, $y, $pbfContent);
        } else {
            $this->geometryComputationService->markTileAsEmpty($z, $x, $y, $app_id);
        }

        return $path;
    }

    // ///////////////////////////// TRACKPBFJOB

    protected function countTracks($boundingBox): int
    {
        // Recupera l'app con i layer associati
        $app = App::with('layers')->find($this->app_id);
        if (! $app) {
            return 0; // Nessun layer associato, nessuna traccia
        }

        // Ottieni gli ID dei layer associati all'app
        $layerIds = $app->layers->pluck('id')->toArray();
        // Se non ci sono layer, ritorna 0
        if (empty($layerIds)) {
            return 0;
        }
        $boundingBoxSQL = sprintf(
            'ST_MakeEnvelope(%f, %f, %f, %f,4326)',
            $boundingBox['xmin'],
            $boundingBox['ymin'],
            $boundingBox['xmax'],
            $boundingBox['ymax']
        );

        // Recupera il nome della tabella dal modello
        $tableName = config('wm-package.ec_track_table');

        // Costruisci la query parametrizzata
        $sql = <<<SQL
            SELECT COUNT(DISTINCT ec.id) AS total_tracks
            FROM {$tableName} ec
            JOIN layerable etl ON ec.id = etl.layerable_id AND etl.layerable_type LIKE '%EcTrack'
            WHERE etl.layer_id = ANY(:layer_ids) -- Usa un parametro per i layer
            AND ST_Intersects(
                ec.geometry,
                {$boundingBoxSQL}
            )
            AND ST_Dimension(ec.geometry::geometry) = 1
            AND NOT ST_IsEmpty(ec.geometry::geometry)
            AND ST_IsValid(ec.geometry::geometry);
        SQL;

        $result = DB::select($sql, [
            'layer_ids' => '{'.implode(',', $layerIds).'}', // Converti in array PostgreSQL
        ]);

        return $result[0]->total_tracks ?? 0;
    }

    protected function getAssociatedLayerMap(): array
    {
        // Ottieni l'app con i layer associati
        $app = App::with('layers')->find($this->app_id);
        if (! $app) {
            return [];
        }

        $layerIds = $app->layers->pluck('id')->toArray();

        // Ottieni le tracce con i layer associati che appartengono ai layer dell'app
        $tracks = EcTrack::with('associatedLayers')
            ->whereHas('associatedLayers', function ($query) use ($layerIds) {
                $query->whereIn('layers.id', $layerIds); // Filtra i layer specifici
            })
            ->get();

        // Costruisci la mappa (ec_track_id => [layer_id1, layer_id2, ...])
        $map = [];
        foreach ($tracks as $track) {
            $map[$track->id] = $track->associatedLayers->pluck('id')->toArray(); // Usa l'attributo personalizzato layers
        }

        return $map;
    }

    protected function generateSQL($boundingBox, $app_id, $z): string
    {
        // Recupera l'app con i layer associati
        $app = App::with('layers')->find($app_id);
        if (! $app) {
            throw new Exception("App not found: {$app_id}");
        }
        // $layerIds = $app->layers->pluck('id')->toArray();
        if ($app->layers->count() === 0) {
            throw new Exception("No layers associated with app: {$app_id}");
        }

        // simplifies geometry by a factor of 4 for zoom levels <= 8
        $simplificationFactor = $this->geometryComputationService->getSimplificationFactor($z, $this->getZoomTreshold());

        $boundingBoxSQL = sprintf(
            'ST_MakeEnvelope(%f, %f, %f, %f, 3857)',
            $boundingBox['xmin'],
            $boundingBox['ymin'],
            $boundingBox['xmax'],
            $boundingBox['ymax']
        );
        // // Interpola gli ID dei layer
        // $layerIdsSQL = implode(', ', $layerIds);

        // Recupera il nome della tabella dal modello
        $tableName = config('wm-package.ec_track_table');

        // TODO: add wheres to match layers of the tracks
        $sql = <<<SQL
    WITH 
    bounds AS (
        SELECT {$boundingBoxSQL} AS geom, {$boundingBoxSQL}::box2d AS b2d
    ),
            validGeometries AS (
        SELECT
            ec.id,
            ec.name,
            ec.properties,
            ST_Force2D(ST_Transform(ec.geometry::geometry,3857)) as geom_mercator
        FROM {$tableName} ec
        CROSS JOIN bounds
        WHERE 
            ec.app_id = $app_id -- Filtra per i layer associati all'app
            AND ec.geometry IS NOT NULL -- Filtra geometrie NULL
            AND ST_IsValid(ec.geometry::geometry) -- Filtra geometrie non valide
            AND ST_Intersects(
                ST_Transform(ec.geometry::geometry,3857), --indexed
                bounds.geom
            )
    ),
    processedGeometries AS (
        SELECT
            id,
            properties,
            name,
            ST_SimplifyPreserveTopology(geom_mercator, $simplificationFactor) as simplified_geom
        FROM validGeometries
    ),
    trackLayers AS (
        SELECT
            layerable_id,
            ARRAY_TO_JSON(ARRAY_AGG(layer_id))::text AS layers
        FROM layerables
        WHERE layerable_type LIKE '%{$this->getTrackModelClassName()}'
        GROUP BY layerable_id
    ),
    trackActivities AS (
        SELECT
            ta.taxonomy_activityable_id AS track_id,
            jsonb_agg(
                DISTINCT label
                ORDER BY label
            ) FILTER (WHERE label IS NOT NULL) AS activities
        FROM taxonomy_activityables ta
        JOIN taxonomy_activities tact ON tact.id = ta.taxonomy_activity_id
        CROSS JOIN LATERAL (
            SELECT NULLIF(btrim(tact.identifier), '') AS label
        ) lbl
        WHERE ta.taxonomy_activityable_type LIKE '%{$this->getTrackModelClassName()}'
        GROUP BY ta.taxonomy_activityable_id
    ),
    ecTracks AS (
        SELECT
            ST_AsMVTGeom(pg.simplified_geom, bounds.b2d) AS geom,
            pg.id,
            pg.name,
            pg.properties ->> 'ref' as ref,
            pg.properties ->> 'cai_scale' as cai_scale,
            COALESCE(
                NULLIF(pg.properties -> 'manual_data' ->> 'distance', '')::double precision,
                NULLIF(pg.properties -> 'osm_data' ->> 'distance', '')::double precision,
                NULLIF(pg.properties -> 'dem_data' ->> 'distance', '')::double precision
            ) as distance,
            COALESCE(
                NULLIF(pg.properties -> 'manual_data' ->> 'duration_forward', '')::integer,
                NULLIF(pg.properties -> 'osm_data' ->> 'duration_forward', '')::integer,
                NULLIF(pg.properties -> 'dem_data' ->> 'duration_forward', '')::integer
            ) as duration_forward,
            ta.activities::text as activities,
            tl.layers,
            pg.properties ->> 'searchable' as searchable,
            pg.properties ->> 'color' as stroke_color
        FROM processedGeometries pg
        LEFT JOIN trackLayers tl ON tl.layerable_id = pg.id
        LEFT JOIN trackActivities ta ON ta.track_id = pg.id
        CROSS JOIN bounds
    )
    SELECT ST_AsMVT(ecTracks.*, '{$tableName}') FROM ecTracks
    WHERE EXISTS (SELECT 1 FROM ecTracks WHERE geom IS NOT NULL AND ST_IsValid(geom)); -- Controllo finale che ci siano geometrie valide
    SQL;

        // Log::info($sql);
        return $sql;
    }

    protected function generateLayerSQL($boundingBox, $app_id, $z): string
    {
        // Recupera l'app con i layer associati
        $app = App::with('layers')->find($app_id);
        if (! $app) {
            throw new Exception("App not found: {$app_id}");
        }

        $layerIds = $app->layers->pluck('id')->toArray();
        if (empty($layerIds)) {
            throw new Exception("No layers associated with app: {$app_id}");
        }
        // Genera l'elenco degli ID layer come stringa SQL
        $layerIdsSQL = implode(', ', $layerIds);

        $tbl = [
            'srid' => '4326',
            'geomColumn' => 'geometry',
            'attrColumns' => 'JSON_BUILD_ARRAY(l.id) AS layers,                -- Usa ARRAY per garantire un array anche con un solo elemento
                 l.properties ->> \'color\' AS stroke_color',
        ];

        // Trasforma il bounding box in una stringa SQL valida
        $boundingBoxSQL = sprintf(
            'ST_MakeEnvelope(%f, %f, %f, %f, 3857)',
            $boundingBox['xmin'],
            $boundingBox['ymin'],
            $boundingBox['xmax'],
            $boundingBox['ymax']
        );

        // Recupera il nome della tabella dal modello
        $tableName = config('wm-package.ec_track_table');

        return <<<SQL
        WITH 
        bounds AS (
            SELECT {$boundingBoxSQL} AS geom, {$boundingBoxSQL}::box2d AS b2d ),
        mvtgeom AS (
            SELECT 
                ST_AsMVTGeom(
                    ST_SimplifyPreserveTopology(
                        ST_Force2D(ST_Transform(ec.{$tbl['geomColumn']}::geometry, 3857)), 4
                    ), 
                    bounds.b2d
                ) AS geom,
                {$tbl['attrColumns']}
            FROM layers l
            JOIN layerables etl ON l.id = etl.layer_id
            JOIN {$tableName} ec ON etl.layerable_id = ec.id
            CROSS JOIN bounds
            WHERE l.id IN ({$layerIdsSQL}) -- Filtra per i layer associati all'app
                AND etl.layerable_type LIKE '%{$this->getTrackModelClassName()}'
                AND ec.app_id = $app_id -- Filtra per app_id
                AND 
                ST_Intersects(
                    ST_Force2D(ST_Transform(ec.{$tbl['geomColumn']}::geometry, 3857)),
                    bounds.geom
                )
                AND ST_IsValid(ec.{$tbl['geomColumn']}::geometry) 
                AND ST_Dimension(ec.{$tbl['geomColumn']}::geometry) > 0
                AND NOT ST_IsEmpty(ec.{$tbl['geomColumn']}::geometry)
                AND ec.{$tbl['geomColumn']} IS NOT NULL
        )
        SELECT ST_AsMVT(mvtgeom.*, 'layers') FROM mvtgeom;
        SQL;
    }

    public function generateWholeAppPbfs(App $app, $minZoom = null, $maxZoom = null, $pbfLayer = null)
    {
        $pbfLayer = $pbfLayer ?? (bool) config('wm-package.services.pbf.pbf_layer', false);
        $bbox = GeometryComputationService::make()->getTracksBboxFromQuery($app->ecTracks());
        if (empty($bbox)) {
            $bbox = json_decode($app->map_bbox);
        }
        if (empty($bbox)) {
            throw new Exception('This app does not have bounding box! Please add bbox. (e.g. [10.39637,43.71683,10.52729,43.84512])');

            return;
        }

        $minZoom = $minZoom ?? config('wm-package.services.pbf.min_zoom');
        $maxZoom = $maxZoom ?? config('wm-package.services.pbf.max_zoom');

        $chain = [];
        for ($zoom = $minZoom; $zoom <= $maxZoom; $zoom++) {
            $chain[] = new GeneratePBFByZoomJob($bbox, $zoom, $app->id, $pbfLayer);
        }
        Bus::chain($chain)->onConnection('redis')->onQueue('pbf')->dispatch();
    }

    /**
     * Genera i PBF per tutta l'app usando l'approccio bottom-up ottimizzato
     * Parte dal zoom più alto e risale la piramide dei tile
     *
     * @param  App  $app  L'app per cui generare i PBF
     * @param  int|null  $minZoom  Zoom minimo (default: dalla config)
     * @param  int|null  $maxZoom  Zoom massimo (default: dalla config)
     * @param  bool  $noPbfLayer  Se non generare layer PBF
     * @param  float  $maxClusterDistance  Distanza massima per clustering (metri, default: 10km)
     * @return void
     *
     * @throws Exception Se l'app non ha tracce
     */
    public function generateWholeAppPbfsOptimized(
        App $app,
        $minZoom = null,
        $maxZoom = null,
        $pbfLayer = null
    ) {
        $pbfLayer = $pbfLayer ?? (bool) config('wm-package.services.pbf.pbf_layer', false);
        // Verifica che l'app abbia tracce
        $trackCount = $app->ecTracks()->count();
        if ($trackCount === 0) {
            throw new Exception("App {$app->id} non ha tracce associate!");
        }

        $minZoom = $minZoom ?? config('wm-package.services.pbf.min_zoom');
        $maxZoom = $maxZoom ?? config('wm-package.services.pbf.max_zoom');

        Log::info('Avvio generazione PBF ottimizzata per app', [
            'app_id' => $app->id,
            'app_name' => $app->name,
            'track_count' => $trackCount,
            'min_zoom' => $minZoom,
            'max_zoom' => $maxZoom,
        ]);

        // Ottieni tutte le tracce dell'app
        $trackIds = $this->getAllTrackIds($app->id);

        if (empty($trackIds)) {
            throw new Exception("App {$app->id} non ha tracce valide!");
        }

        // Dispatch del job chain che gestisce l'intero processo bottom-up
        GenerateOptimizedPBFChainJob::dispatch(
            $app->id,
            $maxZoom, // startZoom (dal più alto)
            $minZoom, // minZoom
            $pbfLayer,
            $trackIds // Passa le track IDs già recuperate
        )->onConnection('redis')->onQueue('pbf');

        Log::info('Job chain di generazione PBF ottimizzata avviato', [
            'app_id' => $app->id,
            'zoom_range' => "{$maxZoom} → {$minZoom} (bottom-up)",
        ]);
    }

    /**
     * Genera i tile PBF solo per una traccia specifica modificata
     * Ottimizzato per rigenerare solo i tile impattati dalla modifica
     *
     * @param  GeometryModel  $track  Il modello della traccia modificata
     * @param  int|null  $startZoom  Zoom di partenza (default: max_zoom dalla config)
     * @param  int|null  $minZoom  Zoom minimo (default: min_zoom dalla config)
     * @return void
     *
     * @throws Exception Se la traccia non ha un bounding box valido
     */
    public function generatePbfsForTrack($track, $startZoom = null, $minZoom = null)
    {
        // Verifica che la traccia abbia una geometria valida
        if (empty($track->geometry)) {
            throw new Exception('Track does not have a valid geometry!');
        }

        // Ottieni i livelli di zoom dalla configurazione se non specificati
        $startZoom = $startZoom ?? config('wm-package.services.pbf.max_zoom');
        $minZoom = $minZoom ?? config('wm-package.services.pbf.min_zoom');

        // Verifica che l'app sia associata alla traccia
        if (empty($track->app_id)) {
            throw new Exception('Track does not have an associated app_id!');
        }

        // Dispatch del job ottimizzato per la singola traccia
        RegeneratePBFForTrackJob::dispatchForTrack(
            $track,
            $startZoom,
            $minZoom,
            $track->app_id
        );
    }

    /**
     * Rigenera i PBF per un layer dopo la modifica della composizione
     *
     * @param  Layer  $layer  Il layer per cui rigenerare i PBF
     * @param  int|null  $minZoom  Zoom minimo (default: dalla config)
     * @param  int|null  $maxZoom  Zoom massimo (default: dalla config)
     */
    public function regeneratePbfsForLayer(Layer $layer, $minZoom = null, $maxZoom = null): void
    {
        try {
            $trackIds = $layer->layerables()
                ->where('layerable_type', config('wm-package.ec_track_model', 'App\Models\EcTrack'))
                ->pluck('layerable_id')
                ->toArray();
            if (empty($trackIds)) {
                throw new Exception('No track IDs found for layer: '.$layer->id);
            }
        } catch (Exception $e) {
            Log::warning('Fallback a generateWholeAppPbfs per errore nella rigenerazione multipla dopo sync', [
                'layer_id' => $layer->id,
                'error' => $e->getMessage(),
            ]);
            $tableName = config('wm-package.ec_track_table', 'ec_tracks');
            $trackIds = $layer->ecTracks()->pluck("{$tableName}.id")->toArray();
        }
        try {
            $minZoom = $minZoom ?? config('wm-package.services.pbf.min_zoom');
            $maxZoom = $maxZoom ?? config('wm-package.services.pbf.max_zoom');

            if (! empty($trackIds)) {
                GenerateOptimizedPBFChainJob::dispatch(
                    $layer->app->id,
                    $maxZoom,
                    $minZoom,
                    false,
                    $trackIds
                )->onConnection('redis')->onQueue('pbf');

                Log::info('PBF rigenerati per tracce multiple del layer', [
                    'layer_id' => $layer->id,
                    'track_count' => count($trackIds),
                    'app_id' => $layer->app->id,
                ]);
            } else {
                // Se non ci sono tracce, usa il metodo originale
                $this->generateWholeAppPbfsOptimized($layer->app, $minZoom, $maxZoom);

                Log::info('PBF rigenerati per app completa dopo sync (nessuna traccia nel layer)', [
                    'layer_id' => $layer->id,
                    'app_id' => $layer->app->id,
                ]);
            }
        } catch (Exception $e) {
            Log::warning('Fallback a generateWholeAppPbfs per errore nella rigenerazione multipla dopo sync', [
                'layer_id' => $layer->id,
                'error' => $e->getMessage(),
            ]);
            // Fallback al metodo originale
            $this->generateWholeAppPbfs($layer->app, $minZoom, $maxZoom);
        }
    }

    /**
     * Ottieni tutti gli ID delle tracce dell'app
     *
     * @param  int  $app_id  ID dell'app
     * @return array Array di track IDs
     */
    public function getAllTrackIds(int $app_id): array
    {
        $tableName = config('wm-package.ec_track_table');

        // Query per ottenere tutte le tracce valide dell'app
        $sql = "
            SELECT id
            FROM {$tableName}
            WHERE app_id = ?
            AND geometry IS NOT NULL
            AND ST_IsValid(geometry::geometry)
            AND ST_Dimension(geometry::geometry) = 1
            AND NOT ST_IsEmpty(geometry::geometry)
            AND ST_GeometryType(geometry::geometry) IN ('ST_LineString', 'ST_MultiLineString')
            ORDER BY id
        ";

        $results = DB::select($sql, [$app_id]);

        return array_column($results, 'id');
    }

    /**
     * Calcola i tile che contengono le geometrie delle tracce usando PostGIS
     *
     * @param  array  $trackIds  Array di track IDs
     * @param  int  $zoom  Livello di zoom
     * @return array Array di tile [x, y, zoom]
     */
    private function calculateTilesFromGeometries(array $trackIds, int $zoom): array
    {
        if (empty($trackIds)) {
            return [];
        }

        $tableName = config('wm-package.ec_track_table');

        // Query per ottenere le coordinate delle geometrie (approccio sicuro)
        $sql = "
            SELECT 
                id,
                ST_X(ST_PointOnSurface(ST_Transform(geometry::geometry, 4326))) as lon,
                ST_Y(ST_PointOnSurface(ST_Transform(geometry::geometry, 4326))) as lat
            FROM {$tableName}
            WHERE id = ANY(:track_ids)
            AND geometry IS NOT NULL
            AND ST_IsValid(geometry::geometry)
            AND ST_Dimension(geometry::geometry) = 1
            AND NOT ST_IsEmpty(geometry::geometry)
            AND ST_GeometryType(geometry::geometry) IN ('ST_LineString', 'ST_MultiLineString')
        ";

        $results = DB::select($sql, [
            'track_ids' => '{'.implode(',', $trackIds).'}',
        ]);

        $tiles = [];
        $seen = [];

        foreach ($results as $result) {
            // Calcola le coordinate tile dalle coordinate geografiche
            $tileCoords = $this->deg2num($result->lat, $result->lon, $zoom);
            $tileX = $tileCoords[0];
            $tileY = $tileCoords[1];

            $key = "{$zoom}_{$tileX}_{$tileY}";

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $tiles[] = [$tileX, $tileY, $zoom];
            }
        }

        return $tiles;
    }

    /**
     * Converte coordinate geografiche in coordinate tile
     *
     * @param  float  $lat_deg  Latitudine in gradi
     * @param  float  $lon_deg  Longitudine in gradi
     * @param  int  $zoom  Livello di zoom
     * @return array [x, y] coordinate tile
     */
    private function deg2num($lat_deg, $lon_deg, $zoom)
    {
        $lat_rad = deg2rad($lat_deg);
        $n = pow(2, $zoom);
        $xtile = floor(($lon_deg + 180.0) / 360.0 * $n);
        $ytile = floor((1.0 - asinh(tan($lat_rad)) / M_PI) / 2.0 * $n);

        return [$xtile, $ytile];
    }

    /**
     * Genera i tile ottimizzati per un determinato zoom usando approccio bottom-up
     *
     * @param  array  $trackIds  Array di track IDs
     * @param  int  $zoom  Livello di zoom
     * @param  float  $maxClusterDistance  Distanza massima per clustering (non usato in questo approccio)
     * @return array Array di tile [x, y, zoom]
     */
    public function generateOptimizedTilesForZoom(array $trackIds, int $zoom): array
    {
        if (empty($trackIds)) {
            return [];
        }

        // Per zoom alti (>= 10), usa approccio bottom-up dalle geometrie
        if ($zoom >= 10) {
            return $this->generateTilesBottomUp($trackIds, $zoom);
        } else {
            // Per zoom bassi, usa approccio tradizionale ma ottimizzato
            return $this->generateTilesWithoutClustering($trackIds, $zoom);
        }
    }

    /**
     * Genera tile usando approccio bottom-up dalle geometrie
     * Parte dalle geometrie delle tracce e calcola i quadranti che le contengono
     *
     * @param  array  $trackIds  Array di track IDs
     * @param  int  $zoom  Livello di zoom di partenza
     * @return array Array di tile [x, y, zoom]
     */
    private function generateTilesBottomUp(array $trackIds, int $zoom): array
    {
        Log::info("Avvio generazione bottom-up per zoom {$zoom}", [
            'total_tracks' => count($trackIds),
            'zoom' => $zoom,
        ]);

        // 1. Calcola i tile di livello zoom che contengono le geometrie delle tracce
        $tilesAtZoom = $this->calculateTilesFromGeometries($trackIds, $zoom);

        Log::info('Calcolati '.count($tilesAtZoom)." tile al livello zoom {$zoom}", [
            'zoom' => $zoom,
            'tiles_count' => count($tilesAtZoom),
        ]);

        return $tilesAtZoom;
    }

    /**
     * Genera tile senza clustering (per zoom bassi)
     */
    private function generateTilesWithoutClustering(array $trackIds, int $zoom): array
    {
        $tableName = config('wm-package.ec_track_table');

        // Calcola il bounding box complessivo delle tracce
        $res = DB::select("
            SELECT ST_Extent(ST_Transform(geometry::geometry, 4326)) as bbox
            FROM {$tableName}
            WHERE id = ANY(:track_ids)
            AND geometry IS NOT NULL
            AND ST_IsValid(geometry::geometry)
        ", [
            'track_ids' => '{'.implode(',', $trackIds).'}',
        ]);

        if (empty($res) || is_null($res[0]->bbox)) {
            return [];
        }

        // Estrai le coordinate del bounding box
        preg_match('/BOX\(([-\d\.]+) ([-\d\.]+),([-\d\.]+) ([-\d\.]+)\)/', $res[0]->bbox, $matches);
        $bbox = [
            (float) $matches[1], // minLon
            (float) $matches[2], // minLat
            (float) $matches[3], // maxLon
            (float) $matches[4],  // maxLat
        ];

        // Genera i tile per questo bounding box
        $geometryService = new GeometryComputationService;

        return $geometryService->generateTiles($bbox, $zoom, $this->getZoomTreshold(), $this->app_id);
    }
}
