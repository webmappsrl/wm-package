<?php

namespace Wm\WmPackage\Services;

use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use stdClass;
use Symm\Gisconverter\Exceptions\InvalidText;
use Symm\Gisconverter\Gisconverter;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;

class GeometryComputationService extends BaseService
{
    public function get3dLineMergeWktFromGeojson(string $geojson): string
    {
        return DB::select(
            "SELECT ST_AsText(ST_Force3D(ST_LineMerge(ST_GeomFromGeoJSON('".$geojson."')))) As wkt"
        )[0]->wkt;
    }

    public function getGeometryModelCoordinates(GeometryModel $ecPoi): stdClass
    {
        $geom = $ecPoi->geometry;

        return DB::select("SELECT ST_X(ST_Transform('$geom'::geometry,4326)) as x,ST_Y(ST_Transform('$geom'::geometry,4326)) AS y")[0];
    }

    public function getWktFromGeojson(string $geojson): string
    {
        return DB::select("SELECT ST_GeomFromGeoJSON('".$geojson."') As wkt")[0]->wkt;
    }

    public function get3dGeometryFromGeojsonRAW(string $geojson): Expression
    {
        return DB::raw("(ST_Force3D(ST_GeomFromGeoJSON('".$geojson."')))");
    }

    public function get2dGeometryFromGeojsonRAW(string $geojson): Expression
    {
        return DB::raw("(ST_Force2D(ST_GeomFromGeoJSON('".$geojson."')))");
    }

    protected function getNeighoursByGeometryAndTable($geometry, $table): array
    {
        return DB::select(
            "SELECT id, St_Distance(geometry,?) as dist FROM {$table}
                WHERE St_DWithin(geometry, ?, ".config('wm-package.services.neighbours_distance').')
                order by St_Linelocatepoint(St_Geomfromgeojson(St_Asgeojson(?)),St_Geomfromgeojson(St_Asgeojson(geometry)));',
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
        $line = "ST_GeomFromGeoJSON('".$trackGeojson."')";
        $point = "ST_GeomFromGeoJSON('".$poiGeojson."')";
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

            $name = '<name>'.($this->name ?? '').'</name>';

            return $name.$formattedGeometry;
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

    public function getTracksBbox(SupportCollection $tracks): array|false
    {
        if ($tracks->count() <= 0) {
            return false;
        }

        $tracksIds = $tracks->pluck('id')->toArray();

        $res = DB::select('select ST_Extent(geometry::geometry)
             as bbox from ec_tracks where id IN ('.implode(',', $tracksIds).');');

        if (count($res) > 0) {
            if (! is_null($res[0]->bbox)) {
                $bbox = $this->bboxArrayFromString($res[0]->bbox);

                return $bbox;
            }
        }

        return false;
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
     * Determines next and previous stage of each track inside the layer
     *
     * @return JSON
     */
    public function getLayerEdges($tracks)
    {

        if (empty($tracks)) {
            return null;
        }

        $trackIds = $tracks->pluck('id')->toArray();
        $edges = [];

        foreach ($tracks as $track) {

            $geometry = $track->geometry;

            $start_point = DB::select(
                <<<SQL
                    SELECT ST_AsText(ST_SetSRID(ST_Force2D(ST_StartPoint('$geometry')), 4326)) As wkt
                SQL
            )[0]->wkt;

            $end_point = DB::select(
                <<<SQL
                    SELECT ST_AsText(ST_SetSRID(ST_Force2D(ST_EndPoint('$geometry')), 4326)) As wkt
                SQL
            )[0]->wkt;

            // Find the next tracks
            $nextTrack = EcTrack::whereIn('id', $trackIds)
                ->where('id', '<>', $track->id)
                ->whereRaw(
                    <<<SQL
                        ST_DWithin(ST_SetSRID(geometry, 4326), 'SRID=4326;{$end_point}', 0.001)
                    SQL
                )
                ->get();

            // Find the previous tracks
            $previousTrack = EcTrack::whereIn('id', $trackIds)
                ->where('id', '<>', $track->id)
                ->whereRaw(
                    <<<SQL
                        ST_DWithin(ST_SetSRID(geometry, 4326), 'SRID=4326;{$start_point}', 0.001)
                    SQL
                )
                ->get();

            $edges[$track->id]['prev'] = $previousTrack->pluck('id')->toArray();
            $edges[$track->id]['next'] = $nextTrack->pluck('id')->toArray();
        }

        return $edges;
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
                    .$table
                    .' WHERE user_id = ?'
                    ." AND ABS(EXTRACT(EPOCH FROM created_at) - EXTRACT(EPOCH FROM TIMESTAMP '"
                    .$model->created_at
                    ."')) < 5400"
                    .' AND St_DWithin(geometry, ?, 400);',
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
                    try {
                        $geojson = Gisconverter::gpxToGeojson($fileContent);
                        $content = json_decode($geojson);
                        $contentType = @$content->type;
                    } catch (InvalidText $ec) {
                    }
                }

                if ($geojson === '') {
                    try {
                        $geojson = Gisconverter::kmlToGeojson($fileContent);
                        $content = json_decode($geojson);
                        // $contentType = @$content->type;
                    } catch (InvalidText $ec) {
                    }
                }
            } else {
                $fileContent = GeoJsonService::make()->convertCollectionToFirstFeature($fileContent);
                $fileContent = GeoJsonService::make()->convertPolygonToMultiPolygon($fileContent);
                $content = json_decode($fileContent);
                // $isJson = json_last_error() === JSON_ERROR_NONE;
                // if ($isJson) {
                //     $contentType = $content->type;
                // }
            }
            $contentGeometry = $content->geometry;
            $geometry = $this->get2dGeometryFromGeojsonRAW(json_encode($contentGeometry));
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
                .'public.ST_Force2D('
                ."(SELECT geometry from {$model->getTable()} where id = {$model->id})"
                .'::geometry)'
                .', geometry)'
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
        $distanceQuery = "SELECT ST_Length(ST_GeomFromGeoJSON('".json_encode($geometry)."')::geography)/1000 as length";
        $distance = DB::select(DB::raw($distanceQuery));

        return $distance[0]->length;
    }

    /**
     * Return a collection of ec tracks inside the bbox. The tracks must be within $distance meters
     * of the given $trackId if provided
     *
     * @param  int|null  $trackId  an ec track id to reference
     * @return mixed
     */
    public static function getSearchClustersInsideBBox(App $app, array $bbox, ?int $trackId = null, ?string $searchString = null, string $language = 'it', int $distanceLimit = 1000): array
    {
        $deltaLon = ($bbox[2] - $bbox[0]) / 6;
        $deltaLat = ($bbox[3] - $bbox[1]) / 6;

        $clusterRadius = min($deltaLon, $deltaLat);

        $from = '';
        $where = '';
        $params = [$clusterRadius];
        $validTrackIds = null;

        if ($app->app_id !== 'it.webmapp.webmapp') {
            $validTrackIds = $app->ecTracks->pluck('id')->toArray() ?? [];
        }

        if (! is_null($validTrackIds)) {
            $where .= 'ec_tracks.id IN ('.implode(',', $validTrackIds).') AND ';
        }

        if (
            is_int($trackId)
            && (! $validTrackIds || in_array($trackId, $validTrackIds))
        ) {
            $track = EcTrack::find($trackId);

            if (isset($track)) {
                $from = ', (SELECT geometry as geom FROM ec_tracks WHERE id = ?) as track';
                $params[] = $trackId;
                $where = 'ST_Distance(ST_Transform(ST_SetSRID(ec_tracks.geometry, 4326), 3857), ST_Transform(ST_SetSRID(track.geom, 4326), 3857)) <= ? AND ';
                $params[] = $distanceLimit;
            }
        }

        if (isset($searchString) && ! empty($searchString)) {
            $escapedSearchString = preg_replace('/[^0-9a-z\s]/', '', strtolower($searchString));
            $where .= "to_tsvector(regexp_replace(LOWER(((ec_tracks.name::json))->>'$language'), '[^0-9a-z\s]', '', 'g')) @@ to_tsquery('$escapedSearchString') AND ";
        }

        $where .= 'geometry && ST_SetSRID(ST_MakeBox2D(ST_Point(?, ?), ST_Point(?, ?)), 4326)';
        $params = array_merge($params, $bbox);

        $query = "
SELECT
	ST_Extent(centroid) AS bbox,
    ST_AsGeojson(ST_Centroid(ST_Extent(geometry))) AS geometry,
	json_agg(id) AS ids
FROM (
	SELECT
		id,
		ST_ClusterDBSCAN(
		    ST_Centroid(geometry),
			eps := ?,
			minpoints := 1
		) OVER () AS cluster_id,
		ST_Centroid(geometry) as centroid,
	    geometry
	FROM
		ec_tracks
	    $from
    WHERE $where
    ) clusters
GROUP BY
	cluster_id;";

        /**
         * The query calculate some clusters of ec tracks intersecting the given bbox.
         * For each cluster it returns:
         *  - the cluster point (geometry, geojson geometry)
         *  - the collected bbox (bbox, postgis BOX)
         *  - the list of features included in the cluster (ids, json array)
         */
        $res = DB::select($query, $params);
        $featureCollection = [
            'type' => 'FeatureCollection',
            'features' => [],
        ];

        foreach ($res as $cluster) {
            $ids = json_decode($cluster->ids, true);
            $geometry = json_decode($cluster->geometry, true);
            $bboxString = str_replace(',', ' ', str_replace(['B', 'O', 'X', '(', ')'], '', $cluster->bbox));
            $bbox = array_map('floatval', explode(' ', $bboxString));

            $images = [];
            $i = 0;

            while ($i < count($ids) && count($images) < 3) {
                $track = EcTrack::find($ids[$i]);

                $image = isset($track->featureImage) ? $track->featureImage->thumbnail('150x150') : '';
                if (isset($image) && ! empty($image) && ! in_array($image, $images)) {
                    $images[] = $image;
                }
                $i++;
            }

            $featureCollection['features'][] = [
                'type' => 'Feature',
                'geometry' => $geometry,
                'properties' => [
                    'ids' => $ids,
                    'bbox' => $bbox,
                    'images' => $images,
                ],
            ];
        }

        return $featureCollection;
    }

    /**
     * Retrieve the closest tracks to the given location
     *
     * @param  App  $app  the reference app
     * @param  int  $distance  the distance limit in meters
     * @param  int  $limit  the max numbers of results
     */
    public static function getNearestToLonLat(App $app, float $lon, float $lat, int $distance = 10000, int $limit = 5): array
    {
        $featureCollection = [
            'type' => 'FeatureCollection',
            'features' => [],
        ];
        $query = EcTrack::whereRaw("ST_Distance(
                ST_Transform('SRID=4326;POINT($lon $lat)'::geometry, 3857),
                ST_Transform(ST_SetSRID(geometry, 4326), 3857)
                ) <= $distance");

        if ($app->app_id !== 'it.webmapp.webmapp') {
            $validTrackIds = $app->ecTracks->pluck('id')->toArray() ?? [];
            $query = $query->whereIn('id', $validTrackIds);
        }

        $tracks = $query->orderByRaw("ST_Distance(
                ST_Transform('SRID=4326;POINT($lon $lat)'::geometry, 3857),
                ST_Transform(ST_SetSRID(geometry, 4326), 3857)
                ) ASC")
            ->limit($limit)
            ->get();

        foreach ($tracks as $track) {
            $featureCollection['features'][] = $track->getGeojson();
        }

        return $featureCollection;
    }
}
