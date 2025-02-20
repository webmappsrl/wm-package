<?php

namespace Wm\WmPackage\Http\Controllers\Api;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Wm\WmPackage\Http\Controllers\Controller;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Services\Models\EcPoiService;
use Wm\WmPackage\Services\Models\EcTrackService;
use Wm\WmPackage\Services\StorageService;

class EcTrackController extends Controller
{
    /**
     * Return EcTrack JSON.
     */
    public function getGeojson(EcTrack $ecTrack, array $headers = []): JsonResponse
    {

        $json = StorageService::make()->getTrackGeojson($ecTrack->id);
        if ($json) {
            return response()->json(json_decode($json));
        }

        UpdateEcTrackAwsJob::dispatch($ecTrack);

        return response()->json($ecTrack->getGeojson(), 200, $headers);
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
        return response()->json($ecTrack->getMedia()->first());
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
                $ids = EcTrackService::make()->getUpdatedAtTracks($user->id)->toArray();
                $list = $list + $ids;
            }
        } else {
            $list = EcTrackService::make()->getUpdatedAtTracks();
        }

        return response()->json($list);
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
