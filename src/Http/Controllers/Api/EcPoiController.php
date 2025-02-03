<?php

namespace Wm\WmPackage\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Wm\WmPackage\Http\Controllers\Controller;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\Media;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Services\Models\EcPoiService;
use Wm\WmPackage\Services\Models\MediaService;
use Wm\WmPackage\Services\Models\OutSourceFeatureService;

class EcPoiController extends Controller
{
    public static function getNeighbourMedia(EcPoi $ecPoi): JsonResponse
    {
        $geometryComputationService = GeometryComputationService::make();

        return response()->json($geometryComputationService->getNeighboursGeojson($ecPoi, Media::class));
    }

    public static function getAssociatedMedia(EcPoi $ecPoi): JsonResponse
    {
        return response()->json(MediaService::make()->getAssociatedMedia($ecPoi));
    }

    public static function getFeatureImage(EcPoi $ecPoi)
    {
        return response()->json($ecPoi->featureImage()->get());
    }

    /**
     * Returns an array of ID and Updated_at based on the Author emails provided
     *
     * @param  $email  string
     * @return JsonResponse with the current
     */
    public function exportPoisByAuthorEmail($email = ''): JsonResponse
    {
        $ecPoiService = EcPoiService::make();
        if (empty($email)) {
            return response()->json($ecPoiService->getUpdatedAtPois());
        } else {
            $list = [];
            $emails = explode(',', $email);
            foreach ($emails as $email) {
                $user = User::where('email', '=', $email)->first();
                $ids = $ecPoiService->getUpdatedAtPois($user)->toArray();
                $list = $list + $ids;
            }

            return response()->json($list);
        }
    }

    /**
     * Returns the EcPoi ID associated to an external feature
     *
     * @param  string  $endpoint_slug
     * @param  int  $source_id
     * @return JsonResponse
     */
    public function getEcPoiFromSourceID($endpoint_slug, $source_id)
    {
        return OutSourceFeatureService::make()->getModelIdFromOutSourceFeature($endpoint_slug, $source_id, EcPoi::class);
    }

    /**
     * Returns the EcPoi Geojson associated to an external feature
     *
     * @param  string  $endpoint_slug
     * @param  int  $source_id
     * @return JsonResponse
     */
    public function getPoiGeojsonFromSourceID($endpoint_slug, $source_id)
    {
        $poi_id = OutSourceFeatureService::make()->getModelIdFromOutSourceFeature($endpoint_slug, $source_id, EcPoi::class);
        $poi = EcPoi::find($poi_id);
        $headers = [];

        if (is_null($poi)) {
            return response()->json(['code' => 404, 'error' => 'Not Found'], 404);
        }

        return response()->json($poi->getGeojson(), 200, $headers);
    }

    /**
     * Returns the EcPoi Webapp URL associated to an external feature
     *
     * @param  string  $endpoint_slug
     * @param  int  $source_id
     * @return JsonResponse
     */
    public function getEcPoiWebappURLFromSourceID($endpoint_slug, $source_id)
    {
        $poi_id = OutSourceFeatureService::make()->getModelIdFromOutSourceFeature($endpoint_slug, $source_id, EcPoi::class);
        $poi = EcPoi::find($poi_id);
        $app_id = $poi->user->apps[0]->id;

        if (is_null($poi) || empty($app_id)) {
            return response()->json(['code' => 404, 'error' => 'Not Found'], 404);
        }

        return redirect('https://'.$app_id.'.app.webmapp.it/#/map?poi='.$poi_id);
    }
}
