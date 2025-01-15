<?php

namespace Wm\WmPackage\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;

class PBFGeneratorService extends BaseService
{
    protected $app_id;

    protected $author_id;

    protected $format;

    protected $zoomTreshold = 6;

    public function __construct(protected StorageService $cloudStorageService) {}

    public function generate($app_id, $z, $x, $y)
    {
        $boundingBox = $this->tileToBoundingBox(['zoom' => $z, 'x' => $x, 'y' => $y]);
        $sql = $this->generateSQL($boundingBox, $app_id, $z);
        $pbf = DB::select($sql);
        $path = false;
        $pbfContent = stream_get_contents($pbf[0]->st_asmvt) ?? null;
        if (! empty($pbfContent)) {
            $path = $this->cloudStorageService->storePBF($app_id, $z, $x, $y, $pbfContent);
        } else {
            $this->markTileAsEmpty($z, $x, $y);
        }

        return $path;
    }

    // Funzione per calcolare il fattore di semplificazione in base al livello di zoom
    private function getSimplificationFactor($zoom)
    {
        if ($zoom <= $this->zoomTreshold) {
            // Maggiore semplificazione per zoom <= 8
            return 4;  // Puoi regolare questo valore in base alle tue esigenze
        }

        return 0.1 / ($zoom + 1);  // Semplificazione inversamente proporzionale per altri zoom
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
            'ST_MakeEnvelope(%f, %f, %f, %f, 3857)',
            $boundingBox['xmin'],
            $boundingBox['ymin'],
            $boundingBox['xmax'],
            $boundingBox['ymax']
        );

        // Costruisci la query parametrizzata
        $sql = <<<SQL
            SELECT COUNT(DISTINCT ec.id) AS total_tracks
            FROM ec_tracks ec
            JOIN ec_track_layer etl ON ec.id = etl.ec_track_id
            WHERE etl.layer_id = ANY(:layer_ids) -- Usa un parametro per i layer
            AND ST_Intersects(
                ST_Transform(ec.geometry, 3857),
                {$boundingBoxSQL}
            )
            AND ST_Dimension(ec.geometry) = 1
            AND NOT ST_IsEmpty(ec.geometry)
            AND ST_IsValid(ec.geometry);
        SQL;

        $result = DB::select($sql, [
            'layer_ids' => '{' . implode(',', $layerIds) . '}', // Converti in array PostgreSQL
        ]);

        return $result[0]->total_tracks ?? 0;
    }

    public function tileToBoundingBox($tileCoordinates): array
    {
        $worldMercMax = 20037508.3427892;
        $worldMercMin = -$worldMercMax;
        $worldMercSize = $worldMercMax - $worldMercMin;
        $worldTileSize = 2 ** $tileCoordinates['zoom'];
        $tileMercSize = $worldMercSize / $worldTileSize;

        $env = [];
        $env['xmin'] = $worldMercMin + $tileMercSize * $tileCoordinates['x'];
        $env['xmax'] = $worldMercMin + $tileMercSize * ($tileCoordinates['x'] + 1);
        $env['ymin'] = $worldMercMax - $tileMercSize * ($tileCoordinates['y'] + 1);
        $env['ymax'] = $worldMercMax - $tileMercSize * $tileCoordinates['y'];

        return $env;
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
            throw new \Exception("App not found: {$app_id}");
        }
        $layerIds = $app->layers->pluck('id')->toArray();
        if (empty($layerIds)) {
            throw new \Exception("No layers associated with app: {$app_id}");
        }

        $simplificationFactor = $this->getSimplificationFactor($z);

        $boundingBoxSQL = sprintf(
            'ST_MakeEnvelope(%f, %f, %f, %f, 3857)',
            $boundingBox['xmin'],
            $boundingBox['ymin'],
            $boundingBox['xmax'],
            $boundingBox['ymax']
        );
        // Interpola gli ID dei layer
        $layerIdsSQL = implode(', ', $layerIds);

        // Genera la query SQL con gli ID dei layer incorporati
        return <<<SQL
    WITH 
    bounds AS (
        SELECT {$boundingBoxSQL} AS geom, {$boundingBoxSQL}::box2d AS b2d
    ),
    track_layers AS (
        SELECT 
            ST_Force2D(ec.geometry) AS geometry, -- Forza la geometria a 2D
            ec.id,
            ec.name,
            ec.ref,
            ec.cai_scale,
            JSON_AGG(DISTINCT etl.layer_id) AS layers,
            ec.activities -> '{$this->app_id}' AS activities, -- Usa $this->app_id per searchable
            ec.themes -> '{$this->app_id}' AS themes, -- Usa $this->app_id per themes
            ec.searchable -> '{$this->app_id}' AS searchable, -- Usa $this->app_id per searchable
            ec.color as stroke_color
        FROM ec_tracks ec
        JOIN ec_track_layer etl ON ec.id = etl.ec_track_id
        JOIN layers l ON etl.layer_id = l.id
        WHERE l.id IN ({$layerIdsSQL}) -- Filtra per i layer associati all'app
        GROUP BY ec.id, ec.geometry
    ),
    mvtgeom AS (
        SELECT 
            ST_AsMVTGeom(
                ST_SimplifyPreserveTopology(
                    ST_Transform(track_layers.geometry, 3857), 
                    $simplificationFactor
                ), 
                bounds.b2d
            ) AS geom,
            track_layers.id,
            track_layers.name,
            track_layers.ref,
            track_layers.cai_scale,
            track_layers.layers,
            track_layers.themes,
            track_layers.activities,
            track_layers.searchable,
            track_layers.stroke_color
        FROM track_layers
        CROSS JOIN bounds
        WHERE 
            ST_Intersects(
                ST_Transform(track_layers.geometry, 3857),
                bounds.geom
            )
            AND ST_Dimension(track_layers.geometry) = 1
            AND NOT ST_IsEmpty(track_layers.geometry)
            AND ST_IsValid(track_layers.geometry)
    )
    SELECT ST_AsMVT(mvtgeom.*, 'ec_tracks') FROM mvtgeom;
    SQL;
    }

    public function markTileAsEmpty($zoom, $x, $y)
    {
        $cacheKey = "empty_tile_{$this->app_id}_{$zoom}_{$x}_{$y}";
        Cache::put($cacheKey, true, now()->addHours(2));

        // Aggiorna la lista delle chiavi tracciate
        $trackedKeys = Cache::get('tiles_keys', []);
        if (! in_array($cacheKey, $trackedKeys)) {
            $trackedKeys[] = $cacheKey;
            Cache::put('tiles_keys', $trackedKeys, 3600); // Salva la lista aggiornata
        }
    }
}
