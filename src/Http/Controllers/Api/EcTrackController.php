<?php

namespace Wm\WmPackage\Http\Controllers\Api;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Wm\WmPackage\Http\Controllers\Controller;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Media;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Services\Models\EcPoiService;
use Wm\WmPackage\Services\Models\EcTrackService;
use Wm\WmPackage\Services\Models\MediaService;
use Wm\WmPackage\Services\Models\OutSourceFeatureService;
use Wm\WmPackage\Services\StorageService;

class EcTrackController extends Controller
{
    /**
     * Return EcTrack JSON.
     */
    public function getGeojson(Request $request, EcTrack $ecTrack, array $headers = []): JsonResponse
    {

        $json = StorageService::make()->getTrackGeojson($ecTrack->id);
        if ($json) {
            return response()->json(json_decode($json));
        }

        UpdateEcTrackAwsJob::dispatch($ecTrack);

        return response()->json($ecTrack->getGeojson(), 200, $headers);
    }

    /**
     * Get a feature collection with the neighbour media
     */
    public static function getNeighbourMedia(EcTrack $ecTrack): JsonResponse
    {
        return response()->json(GeometryComputationService::make()->getNeighboursGeojson($ecTrack, Media::class));
    }

    /**
     * Get a feature collection with the neighbour pois
     */
    public static function getNeighbourEcPoi(EcTrack $ecTrack): JsonResponse
    {
        return response()->json(GeometryComputationService::make()->getNeighboursGeojson($ecTrack, EcPoi::class));
    }

    /**
     * Get a feature collection with the related media
     */
    public static function getAssociatedMedia(EcTrack $ecTrack): JsonResponse
    {
        return response()->json(MediaService::make()->getAssociatedMedia($ecTrack));
    }

    /**
     * Get a feature collection with the related pois
     */
    public static function getAssociatedEcPois(EcTrack $ecTrack): JsonResponse
    {
        return response()->json(EcPoiService::make()->getAssociatedEcPois($ecTrack));
    }

    public static function getFeatureImage(EcTrack $ecTrack): JsonResponse
    {
        return response()->json($ecTrack->featureImage()->get());
    }

    /**
     * Search the ec tracks using the GET parameters
     */
    public function search(Request $request): JsonResponse
    {
        $data = $request->all();

        $validator = Validator::make($data, [
            'bbox' => 'required',
            'app_id' => 'required|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $featureCollection = [
            'type' => 'FeatureCollection',
            'features' => [],
        ];

        $bboxParam = $data['bbox'];
        $sku = $data['app_id'];

        $app = App::where('sku', '=', $sku)->first();

        if (! isset($app->id)) {
            return response()->json(['error' => 'Unknown reference app'], 400);
        }

        if (isset($bboxParam)) {
            try {
                $bbox = explode(',', $bboxParam);
                $bbox = array_map('floatval', $bbox);
            } catch (Exception $e) {
                Log::warning($e->getMessage());
            }

            if (isset($bbox) && is_array($bbox)) {
                $trackRef = $data['reference_id'] ?? null;
                if (isset($trackRef) && strval(intval($trackRef)) === $trackRef) {
                    $trackRef = intval($trackRef);
                } else {
                    $trackRef = null;
                }

                $featureCollection = GeometryComputationService::make()->getSearchClustersInsideBBox($app, $bbox, $trackRef, null, 'en');
            }
        }

        return response()->json($featureCollection);
    }

    /**
     * Get the closest ec track to the given location
     */
    public function nearestToLocation(Request $request, string $lon, string $lat): JsonResponse
    {
        $data = $request->all();

        // TODO: add app as path parameter
        //      this validation is wrong ... it should check over db the app_id existence before the App::where below
        $validator = Validator::make($data, [
            'app_id' => 'required|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $sku = $data['app_id'];

        $app = App::where('sku', '=', $sku)->first();

        if (! isset($app->id)) {
            return response()->json(['error' => 'Unknown reference app'], 400);
        }

        $featureCollection = [
            'type' => 'FeatureCollection',
            'features' => [],
        ];
        try {

            if ($lon === strval(floatval($lon)) && $lat === strval(floatval($lat))) {
                $lon = floatval($lon);
                $lat = floatval($lat);
                $featureCollection = GeometryComputationService::make()->getNearestToLonLat($app, $lon, $lat);
            }
        } catch (Exception $e) {
        }

        return response()->json($featureCollection);
    }

    /**
     * Get the most viewed ec tracks
     */
    public function mostViewed(Request $request): JsonResponse
    {
        $data = $request->all();

        // TODO: add app as path parameter
        //      this validation is wrong ... it should check over db the app_id existence before the App::where below
        $validator = Validator::make($data, [
            'app_id' => 'required|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $sku = $data['app_id'];

        $app = App::where('sku', '=', $sku)->first();

        if (! isset($app->id)) {
            return response()->json(['error' => 'Unknown reference app'], 400);
        }

        $featureCollection = EcTrackService::make()->getMostViewed($app);

        return response()->json($featureCollection);
    }

    /**
     * Get multiple ec tracks in a single geojson
     */
    public function multiple(Request $request): JsonResponse
    {
        $featureCollection = [
            'type' => 'FeatureCollection',
            'features' => [],
        ];

        try {
            $ids = $request->get('ids');
            $ids = explode(',', $ids ?? null);
        } catch (Exception $e) {
        }

        if (isset($ids) && is_array($ids)) {
            $ids = array_slice($ids, 0, 10);
            $ids = array_values(array_unique($ids));
            foreach ($ids as $id) {
                if ($id === strval(intval($id))) {
                    $track = EcTrack::find($id);
                    if (isset($track)) {
                        $featureCollection['features'][] = $track->getGeojson();
                    }
                }
            }
        }

        return response()->json($featureCollection);
    }

    /**
     * Toggle the favorite on the given ec track
     *
     *
     * @return JsonResponse with the current
     */
    public function addFavorite(Request $request, EcTrack $ecTrack): JsonResponse
    {
        $userId = auth('api')->id();
        if (! $ecTrack->isFavorited($userId)) {
            $ecTrack->toggleFavorite($userId);
        }

        return response()->json(['favorite' => $ecTrack->isFavorited($userId)]);
    }

    /**
     * Toggle the favorite on the given ec track
     *
     *
     * @return JsonResponse with the current
     */
    public function removeFavorite(Request $request, EcTrack $ecTrack): JsonResponse
    {
        $userId = auth('api')->id();
        if ($ecTrack->isFavorited($userId)) {
            $ecTrack->toggleFavorite($userId);
        }

        return response()->json(['favorite' => $ecTrack->isFavorited($userId)]);
    }

    /**
     * Toggle the favorite on the given ec track
     *
     *
     * @return JsonResponse with the current
     */
    public function toggleFavorite(Request $request, EcTrack $ecTrack): JsonResponse
    {
        $userId = auth('api')->id();
        $ecTrack->toggleFavorite($userId);

        return response()->json(['favorite' => $ecTrack->isFavorited($userId)]);
    }

    /**
     * Toggle the favorite on the given ec track
     *
     *
     * @return JsonResponse with the current
     */
    public function listFavorites(Request $request): JsonResponse
    {
        /**
         * @var User $user
         */
        $user = auth('api')->user();

        $ids = $user->favorite(EcTrack::class)->pluck('id');

        return response()->json(['favorites' => $ids]);
    }

    /**
     * Returns an array of ID and Updated_at based on the Author emails provided
     *
     * @param  $email  string
     * @return JsonResponse with the current
     */
    public function exportTracksByAuthorEmail($email = ''): JsonResponse
    {
        if ($email) {
            $list = [];
            $emails = explode(',', $email);
            foreach ($emails as $email) {
                $user = User::where('email', '=', $email)->first();
                $ids = EcTrackService::make()->getUpdatedAtTracks($user)->toArray();
                $list = $list + $ids;
            }
        } else {
            $list = EcTrackService::make()->getUpdatedAtTracks();
        }

        return response()->json($list);
    }

    /**
     * Returns the EcTrack ID associated to an external feature
     *
     * @param  string  $endpoint_slug
     * @param  int  $source_id
     * @return JsonResponse
     */
    public function getEcTrackFromSourceID($endpoint_slug, $source_id)
    {
        return OutSourceFeatureService::make()->getModelIdFromOutSourceFeature($endpoint_slug, $source_id, EcTrack::class);
    }

    /**
     * Returns the EcTrack GeoJson associated to an external feature
     *
     * @param  string  $endpoint_slug
     * @param  int  $source_id
     * @return JsonResponse
     */
    public function getTrackGeojsonFromSourceID($endpoint_slug, $source_id)
    {
        $track_id = OutSourceFeatureService::make()->getModelIdFromOutSourceFeature($endpoint_slug, $source_id, EcTrack::class);

        $track = EcTrack::find($track_id);
        $headers = [];

        if (is_null($track)) {
            return response()->json(['code' => 404, 'error' => 'Not Found'], 404);
        }

        return response()->json($track->getGeojson(), 200, $headers);
    }

    /**
     * Returns the EcTrack Webapp URL associated to an external feature
     *
     * @param  string  $endpoint_slug
     * @param  int  $source_id
     * @return JsonResponse
     */
    public function getEcTrackWebappURLFromSourceID($endpoint_slug, $source_id)
    {
        $track_id = OutSourceFeatureService::make()->getModelIdFromOutSourceFeature($endpoint_slug, $source_id, EcTrack::class);
        $track = EcTrack::find($track_id);
        $app_id = $track->user->apps[0]->id;

        if (is_null($track) || empty($app_id)) {
            return response()->json(['code' => 404, 'error' => 'Not Found'], 404);
        }

        return redirect('https://'.$app_id.'.app.webmapp.it/#/map?track='.$track_id);
    }

    /**
     * Get the feature collection for the given track pdf
     */
    public static function getFeatureCollectionForTrackPdf(EcTrack $ecTrack): JsonResponse
    {

        $trackGeometry = GeometryComputationService::make()->getModelGeometryAsGeojson($ecTrack);
        $trackGeometry = json_decode($trackGeometry);

        // feature must have properties field as follow: {"type":"Feature","properties":{"id":1, "type":"track/poi", "strokeColor": "", "fillColor": ""},"geometry":{"type":"LineString","coordinates":[[11.123,45.123],[11.123,45.123]]}}

        $features = [];

        // TODO: move the json format out of here, it should be in the model, resource or in a service
        $trackFeature = [
            'type' => 'Feature',
            'properties' => [
                'id' => $ecTrack->id,
                'type_sisteco' => 'Track',
                'strokeColor' => '',
                'fillColor' => '',
            ],
            'geometry' => $trackGeometry,
        ];

        $features[] = $trackFeature;

        // if the track has related pois we add them to the feature collection, else we return the track feature only
        if (count($ecTrack->ecPois) > 0) {
            foreach ($ecTrack->ecPois as $poi) {
                $poiGeometry = GeometryComputationService::make()->getModelGeometryAsGeojson($poi);
                $poiGeometry = json_decode($poiGeometry);
                // TODO: move the json format out of here, it should be in the model, resource or in a service
                $poiFeature = [
                    'type' => 'Feature',
                    'properties' => [
                        'id' => $poi->id,
                        'type_sisteco' => 'Poi',
                        'pointRadius' => '',
                        'pointFillColor' => '',
                        'pointStrokeColor' => '',
                    ],
                    'geometry' => $poiGeometry,
                ];
                $features[] = $poiFeature;
            }
        }

        // TODO: move the json format out of here, it should be in the model, resource or in a service
        $featureCollection = [
            'type' => 'FeatureCollection',
            'features' => $features,
        ];

        return response()->json($featureCollection);
    }
}
