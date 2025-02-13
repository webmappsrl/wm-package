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


class EcPoiController extends Controller
{

    public static function getFeatureImage(EcPoi $ecPoi)
    {
        return response()->json($ecPoi->getMedia()->first());
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
}
