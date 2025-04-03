<?php

namespace Wm\WmPackage\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;

class PBFGeneratorService extends BaseService
{
    protected $app_id;

    protected $author_id;

    protected $format;

    public function __construct(protected StorageService $cloudStorageService, protected GeometryComputationService $geometryComputationService) {}

    public function getZoomTreshold(): int
    {
        return config('wm-package.services.pbf.zoom_treshold', 6);
    }

    public function generate($app_id, $z, $x, $y)
    {
        $boundingBox = $this->geometryComputationService->tileToBoundingBox(['zoom' => $z, 'x' => $x, 'y' => $y]);

        $sql = $this->generateSQL($boundingBox, $app_id, $z);
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

        // Costruisci la query parametrizzata
        $sql = <<<SQL
            SELECT COUNT(DISTINCT ec.id) AS total_tracks
            FROM ec_tracks ec
            JOIN layerable etl ON ec.id = etl.layerable_id AND etl.layerable_type LIKE '%EcTrack'
            WHERE etl.layer_id = ANY(:layer_ids) -- Usa un parametro per i layer
            AND ST_Intersects(
                ec.geometry,
                {$boundingBoxSQL}
            )
            AND ST_Dimension(ec.geometry) = 1
            AND NOT ST_IsEmpty(ec.geometry)
            AND ST_IsValid(ec.geometry);
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
            throw new \Exception("App not found: {$app_id}");
        }
        // $layerIds = $app->layers->pluck('id')->toArray();
        if ($app->layers->count() === 0) {
            throw new \Exception("No layers associated with app: {$app_id}");
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

        // TODO: add activities and wheres to match layers of the tracks
        $sql = <<<SQL
    WITH 
    bounds AS (
        SELECT {$boundingBoxSQL} AS geom, {$boundingBoxSQL}::box2d AS b2d
    ),
    ecTracks AS (
        SELECT 
            ST_AsMVTGeom(
                ST_SimplifyPreserveTopology(
                    ST_Transform(ec.geometry::geometry,3857), --indexed
                    $simplificationFactor
                ), 
                bounds.b2d
            ) AS geom,
            ec.id,
            ec.name,
            ec.properties ->> 'ref' as ref,
            ec.properties ->> 'cai_scale' as cai_scale,
            ec.properties ->> 'distance' as distance,
            ec.properties ->> 'duration_forward' as duration_forward,
            ec.properties -> 'layers' AS layers, -- jsonb
            ec.properties ->> 'color' as stroke_color
        FROM ec_tracks ec
        CROSS JOIN bounds
        WHERE 
            ec.app_id = $app_id -- Filtra per i layer associati all'app
            AND ST_Intersects(
                ST_Transform(ec.geometry::geometry,3857), --indexed
                bounds.geom
            )
            AND ST_Dimension(ST_Transform(ec.geometry::geometry,3857)) = 1
    )
    SELECT ST_AsMVT(ecTracks.*, 'ec_tracks') FROM ecTracks;
    SQL;

        // Log::info($sql);
        return $sql;
    }
}
