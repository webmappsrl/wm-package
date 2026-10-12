<?php

namespace Wm\WmPackage\Services\Models;

/**
 * Dati tecnici di una UgcTrack registrata (oc:8742), calcolati sui soli punti tenuti dalla
 * pulizia GPS di oc:8719. La specifica completa, che l'app replica per le tracce non ancora
 * sincronizzate, è in docs/knowledge/dati-tecnici-delle-tracce-ugc.md: ogni regola qui deve
 * restare identica a quella pagina e ai casi in tests/fixtures/ugc-track-stats/.
 */
class UgcTrackStatsService
{
    public const DEFAULT_MAX_SPEED_PERCENTILE = 95.0;

    public const DEFAULT_MOVING_MIN_SPEED_KMH = 1.0;

    /** Chiavi calcolate dal servizio DEM (UpdateUgcTrackDemStatsJob), non da questa classe. */
    public const DEM_KEYS = ['ascent', 'descent', 'ele_min', 'ele_max', 'ele_from', 'ele_to'];

    private const EARTH_RADIUS_METERS = 6371000.0;

    public function __construct(private UgcTrackCleanupService $cleanup) {}

    public static function make(): self
    {
        return new self(UgcTrackCleanupService::make());
    }

    public function maxSpeedPercentile(): float
    {
        $value = config('wm-package.ugc_track_max_speed_percentile');

        return is_numeric($value) && $value > 0 && $value <= 100 ? (float) $value : self::DEFAULT_MAX_SPEED_PERCENTILE;
    }

    public function movingMinSpeedKmh(): float
    {
        $value = config('wm-package.ugc_track_moving_min_speed_kmh');

        return is_numeric($value) && $value > 0 ? (float) $value : self::DEFAULT_MOVING_MIN_SPEED_KMH;
    }

    /**
     * @param  array<int, mixed>  $locations
     * @return array<string, mixed>|null
     */
    public function localStats(array $locations): ?array
    {
        $locations = array_values($locations);
        $flags = $this->cleanup->keptFlags($locations);
        $kept = array_values(array_filter($locations, static fn ($l, $i) => $flags[$i], ARRAY_FILTER_USE_BOTH));
        if (count($kept) < 2) {
            return null;
        }

        $maxAccuracy = $this->cleanup->maxAccuracyMeters();
        $minSpeed = $this->movingMinSpeedKmh();

        $meters = 0.0;
        $movingSeconds = 0.0;
        $hasTimedSegment = false;
        $goodMeters = 0.0;
        $goodSeconds = 0.0;
        $segmentSpeeds = [];

        for ($i = 1, $n = count($kept); $i < $n; $i++) {
            $segment = $this->haversineMeters($kept[$i - 1], $kept[$i]);
            $meters += $segment;

            $dt = $this->deltaSeconds($kept[$i - 1], $kept[$i]);
            if ($dt === null) {
                continue;
            }
            $hasTimedSegment = true;
            $kmh = $segment / $dt * 3.6;
            $segmentSpeeds[] = $kmh;

            if ($kmh < $minSpeed) {
                continue;
            }
            $movingSeconds += $dt;
            if ($this->goodAccuracy($kept[$i - 1], $maxAccuracy) && $this->goodAccuracy($kept[$i], $maxAccuracy)) {
                $goodMeters += $segment;
                $goodSeconds += $dt;
            }
        }

        $speeds = array_values(array_filter(
            array_map(static fn ($l) => $l['speed'] ?? null, $kept),
            static fn ($v) => is_numeric($v) && $v >= 0
        ));
        $maxSpeed = $this->percentile($speeds !== [] ? $speeds : $segmentSpeeds, $this->maxSpeedPercentile());

        return [
            'distance' => round($meters / 1000, 2),
            ...array_fill_keys(self::DEM_KEYS, null),
            'duration' => $this->durationMinutes($kept),
            'duration_moving' => $hasTimedSegment ? (int) round($movingSeconds / 60) : null,
            'avg_speed' => $goodSeconds > 0 ? round($goodMeters / $goodSeconds * 3.6, 1) : null,
            'max_speed' => $maxSpeed === null ? null : round($maxSpeed, 1),
            'computed_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Percentile nearest-rank: rango = ceil(p/100 * n), minimo 1, sui valori ordinati.
     *
     * @param  array<int, int|float|string>  $values
     */
    public function percentile(array $values, float $p): ?float
    {
        if ($values === []) {
            return null;
        }
        $sorted = array_map('floatval', $values);
        sort($sorted);
        $rank = max(1, (int) ceil($p / 100 * count($sorted)));

        return $sorted[min($rank, count($sorted)) - 1];
    }

    /** @param  list<array<string, mixed>>  $kept */
    private function durationMinutes(array $kept): ?int
    {
        $times = array_values(array_filter(array_map(static fn ($l) => $l['time'] ?? null, $kept), 'is_numeric'));
        if (count($times) < 2) {
            return null;
        }
        $seconds = ((float) end($times) - (float) $times[0]) / 1000;

        return $seconds > 0 ? (int) round($seconds / 60) : null;
    }

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     */
    private function deltaSeconds(array $from, array $to): ?float
    {
        if (! is_numeric($from['time'] ?? null) || ! is_numeric($to['time'] ?? null)) {
            return null;
        }
        $dt = ((float) $to['time'] - (float) $from['time']) / 1000;

        return $dt > 0 ? $dt : null;
    }

    /**
     * stats nel formato di StoryShareImageService (oc:8183): l'immagine condivisa mostra gli
     * stessi numeri dell'app e di Nova.
     *
     * @param  array<string, mixed>  $stats
     * @return array{duration_seconds: int|null, distance_km: float|null, ascent_meters: float|null}
     */
    public function forShareImage(array $stats): array
    {
        return [
            'duration_seconds' => is_numeric($stats['duration'] ?? null) ? (int) $stats['duration'] * 60 : null,
            'distance_km' => is_numeric($stats['distance'] ?? null) ? (float) $stats['distance'] : null,
            'ascent_meters' => is_numeric($stats['ascent'] ?? null) ? (float) $stats['ascent'] : null,
        ];
    }

    /** @param  array<string, mixed>  $point */
    private function goodAccuracy(array $point, float $maxAccuracy): bool
    {
        return is_numeric($point['accuracy'] ?? null) && (float) $point['accuracy'] <= $maxAccuracy;
    }

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     */
    private function haversineMeters(array $from, array $to): float
    {
        $lat1 = deg2rad((float) $from['latitude']);
        $lat2 = deg2rad((float) $to['latitude']);
        $dLat = $lat2 - $lat1;
        $dLon = deg2rad((float) $to['longitude'] - (float) $from['longitude']);
        $h = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLon / 2) ** 2;

        return 2 * self::EARTH_RADIUS_METERS * asin(min(1.0, sqrt($h)));
    }
}
