<?php

namespace Wm\WmPackage\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Wm\WmPackage\Http\Controllers\Controller;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Models\MediaService;

class LayerFavoriteController extends Controller
{
    /**
     * Add the given layer to the authenticated user's favorites (idempotent).
     */
    public function addFavorite(Request $request, Layer $layer): JsonResponse
    {
        $userId = auth('api')->id();
        if (! $layer->isFavorited($userId)) {
            $layer->toggleFavorite($userId);
        }

        return response()->json(['favorite' => $layer->isFavorited($userId)]);
    }

    /**
     * Remove the given layer from the authenticated user's favorites (idempotent).
     */
    public function removeFavorite(Request $request, Layer $layer): JsonResponse
    {
        $userId = auth('api')->id();
        if ($layer->isFavorited($userId)) {
            $layer->toggleFavorite($userId);
        }

        return response()->json(['favorite' => $layer->isFavorited($userId)]);
    }

    /**
     * Toggle the favorite state of the given layer for the authenticated user.
     */
    public function toggleFavorite(Request $request, Layer $layer): JsonResponse
    {
        $userId = auth('api')->id();
        $layer->toggleFavorite($userId);

        return response()->json(['favorite' => $layer->isFavorited($userId)]);
    }

    /**
     * List the authenticated user's favorite layers with a lightweight payload
     * (only the fields consumed by wm-layer-box: id, title, feature_image, logo_image, style.color).
     */
    public function list(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();

        $mediaService = MediaService::make();
        $layers = $user->favorite((new Layer)->getMorphClass())
            ->map(function (Layer $layer) use ($mediaService) {
                $firstMedia = $layer->getMedia()->first();

                return [
                    'id' => $layer->id,
                    'title' => $layer->getTranslations('name'),
                    'feature_image' => $firstMedia ? $mediaService->getThumbnailUrl($firstMedia) : '',
                    'logo_image' => $layer->logo_image,
                    'style' => ['color' => $layer->getStrokeColorHex()],
                ];
            })
            ->values();

        return response()->json(['favorites' => $layers]);
    }
}
