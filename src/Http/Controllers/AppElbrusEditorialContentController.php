<?php

namespace Wm\WmPackage\Http\Controllers;

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Illuminate\Http\JsonResponse;

class AppElbrusEditorialContentController extends Controller
{
    /**
     * Api to get the ec poi geojson with the elbrus mapping
     */
    public function getPoiGeojson(App $app, EcPoi $ecPoi): JsonResponse
    {
        return response()->json($ecPoi->getElbrusGeojson());
    }

    /**
     * Api to get the ec track geojson with the elbrus mapping
     */
    public function getTrackGeojson(App $app, EcTrack $ecTrack): JsonResponse
    {
        return response()->json($ecTrack->getElbrusGeojson());
    }

    /**
     * Api to get the ec track json with the elbrus mapping
     */
    public function getTrackJson(App $app, EcTrack $ecTrack): JsonResponse
    {
        $geojson = $ecTrack->getElbrusGeojson();
        return response()->json($geojson['properties']);
    }
}
