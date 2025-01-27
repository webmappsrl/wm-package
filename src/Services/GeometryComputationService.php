<?php

namespace Wm\WmPackage\Services;

use stdClass;
use Exception;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Illuminate\Support\Facades\DB;
use Symm\Gisconverter\Gisconverter;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Eloquent\Collection;
use Wm\WmPackage\Models\Abstracts\GeometryModel;

class GeometryComputationService extends BaseService
{
    public function get3dLineMergeWktFromGeojson(string $geojson): string
    {
        return DB::select(
            "SELECT ST_AsText(ST_Force3D(ST_LineMerge(ST_GeomFromGeoJSON('" . $geojson . "')))) As wkt"
        )[0]->wkt;
    }

    public function getGeometryModelCoordinates(GeometryModel $ecPoi): stdClass
    {
        $geom = $ecPoi->geometry;
        return DB::select("SELECT ST_X(ST_Transform('$geom'::geometry,4326)) as x,ST_Y(ST_Transform('$geom'::geometry,4326)) AS y")[0];
    }

    public function getWktFromGeojson(string $geojson): string
    {
        return DB::select("SELECT ST_GeomFromGeoJSON('" . $geojson . "') As wkt")[0]->wkt;
    }

    public function get3dGeometryFromGeojsonRAW(string $geojson): Expression
    {
        return DB::raw("(ST_Force3D(ST_GeomFromGeoJSON('" . $geojson . "')))");
    }

    public function get2dGeometryFromGeojsonRAW(string $geojson): Expression
    {
        return DB::raw("(ST_Force2D(ST_GeomFromGeoJSON('" . $geojson . "')))");
    }

    protected function getNeighoursByGeometryAndTable($geometry, $table): array
    {
        return DB::select(
            "SELECT id, St_Distance(geometry,?) as dist FROM {$table}
                WHERE St_DWithin(geometry, ?, " . config('wm-package.services.neighbours_distance') . ")
                order by St_Linelocatepoint(St_Geomfromgeojson(St_Asgeojson(?)),St_Geomfromgeojson(St_Asgeojson(geometry)));",
            [
                $geometry,
                $geometry,
                $geometry,
            ]
        );
    }

    public function getLineLocatePointFloat(string $trackGeojson, string $poiGeojson): float
    {
        // POI VAL along track https://postgis.net/docs/ST_LineLocatePoint.html
        $line = "ST_GeomFromGeoJSON('" . $trackGeojson . "')";
        $point = "ST_GeomFromGeoJSON('" . $poiGeojson . "')";
        $sql = DB::raw("SELECT ST_LineLocatePoint($line,$point) as val;");
        $result = DB::select($sql);

        return $result[0]->val;
    }

    public function getModelGeometryAsGeojson(GeometryModel $model): string
    {
        return $model::where('id', '=', $model->id)
            ->select(
                DB::raw('ST_AsGeoJSON(geometry) as geom')
            )
            ->first()
            ->geom;
    }

    public function getModelGeometryAsKml(GeometryModel $model): string
    {
        $geom = $this->getModelGeometryAsGeojson($model);
        if (isset($geom)) {
            $formattedGeometry = Gisconverter::geojsonToKml($geom);

            $name = '<name>' . ($this->name ?? '') . '</name>';

            return $name . $formattedGeometry;
        } else {
            return null;
        }
        // return $model::where('id', '=', $model->id)
        //     ->select(
        //         DB::raw('ST_AsKML(geometry) as geom')
        //     )
        //     ->first()
        //     ->geom;
    }

    public function getModelGeometryAsGpx(GeometryModel $model): string
    {
        $geom = $this->getModelGeometryAsGeojson($model);

        if (isset($geom)) {
            return Gisconverter::geojsonToGpx($geom);
        } else {
            return null;
        }
        // return $model::where('id', '=', $model->id)
        //     ->select(
        //         DB::raw('ST_AsGpx(geometry) as geom')
        //     )
        //     ->first()
        //     ->geom;
    }

    public function getGeometryModelBbox(GeometryModel $model): array
    {
        return $this->bbox(false, $model);
    }

    public function getEcTracksBboxByUserId(int $userId)
    {
        $query = '
            SELECT ST_Extent(geometry) as bbox
            FROM ec_tracks
            WHERE user_id = ?
        ';

        $result = DB::select($query, [$userId]);

        if (! empty($result)) {

            return $this->bboxArrayFromString($result[0]->bbox);
        }

        return null;
    }

    /**
     * Calculate the bounding box of the track
     */
    protected function bbox($geometry = false, GeometryModel|false $model = false): array
    {

        $bboxString = '';
        if ($geometry) {
            $b = DB::select('SELECT ST_Extent(?) as bbox', [$geometry]);
            if (! empty($b)) {
                $bboxString = $b[0]->bbox;
            }
        } else {
            $modelId = $model->id;
            $rawResult = $model::where('id', $modelId)->selectRaw('ST_Extent(geometry) as bbox')->first();
            $bboxString = $rawResult['bbox'];
        }

        return $this->bboxArrayFromString($bboxString);
    }

    private function bboxArrayFromString(string $bboxString): array
    {
        return array_map('floatval', explode(' ', str_replace(',', ' ', str_replace(['B', 'O', 'X', '(', ')'], '', $bboxString))));
    }

    /**
     * Calculate the centroid of the ec track
     *
     * @return array [lon, lat] of the point
     */
    public function getCentroid(GeometryModel $model): array
    {
        $rawResult = $model::where('id', $model->id)
            ->selectRaw(
                'ST_X(ST_AsText(ST_Centroid(geometry))) as lon'
            )
            ->selectRaw(
                'ST_Y(ST_AsText(ST_Centroid(geometry))) as lat'
            )->first();

        return [floatval($rawResult['lon']), floatval($rawResult['lat'])];
    }

    public function getNeighboursGeojson(GeometryModel $model, string $neighboursModelClass): array
    {
        $neighboursModel = new $neighboursModelClass;
        $features = [];
        try {
            $result = $this->getNeighoursByGeometryAndTable($model->geometry, $neighboursModel->getTable());
        } catch (Exception $e) {
            $result = [];
        }
        $ids = collect($result)->pluck('id')->toArray();
        $features = [];
        $neighboursModel->whereIn('id', $ids)->get()->each(function ($neighbour) use (&$features) {
            $geojson = $neighbour->getGeojson();
            if (isset($geojson)) {
                $features[] = $geojson;
            }
        });

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
        ];
    }

    /**
     * Return a feature collection with the related UGC features
     */
    public function getRelatedUgcGeojson($model): array
    {
        $classes = ['App\Models\UgcPoi' => 'ugc_pois', 'App\Models\UgcTrack' => 'ugc_tracks', 'App\Models\UgcMedia' => 'ugc_media'];
        $modelType = get_class($model);
        $features = [];

        unset($classes[$modelType]);

        foreach ($classes as $class => $table) {
            $result = DB::select(
                'SELECT id FROM '
                    . $table
                    . ' WHERE user_id = ?'
                    . " AND ABS(EXTRACT(EPOCH FROM created_at) - EXTRACT(EPOCH FROM TIMESTAMP '"
                    . $model->created_at
                    . "')) < 5400"
                    . ' AND St_DWithin(geometry, ?, 400);',
                [
                    $model->user_id,
                    $model->geometry,
                ]
            );
            foreach ($result as $row) {
                $geojson = $class::find($row->id)->getGeojson();
                if (isset($geojson)) {
                    $features[] = $geojson;
                }
            }
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
        ];
    }

    public function gpxToGeojson(string $gpx): string
    {
        return Gisconverter::gpxToGeojson($gpx);

        // return DB::select(
        //     "SELECT ST_AsGeoJSON(ST_GeomFromGPX('" . $gpx . "')) As geojson"
        // )[0]->geojson;
    }

    public function kmlToGeojson(string $kml): string
    {
        return Gisconverter::kmlToGeojson($kml);

        // return DB::select(
        //     "SELECT ST_AsGeoJSON(ST_GeomFromKML('" . $kml . "')) As geojson"
        // )[0]->geojson;
    }

    /**
     * @param string json encoded geometry.
     */
    public function fileToGeometry($fileContent = '')
    {
        $geometry = $contentType = null;
        if ($fileContent) {
            if (substr($fileContent, 0, 5) == '<?xml') {
                $geojson = '';
                if ($geojson === '') {

                    $geojson = $this->gpxToGeojson($fileContent);
                    $content = json_decode($geojson);
                    $contentType = @$content->type;
                }

                if ($geojson === '') {
                    $geojson = $this->kmlToGeojson($fileContent);
                    $content = json_decode($geojson);
                    $contentType = @$content->type;
                }
            } else {
                $content = json_decode($fileContent);
                $isJson = json_last_error() === JSON_ERROR_NONE;
                if ($isJson) {
                    $contentType = $content->type;
                }
            }

            if ($contentType) {
                switch ($contentType) {
                    case 'FeatureCollection':
                        $contentGeometry = $content->features[0]->geometry;
                        break;
                    case 'LineString':
                        $contentGeometry = $content;
                        break;
                    default:
                        $contentGeometry = $content->geometry;
                        break;
                }
                $geometry = $this->get3dGeometryFromGeojsonRAW(json_encode($contentGeometry));
            }
        }

        return $geometry;
    }

    public function isRoundtrip(array $coords): bool
    {
        $treshold = 0.001; // diff < 300 metri ref trackid:1592
        $len = count($coords);
        $firstCoord = $coords[0];
        $lastCoord = $coords[$len - 1];
        $firstX = $firstCoord[0];
        $lastX = $lastCoord[0];
        $firstY = $firstCoord[1];
        $lastY = $lastCoord[1];

        return (abs($lastX - $firstX) < $treshold) && (abs($lastY - $firstY) < $treshold);
    }

    /**
     * Converts lat/lon degrees to x/y coordinates with the provided zoom level
     *
     * @param [type] $lat_deg
     * @param [type] $lon_deg
     * @param [type] $zoom
     * @return void
     */
    private function deg2num($lat_deg, $lon_deg, $zoom)
    {
        $lat_rad = deg2rad($lat_deg);
        $n = pow(2, $zoom);
        $xtile = intval(($lon_deg + 180.0) / 360.0 * $n);
        $ytile = intval((1.0 - log(tan($lat_rad) + (1 / cos($lat_rad))) / pi()) / 2.0 * $n);

        return [$xtile, $ytile];
    }

    public function getModelIntersections(GeometryModel $model, string $targetModelClass): Collection
    {
        return $targetModelClass::whereRaw(
            'public.ST_Intersects('
                . 'public.ST_Force2D('
                . "(SELECT geometry from {$model->getTable()} where id = {$model->id})"
                . '::geometry)'
                . ', geometry)'
        )->get();
    }

    public function generateTiles($bbox, $zoom)
    {
        [$minLon, $minLat, $maxLon, $maxLat] = $bbox;
        [$minTileX, $minTileY] = $this->deg2num($maxLat, $minLon, $zoom);
        [$maxTileX, $maxTileY] = $this->deg2num($minLat, $maxLon, $zoom);

        $tiles = [];
        for ($x = $minTileX; $x <= $maxTileX; $x++) {
            for ($y = $minTileY; $y <= $maxTileY; $y++) {
                $tiles[] = [$x, $y, $zoom];
            }
        }

        return $tiles;
    }

    /**
     * From geomixer
     */
    public function calculateSlopeValues(array $geometry): ?array
    {
        if (
            ! isset($geometry['type'])
            || ! isset($geometry['coordinates'])
            || $geometry['type'] !== 'LineString'
            || ! is_array($geometry['coordinates'])
            || count($geometry['coordinates']) === 0
        ) {
            return null;
        }

        $values = [];
        foreach ($geometry['coordinates'] as $key => $coordinate) {
            $firstPoint = $coordinate;
            $lastPoint = $coordinate;
            if ($key < count($geometry['coordinates']) - 1) {
                $lastPoint = $geometry['coordinates'][$key + 1];
            }

            if ($key > 0) {
                $firstPoint = $geometry['coordinates'][$key - 1];
            }

            $deltaY = $lastPoint[2] - $firstPoint[2];
            $deltaX = $this->getDistanceComp(['type' => 'LineString', 'coordinates' => [$firstPoint, $lastPoint]]) * 1000;

            $values[] = $deltaX > 0 ? round($deltaY / $deltaX * 100, 1) : 0;
        }

        if (count($values) !== count($geometry['coordinates'])) {
            return null;
        }

        return $values;
    }

    /**
     * Calculate the distance comp from geometry in KM
     *
     * @param  array  $geometry  the ecTrack geometry
     * @return float the distance comp in KMs
     */
    public function getDistanceComp(array $geometry): float
    {
        $distanceQuery = "SELECT ST_Length(ST_GeomFromGeoJSON('" . json_encode($geometry) . "')::geography)/1000 as length";
        $distance = DB::select(DB::raw($distanceQuery));

        return $distance[0]->length;
    }
}
