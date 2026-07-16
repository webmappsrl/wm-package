<?php

declare(strict_types=1);

namespace Wm\WmPackage\Http\Controllers\Nova;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Wm\WmPackage\Exceptions\AnalyticsQueryException;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\PostHog\AnalyticsService;

class AnalyticsController extends Controller
{
    public function layer(Request $request, Layer $layer): JsonResponse
    {
        abort_unless(
            $request->user()?->hasRole('Administrator') || $layer->user_id === $request->user()?->id,
            403
        );

        $service = app(AnalyticsService::class);
        $range = $this->resolveRange($request);

        $usage = $service->getLayerUsage($layer->id, $range);
        $trackDownloads = $service->getLayerTrackDownloads($layer, $range);

        return response()->json(array_merge($usage, [
            'track_downloads' => $trackDownloads,
        ]));
    }

    public function global(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasRole('Administrator'), 403);

        $service = app(AnalyticsService::class);
        $range = $this->resolveRange($request);

        try {
            $usage = $service->getGlobalUsage($range);
            $rankingLayers = $service->getAllLayersUsage($range);
            $rankingTracks = $service->getAllTracksDownloads($range);
        } catch (AnalyticsQueryException $e) {
            return response()->json(['error' => 'analytics_query_failed'], 502);
        }

        return response()->json(array_merge($usage, [
            'ranking_layers' => $rankingLayers,
            'ranking_tracks' => $rankingTracks,
        ]));
    }

    private function resolveRange(Request $request): string
    {
        $month = $request->query('month');
        if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
            return 'month:'.$month;
        }

        $days = (int) $request->query('days', 30);
        if (in_array($days, [90, 365], true)) {
            return "last_{$days}_days";
        }

        return 'last_30_days';
    }
}
