<?php

namespace Wm\WmPackage\Services\Models;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\UgcTrack;

/**
 * Regole di pulizia dei punti GPS di una traccia UGC (oc:8719).
 *
 * L'app registra ogni posizione ricevuta dal telefono senza guardare l'accuracy: quando il GPS
 * perde il segnale arrivano posizioni da rete cellulare con errori di chilometri. Qui si decide
 * quali punti di `properties.locations` si tengono; tutto il resto del package (salvataggio,
 * command, statistiche di condivisione, Nova) usa queste regole, non ne ha di proprie.
 *
 * Ogni punto è di uno di tre tipi:
 * - invalido, sempre scartato: non è un array, latitudine o longitudine non numeriche, non finite
 *   o fuori range (±90, ±180), oppure entrambe a 0 (l'app usa [0, 0, 0] quando la registrazione
 *   parte senza posizione);
 * - sospetto: valido, con accuracy numerica >= 0 e oltre `maxAccuracyMeters()`;
 * - buono: valido e non sospetto (accuracy assente, non numerica o negativa: non c'è modo di
 *   giudicare il punto, quindi si tiene).
 *
 * I punti buoni si tengono sempre. Un sospetto si tiene se dista al massimo
 * `maxDeviationMeters()` dal tratto fra l'ultimo punto tenuto prima di lui (ancora sinistra, può
 * essere un sospetto tenuto) e il primo punto buono dopo di lui (ancora destra). In testa o in coda
 * si misura dall'unica ancora che c'è; senza alcun punto buono il sospetto si scarta. L'accuracy
 * dice che un punto è sospetto, la distanza dice se è davvero sbagliato: un punto con accuracy
 * 100 m che sta sul percorso non ha motivo di sparire.
 *
 * Nessuna regola su tempo o distanza fra punti consecutivi: l'app non salva le pause, e un buco
 * di tempo seguito da uno spostamento è indistinguibile da un segnale perso. Un filtro sui salti
 * scarterebbe punti giusti.
 */
class UgcTrackCleanupService
{
    /**
     * Distanza (in gradi, circa 0,1 m) sotto la quale due geometrie si considerano uguali.
     * Le coordinate salvate dall'app hanno 9 decimali, l'EWKT ne scrive 8: un confronto esatto
     * segnalerebbe come cambiate tracce identiche al millimetro. Sotto ~10 cm una differenza
     * GPS non ha significato.
     */
    public const SAME_GEOMETRY_TOLERANCE_DEGREES = 1e-6;

    /** Soglia di accuracy (metri) oltre la quale un punto è sospetto, se la config non ne dà una valida. */
    public const DEFAULT_MAX_ACCURACY_METERS = 40.0;

    /** Distanza (metri) dal percorso oltre la quale un punto sospetto si scarta, se la config non ne dà una valida. */
    public const DEFAULT_MAX_DEVIATION_METERS = 50.0;

    private const EARTH_RADIUS_METERS = 6371000.0;

    private const INVALID = 0;

    private const SUSPECT = 1;

    private const GOOD = 2;

    public static function make(): self
    {
        return new self;
    }

    public function maxAccuracyMeters(): float
    {
        return $this->positiveOr(config('wm-package.ugc_track_max_accuracy_meters'), self::DEFAULT_MAX_ACCURACY_METERS);
    }

    public function maxDeviationMeters(): float
    {
        return $this->positiveOr(config('wm-package.ugc_track_max_deviation_meters'), self::DEFAULT_MAX_DEVIATION_METERS);
    }

    /**
     * @return list<mixed>|null
     */
    public function locationsOf(UgcTrack $track): ?array
    {
        $locations = $track->properties['locations'] ?? null;

        return is_array($locations) && $locations !== [] ? array_values($locations) : null;
    }

    /**
     * Per ogni punto di `array_values($locations)`, nello stesso ordine, vero se si tiene.
     * È l'unico posto in cui si decide: `keptLocations()`, `gaps()` e `summary()` partono da qui.
     *
     * @param  array<int, mixed>  $locations
     * @return list<bool>
     */
    public function keptFlags(array $locations): array
    {
        $locations = array_values($locations);
        $kinds = array_map(fn ($location) => $this->kindOf($location), $locations);
        $maxDeviation = $this->maxDeviationMeters();

        $flags = [];
        $leftAnchor = null;
        $nextGood = null;

        foreach ($locations as $i => $location) {
            if ($kinds[$i] === self::INVALID) {
                $flags[] = false;

                continue;
            }

            if ($kinds[$i] === self::GOOD) {
                $flags[] = true;
                $leftAnchor = $location;

                continue;
            }

            // Primo punto buono dopo il sospetto: si ricerca solo quando quello noto è rimasto indietro.
            if ($nextGood === null || $nextGood <= $i) {
                $nextGood = $this->nextGoodIndex($kinds, $i + 1);
            }
            $rightAnchor = $nextGood < count($locations) ? $locations[$nextGood] : null;

            $deviation = $this->deviationMeters($location, $leftAnchor, $rightAnchor);
            $keep = $deviation !== null && $deviation <= $maxDeviation;

            $flags[] = $keep;
            if ($keep) {
                $leftAnchor = $location;
            }
        }

        return $flags;
    }

    /**
     * @param  array<int, mixed>  $locations
     * @return list<array<string, mixed>>
     */
    public function keptLocations(array $locations): array
    {
        $locations = array_values($locations);
        $flags = $this->keptFlags($locations);

        return array_values(array_filter($locations, static fn ($location, $i) => $flags[$i], ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Tratti ricostruiti: per ogni sequenza di punti scartati compresa fra due punti tenuti, il
     * segmento che li unisce. Le sequenze in testa o in coda non producono tratti.
     *
     * @param  array<int, mixed>  $locations
     * @return list<array{from: array<string, mixed>, to: array<string, mixed>, discarded: int, seconds: int, max_accuracy: float}>
     */
    public function gaps(array $locations): array
    {
        $gaps = [];
        $previousKept = null;
        $run = [];

        $locations = array_values($locations);
        $flags = $this->keptFlags($locations);

        foreach ($locations as $i => $location) {
            if (! $flags[$i]) {
                $run[] = $location;

                continue;
            }

            if ($run !== [] && $previousKept !== null) {
                $gaps[] = [
                    'from' => $previousKept,
                    'to' => $location,
                    'discarded' => count($run),
                    'seconds' => max(0, (int) round(((float) ($location['time'] ?? 0) - (float) ($previousKept['time'] ?? 0)) / 1000)),
                    'max_accuracy' => $this->maxAccuracy($run),
                ];
            }

            $run = [];
            $previousKept = $location;
        }

        return $gaps;
    }

    /**
     * @param  array<int, mixed>  $locations
     * @return array{total: int, discarded: int, max_discarded_accuracy: float, length_before_km: float, length_after_km: float}
     */
    public function summary(array $locations): array
    {
        $locations = array_values($locations);
        $flags = $this->keptFlags($locations);
        $kept = array_values(array_filter($locations, static fn ($location, $i) => $flags[$i], ARRAY_FILTER_USE_BOTH));
        $discarded = array_values(array_filter($locations, static fn ($location, $i) => ! $flags[$i], ARRAY_FILTER_USE_BOTH));
        // «Prima» comprende anche i punti (0,0) e fuori range: è la linea che l'app ha disegnato.
        // Restano fuori solo i punti senza coordinate finite, che non si possono misurare.
        $withCoordinates = array_values(array_filter($locations, fn ($location) => $this->hasFiniteCoordinates($location)));

        return [
            'total' => count($locations),
            'discarded' => count($discarded),
            'max_discarded_accuracy' => $this->maxAccuracy($discarded),
            'length_before_km' => $this->lengthKm($withCoordinates),
            'length_after_km' => $this->lengthKm($kept),
        ];
    }

    /**
     * EWKT della geometria pulita. Costruito solo da numeri (sprintf con %F, indipendente dal
     * locale), quindi sicuro da inserire in un'espressione SQL.
     *
     * @param  list<array<string, mixed>>  $kept
     */
    public function ewkt(array $kept): ?string
    {
        if (count($kept) < 2) {
            return null;
        }

        // Un punto senza quota prende quella del punto precedente: uno 0 in mezzo a quote di
        // montagna disegnerebbe un pozzo nel profilo altimetrico.
        $altitude = 0.0;
        $coordinates = [];
        foreach ($kept as $location) {
            if (is_numeric($location['altitude'] ?? null)) {
                $altitude = (float) $location['altitude'];
            }
            $coordinates[] = sprintf('%.8F %.8F %.3F', (float) $location['longitude'], (float) $location['latitude'], $altitude);
        }
        $coordinates = implode(', ', $coordinates);

        return "SRID=4326;MULTILINESTRING Z (({$coordinates}))";
    }

    public function ewktFor(UgcTrack $track): ?string
    {
        $locations = $this->locationsOf($track);

        return $locations === null ? null : $this->ewkt($this->keptLocations($locations));
    }

    public function geometryExpressionFor(UgcTrack $track): ?Expression
    {
        $ewkt = $this->ewktFor($track);

        return $ewkt === null ? null : DB::raw("ST_GeomFromEWKT('{$ewkt}')");
    }

    /**
     * Vero se la geometria salvata è diversa da quella pulita. Confronto PostGIS (non fra stringhe)
     * con tolleranza: due geometrie a distanza di Hausdorff <= SAME_GEOMETRY_TOLERANCE_DEGREES sono uguali.
     */
    public function wouldChange(UgcTrack $track): bool
    {
        $ewkt = $this->ewktFor($track);
        if ($ewkt === null || ! $track->exists) {
            return false;
        }

        $row = DB::selectOne(
            "SELECT (geometry IS NULL OR ST_HausdorffDistance(geometry::geometry, ST_GeomFromEWKT(?)) > ?) AS changes FROM {$track->getTable()} WHERE id = ?",
            [$ewkt, self::SAME_GEOMETRY_TOLERANCE_DEGREES, $track->id]
        );

        return (bool) ($row->changes ?? false);
    }

    private function positiveOr(mixed $value, float $default): float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : $default;
    }

    private function hasFiniteCoordinates(mixed $location): bool
    {
        return is_array($location)
            && is_numeric($location['latitude'] ?? null)
            && is_numeric($location['longitude'] ?? null)
            && is_finite((float) $location['latitude'])
            && is_finite((float) $location['longitude']);
    }

    private function kindOf(mixed $location): int
    {
        if (! $this->hasFiniteCoordinates($location)) {
            return self::INVALID;
        }

        $lat = (float) $location['latitude'];
        $lon = (float) $location['longitude'];
        if (abs($lat) > 90 || abs($lon) > 180 || ($lat === 0.0 && $lon === 0.0)) {
            return self::INVALID;
        }

        $accuracy = $location['accuracy'] ?? null;
        if (is_numeric($accuracy) && (float) $accuracy >= 0 && (float) $accuracy > $this->maxAccuracyMeters()) {
            return self::SUSPECT;
        }

        return self::GOOD;
    }

    /**
     * @param  list<int>  $kinds
     */
    private function nextGoodIndex(array $kinds, int $from): int
    {
        $n = count($kinds);
        for ($j = $from; $j < $n; $j++) {
            if ($kinds[$j] === self::GOOD) {
                return $j;
            }
        }

        return $n;
    }

    /**
     * Distanza in metri del punto dal tratto fra le due ancore, o dall'unica ancora presente;
     * null se mancano entrambe.
     *
     * @param  array<string, mixed>  $point
     * @param  array<string, mixed>|null  $left
     * @param  array<string, mixed>|null  $right
     */
    private function deviationMeters(array $point, ?array $left, ?array $right): ?float
    {
        if ($left === null && $right === null) {
            return null;
        }

        $from = $left ?? $right;
        $to = $right ?? $left;

        return $this->pointToSegmentMeters($point, $from, $to);
    }

    /**
     * Distanza punto-segmento con una proiezione equirettangolare locale centrata sul punto:
     * x = Δlon·cos(lat media)·R, y = Δlat·R. Alle distanze in gioco (decine di metri, qualche
     * chilometro) l'errore è trascurabile, e non serve una query al DB.
     *
     * @param  array<string, mixed>  $point
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     */
    private function pointToSegmentMeters(array $point, array $from, array $to): float
    {
        $lat = (float) $point['latitude'];
        $lon = (float) $point['longitude'];
        $cos = cos(deg2rad(($lat + (float) $from['latitude'] + (float) $to['latitude']) / 3));

        $ax = deg2rad((float) $from['longitude'] - $lon) * $cos * self::EARTH_RADIUS_METERS;
        $ay = deg2rad((float) $from['latitude'] - $lat) * self::EARTH_RADIUS_METERS;
        $bx = deg2rad((float) $to['longitude'] - $lon) * $cos * self::EARTH_RADIUS_METERS;
        $by = deg2rad((float) $to['latitude'] - $lat) * self::EARTH_RADIUS_METERS;

        $dx = $bx - $ax;
        $dy = $by - $ay;
        $lengthSquared = $dx * $dx + $dy * $dy;

        // Proiezione del punto (l'origine) sulla retta del tratto, limitata agli estremi.
        $t = $lengthSquared > 0 ? max(0.0, min(1.0, -($ax * $dx + $ay * $dy) / $lengthSquared)) : 0.0;

        return hypot($ax + $t * $dx, $ay + $t * $dy);
    }

    /**
     * @param  list<mixed>  $locations
     */
    private function maxAccuracy(array $locations): float
    {
        $values = array_map(
            static fn ($location) => is_array($location) && is_numeric($location['accuracy'] ?? null) ? (float) $location['accuracy'] : 0.0,
            $locations
        );

        return $values === [] ? 0.0 : max($values);
    }

    /**
     * @param  list<array<string, mixed>>  $points
     */
    private function lengthKm(array $points): float
    {
        $meters = 0.0;
        for ($i = 1, $n = count($points); $i < $n; $i++) {
            $meters += $this->haversineMeters($points[$i - 1], $points[$i]);
        }

        return $meters / 1000;
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
