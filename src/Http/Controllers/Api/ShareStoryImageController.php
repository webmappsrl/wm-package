<?php

namespace Wm\WmPackage\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Wm\WmPackage\Http\Controllers\Controller;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Services\Models\StoryShare\StoryShareImageService;

/**
 * Compositing endpoint for the Instagram/Facebook Stories share image (oc:8183).
 *
 * Receives a raw map screenshot + pre-computed track statistics from the client, along with
 * the `uuid` of the UgcTrack being shared, composes them with the owning app's branded
 * `story_frame` (see {@see StoryShareImageService}), and returns the final 1080x1920 PNG.
 * No persistence of the resulting image: the UgcTrack lookup is read-only, purely to
 * authenticate which app's branding applies.
 */
class ShareStoryImageController extends Controller
{
    /**
     * Max accepted upload size in KB (plan.md task 9): coherent with the expected size of a
     * map screenshot. No dedicated rate-limit in this cycle — explicit decision, see
     * docs/features/8183-condivisione-percorso-registrato-sui-social/overview.md "Rischi".
     */
    private const MAX_SCREENSHOT_KB = 10240;

    public function store(Request $request, StoryShareImageService $service): Response
    {
        $validator = Validator::make($request->all(), [
            'uuid' => ['required', 'string'],
            // Accepted for client convenience/logging only — see the security note below,
            // never used to decide which app's story_frame to load.
            'app_id' => ['sometimes'],
            'screenshot' => ['required', 'image', 'max:'.self::MAX_SCREENSHOT_KB],
            'duration_seconds' => ['required', 'integer', 'min:0'],
            'distance_km' => ['required', 'numeric', 'min:0'],
            'ascent_meters' => ['required', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors()->first(),
                'code' => 422,
            ], 422);
        }

        $validated = $validator->validated();
        $user = $request->user();

        // SECURITY (non-negotiable, oc:8183): the app is ALWAYS derived from the UgcTrack
        // being shared — never from the `app_id` field the client may also send in the
        // payload. The client sends `uuid` (properties.uuid of the UgcTrack, generated
        // client-side when the track is registered), which lets the server look up the
        // authoritative track record, verify the requesting user actually owns it, and
        // read the app id from THAT record. This is what stands between one app's users
        // and another app's story_frame branding.
        $ugcTrack = UgcTrack::where('properties->uuid', $validated['uuid'])->first();

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
            $image = $service->compose($app, $request->file('screenshot'), [
                'duration_seconds' => (int) $validated['duration_seconds'],
                'distance_km' => (float) $validated['distance_km'],
                'ascent_meters' => (float) $validated['ascent_meters'],
            ]);
        } catch (RuntimeException $e) {
            // Invalid/corrupted screenshot, or an unreadable story_frame asset: both mean
            // "could not produce a valid share image" — 4xx, never a 200 with a partial or
            // corrupted image (plan.md task 11).
            Log::warning('[oc:8183] share-story-image compositing failed: '.$e->getMessage(), [
                'app_id' => $app->id,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'error' => 'Unable to process the provided screenshot.',
                'code' => 422,
            ], 422);
        } catch (Throwable $e) {
            Log::error('[oc:8183] share-story-image unexpected failure: '.$e->getMessage(), [
                'app_id' => $app->id,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'error' => 'Internal error while generating the share image.',
                'code' => 500,
            ], 500);
        }

        return response($image->getEncoded(), 200, [
            'Content-Type' => 'image/png',
        ]);
    }
}
