<?php

namespace Wm\WmPackage\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;
use Wm\WmPackage\Http\Controllers\Controller;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Services\Models\StoryShare\MapRenderService;
use Wm\WmPackage\Services\Models\StoryShare\StoryImageLayout;
use Wm\WmPackage\Services\Models\StoryShare\StoryShareImageService;
use Wm\WmPackage\Services\Models\StoryShare\TrackStatsService;

/**
 * Compositing endpoint for the Instagram/Facebook Stories share image (oc:8183, third
 * revision).
 *
 * The client sends ONLY the `uuid` of the UgcTrack being shared — no screenshot, no
 * statistics, no app_id (see docs/features/8183-condivisione-percorso-registrato-sui-social/
 * overview.md for the full rationale). Everything else is computed server-side:
 *   1. resolve the UgcTrack by `properties.uuid` and verify ownership (unchanged from the
 *      previous revision — see notes.md "Revisione: nuovo meccanismo di risoluzione app");
 *   2. compute statistics from `properties.locations` ({@see TrackStatsService});
 *   3. render the map image from the track's own PostGIS geometry ({@see MapRenderService});
 *   4. composite map + statistics + the app's `story_frame` ({@see StoryShareImageService});
 *   5. persist the final image on the UgcTrack's `share_image` media collection, so the
 *      public `GET /share/ugc-track/{uuid}` page can serve it later, asynchronously, to an
 *      OG-unfurling crawler;
 *   6. return both a direct URL to the persisted image and the public share page URL.
 */
class ShareStoryImageController extends Controller
{
    public function store(
        Request $request,
        TrackStatsService $statsService,
        MapRenderService $mapRenderService,
        StoryShareImageService $compositingService
    ): JsonResponse {
        $validator = Validator::make($request->all(), [
            'uuid' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors()->first(),
                'code' => 422,
            ], 422);
        }

        $uuid = $validator->validated()['uuid'];
        $user = $request->user();

        // SECURITY (non-negotiable, oc:8183): the app is ALWAYS derived from the UgcTrack
        // being shared — never from a client-supplied parameter. See notes.md for the full
        // history of why (the previous `User::app()`-based mechanism was reverted).
        $ugcTrack = UgcTrack::where('properties->uuid', $uuid)->first();

        if ($ugcTrack === null) {
            return response()->json([
                'error' => 'No track found for the given uuid.',
                'code' => 404,
            ], 404);
        }

        if ((int) $ugcTrack->user_id !== (int) $user->id) {
            // Track exists but belongs to someone else: never leak whose it is, just
            // refuse. 403 (not 404) because the resource's existence is not the secret
            // here — ownership is what's being enforced.
            return response()->json([
                'error' => 'The authenticated user does not own this track.',
                'code' => 403,
            ], 403);
        }

        // properties.app_id is the trusted source (set server-side at track creation, see
        // Controller::validateGeojson()); the real `app_id` column is used only as a
        // fallback for tracks created outside that flow (e.g. via Nova), matching the same
        // precedent already established in UgcController::fillModelWithRequest().
        $appId = $ugcTrack->properties['app_id'] ?? $ugcTrack->app_id;
        $app = $appId !== null ? App::find($appId) : null;

        if ($app === null) {
            Log::error('[oc:8183] share-story-image: could not resolve an app for the given ugc track', [
                'ugc_track_id' => $ugcTrack->id,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'error' => 'Unable to resolve the app for this track.',
                'code' => 500,
            ], 500);
        }

        try {
            $stats = $statsService->compute($ugcTrack->properties['locations'] ?? []);
            $mapImage = $mapRenderService->render($ugcTrack, $app, StoryImageLayout::MAP_WIDTH, StoryImageLayout::MAP_HEIGHT);
            $image = $compositingService->compose($app, $mapImage, $stats);

            $media = $ugcTrack->addMediaFromString($image->getEncoded())
                ->usingFileName('share-'.$ugcTrack->id.'.png')
                ->toMediaCollection('share_image');

            // Static snapshot (overview.md "Ciclo di vita della pagina pubblica"): the
            // public page never recomputes anything, so the display data it needs (track
            // name, stats, owning app's name) is frozen here, at share time, instead of
            // being re-derived from possibly-changed live data when a crawler later visits
            // the page. saveQuietly() to avoid re-triggering UgcObserver (geometry
            // normalization etc. — same idiom already used by
            // GeometryModel::populateProperties()/populatePropertyMedia()).
            $ugcTrack->properties = array_merge($ugcTrack->properties ?? [], [
                'share_snapshot' => [
                    'name' => $ugcTrack->name,
                    'app_name' => $app->name,
                    'duration_seconds' => $stats['duration_seconds'],
                    'distance_km' => $stats['distance_km'],
                    'ascent_meters' => $stats['ascent_meters'],
                    'shared_at' => now()->toIso8601String(),
                ],
            ]);
            $ugcTrack->saveQuietly();
        } catch (Throwable $e) {
            Log::error('[oc:8183] share-story-image generation failed: '.$e->getMessage(), [
                'app_id' => $app->id,
                'ugc_track_id' => $ugcTrack->id,
                'user_id' => $user->id,
                'exception' => get_class($e),
            ]);

            return response()->json([
                'error' => 'Internal error while generating the share image.',
                'code' => 500,
            ], 500);
        }

        return response()->json([
            'image_url' => $media->getUrl(),
            'share_url' => route('share.ugc-track', ['uuid' => $uuid]),
        ]);
    }
}
