<?php

namespace Wm\WmPackage\Services\Models\StoryShare;

use Wm\WmPackage\Services\Models\UgcTrackCleanupService;

/**
 * Computes track statistics (duration, distance, ascent) server-side from the raw GPS
 * location log stored in `UgcTrack.properties['locations']` (oc:8183, third revision).
 *
 * This is a direct PHP port of the algorithm previously implemented client-side in
 * `core/src/app/services/geoutils.service.ts` (webmapp-app repo, method `getSlope()` for
 * ascent + `getTime()`/`getLength()` for duration/distance), now removed from that use since
 * the client no longer sends pre-computed statistics. See
 * docs/features/8183-condivisione-percorso-registrato-sui-social/notes.md for the porting
 * decisions (earth radius constant, noise threshold semantics, empty/degenerate input).
 *
 * Stateless: no dependency on any model, only on the raw `locations` array shape:
 * `[{time: int (ms epoch), latitude: float, longitude: float, altitude: float,
 *   altitudeAccuracy: float}, ...]`. Entries are filtered through the same rules as
 * `UgcTrackCleanupService` before any computation (defensive: a single malformed GPS sample
 * must not corrupt the whole distance/ascent sum by producing NaN/absurd jumps, and points
 * with excessive accuracy or at (0,0) must be excluded from statistics).
 */
class TrackStatsService
{
    /**
     * Earth radius in meters used for the haversine distance formula, as specified by the
     * ticket (oc:8183) — matches the "raggio terrestre 6371000m" requirement. Note this is
     * the IUGG mean radius, close to but NOT identical to the ~6371008.8m used by
     * OpenLayers' `getDistance()` (the sphere the original TS algorithm delegated to for
     * `getLength()`/`_calcDistanceM()`): the ticket's explicit value is used instead, since
     * it is the one written into the spec, and the ~0.0001% difference is immaterial for a
     * share-image statistics display.
     */
    private const EARTH_RADIUS_M = 6371000;

    private UgcTrackCleanupService $cleanup;

    /**
     * Le statistiche si calcolano sugli stessi punti della geometria pulita (oc:8719): senza,
     * l'immagine condivisa mostrerebbe la distanza gonfiata dai punti da rete cellulare.
     */
    public function __construct(?UgcTrackCleanupService $cleanup = null)
    {
        $this->cleanup = $cleanup ?? UgcTrackCleanupService::make();
    }

    /**
     * @param  array<int, mixed>  $locations  raw `properties['locations']` array of a
     *                                        UgcTrack, in chronological order (this service
     *                                        does not sort — same assumption as the
     *                                        original TS implementation, which never sorted
     *                                        either). Typed loosely on purpose: this is
     *                                        arbitrary client-authored JSON (a jsonb
     *                                        column), not a schema-enforced shape — each
     *                                        entry is validated defensively below, not
     *                                        assumed to already be a well-formed array.
     * @return array{duration_seconds: int, distance_km: float, ascent_meters: float}
     */
    public function compute(array $locations): array
    {
        $points = $this->cleanup->keptLocations($locations);

        if (count($points) < 2) {
            // Empty array or a single GPS sample: no distance/duration/ascent can be
            // derived from a single point. Returning zeros (not an error/exception) per the
            // ticket's explicit instruction.
            return [
                'duration_seconds' => 0,
                'distance_km' => 0.0,
                'ascent_meters' => 0.0,
            ];
        }

        return [
            'duration_seconds' => $this->computeDurationSeconds($points),
            'distance_km' => $this->computeDistanceKm($points),
            'ascent_meters' => $this->computeAscentMeters($points),
        ];
    }

    /**
     * `(last.time - first.time) / 1000`, clamped to zero: a track with an out-of-order or
     * malformed `time` field must never report a negative duration.
     *
     * @param  array<int, array<string, mixed>>  $points
     */
    private function computeDurationSeconds(array $points): int
    {
        $first = $points[0]['time'] ?? null;
        $last = $points[count($points) - 1]['time'] ?? null;

        if (! is_numeric($first) || ! is_numeric($last)) {
            return 0;
        }

        return max(0, (int) round(((float) $last - (float) $first) / 1000));
    }

    /**
     * Sum of haversine distances between consecutive GPS points.
     *
     * @param  array<int, array<string, mixed>>  $points
     */
    private function computeDistanceKm(array $points): float
    {
        $meters = 0.0;

        for ($i = 1, $count = count($points); $i < $count; $i++) {
            $meters += $this->haversineMeters($points[$i - 1], $points[$i]);
        }

        return $meters / 1000;
    }

    private function haversineMeters(array $from, array $to): float
    {
        $lat1 = deg2rad((float) $from['latitude']);
        $lat2 = deg2rad((float) $to['latitude']);
        $deltaLat = deg2rad((float) $to['latitude'] - (float) $from['latitude']);
        $deltaLon = deg2rad((float) $to['longitude'] - (float) $from['longitude']);

        $a = sin($deltaLat / 2) ** 2
            + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_M * $c;
    }

    /**
     * Sum of positive altitude differences between consecutive points, discarding as noise
     * any difference under `max(altitudeAccuracy of the two points) / 6` — same formula as
     * the ported `getSlope()` in geoutils.service.ts. Points missing `altitude` are skipped
     * (that pair contributes nothing, computation continues with the next pair).
     *
     * @param  array<int, array<string, mixed>>  $points
     */
    private function computeAscentMeters(array $points): float
    {
        $ascent = 0.0;

        for ($i = 1, $count = count($points); $i < $count; $i++) {
            $prev = $points[$i - 1];
            $curr = $points[$i];

            if (! isset($prev['altitude'], $curr['altitude'])
                || ! is_numeric($prev['altitude']) || ! is_numeric($curr['altitude'])) {
                continue;
            }

            $prevAccuracy = is_numeric($prev['altitudeAccuracy'] ?? null) ? (float) $prev['altitudeAccuracy'] : 0.0;
            $currAccuracy = is_numeric($curr['altitudeAccuracy'] ?? null) ? (float) $curr['altitudeAccuracy'] : 0.0;
            $noiseThreshold = max($prevAccuracy, $currAccuracy) / 6;

            $altitudeDifference = (float) $curr['altitude'] - (float) $prev['altitude'];

            if ($altitudeDifference > $noiseThreshold) {
                $ascent += $altitudeDifference;
            }
        }

        return $ascent;
    }
}
