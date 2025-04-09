<?php

namespace Wm\WmPackage\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Wm\WmPackage\Http\Controllers\Controller;
use Wm\WmPackage\Http\Resources\MediaResource;
use Wm\WmPackage\Models\Media;

class MediaController extends Controller
{
    /**
     * Return Media JSON.
     */
    public function show(Media $media): JsonResponse
    {
        $geojson = $media->getGeojson();
        $geojson['properties'] = new MediaResource($media)->toArray();
        return response()->json($geojson);
    }
}
