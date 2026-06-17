<?php

declare(strict_types=1);

namespace Wm\WmPackage\Http\Controllers\Nova;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\PostHog\AnalyticsService;

class AnalyticsController extends Controller
{
    public function layer(Layer $layer): JsonResponse
    {
        return response()->json(
            app(AnalyticsService::class)->getLayerUsage($layer->id)
        );
    }
}
