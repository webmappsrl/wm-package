<?php

declare(strict_types=1);

namespace Wm\WmPackage\Http\Controllers\Nova;

use Illuminate\Routing\Controller;
use Wm\WmPackage\Services\PostHog\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Wm\WmPackage\Models\Layer;

class AnalyticsController extends Controller
{
    public function layer(Layer $layer): JsonResponse
    {
        return response()->json(
            app(AnalyticsService::class)->getLayerUsage($layer->id)
        );
    }
}
