<?php

namespace Wm\WmPackage\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Wm\WmPackage\Models\UgcTrack;

/**
 * Public (unauthenticated) landing page for a shared UgcTrack (oc:8183, third revision):
 * `GET /share/ugc-track/{uuid}`. Purely a static snapshot renderer — see
 * docs/features/8183-condivisione-percorso-registrato-sui-social/overview.md "Ciclo di vita
 * della pagina pubblica": nothing is recomputed here, the page only reads what
 * ShareStoryImageController already persisted (the `share_image` media + the
 * `properties.share_snapshot` frozen at share time).
 *
 * 404 in two cases, both meaning "there is nothing shareable to show at this uuid":
 *   - no UgcTrack matches the uuid at all (never existed, or was deleted — deleting the
 *     track must not leave its data reachable through this public, unauthenticated URL);
 *   - the UgcTrack exists but has no persisted `share_image` (it was never actually shared
 *     through the endpoint above, or the media was somehow lost) — showing an OG page
 *     pointing at a non-existent image would be a broken experience for the crawler/user,
 *     so this is treated the same as "nothing to show" even though the ticket's wording
 *     technically only calls out "the track exists" vs "was deleted" (documented in
 *     notes.md as a point worth a second look).
 */
class ShareUgcTrackController extends Controller
{
    public function show(string $uuid): View
    {
        $ugcTrack = UgcTrack::where('properties->uuid', $uuid)->first();

        if ($ugcTrack === null) {
            throw new NotFoundHttpException('No track found for the given uuid.');
        }

        $media = $ugcTrack->getFirstMedia('share_image');

        if ($media === null) {
            throw new NotFoundHttpException('This track has not been shared yet.');
        }

        $snapshot = $ugcTrack->properties['share_snapshot'] ?? [];
        $name = $snapshot['name'] ?? $ugcTrack->name ?: 'Percorso registrato';

        return view('wm-package::share-ugc-track', [
            'title' => $this->buildTitle($name, $snapshot),
            // Hardcoded, Italian, shard-agnostic-in-name-only (see notes.md): this package
            // instance currently serves only camminiditalia, so a fixed string is
            // acceptable for now — flagged as an open point if the package is ever reused
            // by a shard wanting different wording.
            'description' => $snapshot['app_name'] ?? null
                ? "Percorso registrato su {$snapshot['app_name']}"
                : "Percorso registrato su Cammini d'Italia",
            'imageUrl' => $media->getUrl(),
            'canonicalUrl' => URL::route('share.ugc-track', ['uuid' => $uuid]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function buildTitle(string $name, array $snapshot): string
    {
        $parts = [$name];

        if (isset($snapshot['distance_km']) && (float) $snapshot['distance_km'] > 0) {
            $parts[] = number_format((float) $snapshot['distance_km'], 1, ',', '').' km';
        }

        if (isset($snapshot['ascent_meters']) && (float) $snapshot['ascent_meters'] > 0) {
            $parts[] = '+'.number_format((float) $snapshot['ascent_meters'], 0, ',', '').' m';
        }

        return implode(' · ', $parts);
    }
}
