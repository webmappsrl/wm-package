<?php

declare(strict_types=1);

namespace Wm\WmPackage\Http\Controllers\Nova;

use Illuminate\Contracts\Cache\LockTimeoutException;
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

        try {
            $usage = $service->getLayerUsage($layer->id, $range);
            $trackDownloads = $service->getLayerTrackDownloads($layer, $range);
        } catch (LockTimeoutException $e) {
            return response()->json(['error' => 'analytics_query_failed'], 502);
        }

        // Non nel try/catch sopra: getUserMovedStats() gestisce già internamente ogni
        // fallimento e ritorna null (mai un'eccezione) — le altre metriche della card
        // devono restare visibili anche se questa query bulk più pesante fallisce.
        $userPresence = $service->getUserMovedStats($layer, $range);

        return response()->json(array_merge($usage, [
            'track_downloads' => $trackDownloads,
            'user_presence' => $userPresence,
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
            $rankingUserPresence = $service->getAllLayersUserPresence($range);
            $rankingTracks = $service->getAllTracksDownloads($range);
            $rankingTrackShares = $service->getAllTracksShares($range);
            $searchTotal = $service->getTotalSearches($range);
            $rankingSearchQueries = $service->getTopSearchQueries($range);
        } catch (AnalyticsQueryException|LockTimeoutException $e) {
            return response()->json(['error' => 'analytics_query_failed'], 502);
        }

        return response()->json(array_merge($usage, [
            'ranking_layers' => $rankingLayers,
            'ranking_user_presence' => $rankingUserPresence,
            'ranking_tracks' => $rankingTracks,
            'ranking_track_shares' => $rankingTrackShares,
            'search_total' => $searchTotal,
            'ranking_search_queries' => $rankingSearchQueries,
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
