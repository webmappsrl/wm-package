<?php

namespace Wm\WmPackage\Http\Controllers\Api;

use Exception;
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

    public function destroy(Media $media): JsonResponse
    {
        $this->validateUser($media);
        try {
            $media->delete();
        } catch (Exception $e) {
            return response()->json([
                'error' => "this media can't be deleted by api",
                'code' => 400,
            ], 400);
        }

        return response()->json(['success' => 'media deleted']);
    }
}
