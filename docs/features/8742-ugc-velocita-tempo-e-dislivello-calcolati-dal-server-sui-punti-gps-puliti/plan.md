> Ticket: oc:8742

# UGC: `properties.stats` calcolato dal server — piano di implementazione

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** il server calcola e salva in `properties.stats` delle UgcTrack registrate distanza,
dislivelli (DEM), tempi, velocità media e massima sui soli punti GPS tenuti dalla pulizia di
oc:8719; Nova e l'immagine di condivisione li mostrano, il `config.json` espone i parametri.

**Architecture:** un servizio puro (`UgcTrackStatsService`) calcola i valori locali da
`properties.locations`; l'observer `saving` già esistente lo chiama solo quando i punti GPS
cambiano e conserva `stats` del DB negli altri casi; un job sulla coda `dem` aggiunge le chiavi
DEM con un UPDATE SQL mirato. Il command di oc:8719 popola le tracce esistenti.

**Tech Stack:** Laravel 12, Nova 5, PostgreSQL/PostGIS, Pest.

**Spec:** [overview.md](overview.md) (approvata). Specifica del calcolo da scrivere nel Task 9:
`docs/knowledge/dati-tecnici-delle-tracce-ugc.md`.

## Global Constraints

- Tutto il codice in `wm-package`. In camminiditalia solo bump del gitlink e riga nel `CLAUDE.md`.
- Branch `feature/oc-8742-ugc-velocita-tempo-e-dislivello-calcolati-dal-server` da `origin/develop`
  in **entrambi** i repo; in camminiditalia si lascia `Passaporto` e si riallinea il submodule
  (`git submodule update`), gitlink attuale `41d0e16d` uguale su entrambi i branch.
- Commit: `feat(oc:8742): …`. **Nessun commit durante l'esecuzione**: i comandi di commit di questo
  piano sono istruzioni per il dev, eseguite solo dopo il review-gate.
- PHP minimo `>8.1`: nessuna `const` dentro un trait.
- Geometrie PostGIS solo via SQL, mai attraverso l'ORM.
- Traduzioni in `resources/lang/*.json`: chiave inglese, voci in it, de, es, fr (en uguale alla chiave).
- Commenti e documentazione in italiano.
- `composer format` (Pint) riformatta tutto il repo: dopo, `git status` e scarto dei file estranei.
- Test del package (da `/Users/bongiu/Documents/camminiditalia`):
  `docker run --rm --network camminiditalia_default -e DB_HOST=postgres-camminiditalia -v "$PWD/wm-package:/app" -w /app wm-phpfpm:8.4 vendor/bin/pest <file>`
  — d'ora in poi abbreviato `PEST <file>`.
- Chiavi di `stats` (nomi e unità fissati in overview): `distance` km, `ascent` `descent`
  `ele_min` `ele_max` `ele_from` `ele_to` m, `duration` `duration_moving` minuti interi,
  `avg_speed` `max_speed` km/h, `points_total`, `points_discarded`, `computed_at` ISO 8601 UTC.
- Un valore non calcolabile è `null`, mai `0`.

## Review Focus

1. **Edit dall'app con gli stessi punti e la geometria grezza** → la geometria va ancora ripulita
   (come oggi) e `stats` resta quello salvato, DEM compreso. Test nel Task 3.
2. **`stats` mandato dal client nello store di una traccia nuova o nel merge per uuid di oc:8718**
   → mai salvato. Test nel Task 3.
3. **Job DEM che arriva dopo che i punti sono cambiati di nuovo** → non deve scrivere dislivelli
   della geometria vecchia. Test nel Task 4 (guardia su `computed_at`).
4. **Punti con `time` mancante o non crescente** → nessun errore, i tratti senza Δt positivo non
   contano nei tempi e nelle velocità. Test nel Task 1.
5. **Traccia senza `locations` che aveva `stats`** (per esempio dal client) → `stats` rimosso.
   Test nel Task 3.

---

## File

| File | Responsabilità |
|---|---|
| `src/Services/Models/UgcTrackStatsService.php` (nuovo) | calcolo dei valori locali, chiavi DEM, mappatura per l'immagine di condivisione |
| `src/Observers/UgcTrackGeometryCleanupObserver.php` | quando ricalcolare `stats`, conservazione di quello del DB, accodamento del DEM |
| `src/Jobs/UpdateUgcTrackDemStatsJob.php` (nuovo) | chiamata DEM e UPDATE SQL delle sole chiavi DEM |
| `src/Jobs/CleanUgcTrackGeometryJob.php`, `src/Commands/WmCleanUgcTrackGeometryCommand.php` | popolamento delle tracce esistenti |
| `config/wm-package.php` | due parametri nuovi |
| `src/Services/Models/App/AppConfigService.php` | `GEOLOCATION.record.stats` |
| `src/Nova/UgcTrack.php`, `resources/lang/*.json` | campo «Technical data» nel dettaglio |
| `src/Http/Controllers/Api/ShareStoryImageController.php` | immagine di condivisione da `stats` |
| `tests/Fixtures/ugc-track-stats/*.json` (nuovi) | casi condivisi con wm-core |
| `docs/knowledge/dati-tecnici-delle-tracce-ugc.md` (nuovo) | specifica del calcolo |

---

### Task 1: `UgcTrackStatsService` — calcolo dei valori locali

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1-e-2-points_total-e-points_discarded-tolti-da-stats)

**Files:**
- Create: `src/Services/Models/UgcTrackStatsService.php`
- Modify: `config/wm-package.php` (dopo `ugc_track_max_deviation_meters`, riga 22)
- Test: `tests/Unit/Services/UgcTrackStatsServiceTest.php`

**Interfaces:**
- Consumes: `UgcTrackCleanupService::make()`, `->keptFlags(array)`, `->maxAccuracyMeters()`.
- Produces:
  - `UgcTrackStatsService::make(): self`
  - `const DEM_KEYS = ['ascent', 'descent', 'ele_min', 'ele_max', 'ele_from', 'ele_to']`
  - `const DEFAULT_MAX_SPEED_PERCENTILE = 95.0`, `const DEFAULT_MOVING_MIN_SPEED_KMH = 1.0`
  - `maxSpeedPercentile(): float`, `movingMinSpeedKmh(): float`
  - `localStats(array $locations): ?array` — `null` se meno di 2 punti tenuti; altrimenti tutte le
    chiavi di `stats`, con le chiavi DEM a `null`.
  - `percentile(array $values, float $p): ?float` (pubblico per i test e la specifica)

Regole fissate qui e copiate nella specifica del Task 9:
- distanza fra due punti: haversine con R = 6.371.000 m;
- un tratto (i-1 → i) ha Δt = (time_i − time_{i-1}) / 1000 s; vale per i tempi solo se entrambi i
  `time` sono numerici e Δt > 0;
- tratto **in movimento**: Δt > 0 e velocità del tratto (m/Δt × 3,6) **≥** `moving_min_speed`;
- tratto **con GPS buono**: entrambi i punti hanno `accuracy` numerica **≤** `max_accuracy`;
- `duration`: ultimo `time` numerico meno il primo, fra i punti tenuti, in minuti arrotondati
  (`round`); `null` se ≤ 0 o se mancano;
- `duration_moving`: somma dei Δt dei tratti in movimento, minuti arrotondati; `null` se non c'è
  nessun tratto con Δt valido;
- `avg_speed`: (somma metri dei tratti in movimento con GPS buono) / (somma dei loro Δt) × 3,6,
  arrotondata a 1 decimale; `null` se quel tempo è 0;
- `max_speed`: percentile `max_speed_percentile` dei valori `speed` numerici e ≥ 0 dei punti
  tenuti; se nessun punto ha `speed`, dello stesso percentile delle velocità dei tratti con
  Δt > 0; `null` se nessun valore. 1 decimale;
- percentile **nearest-rank**: valori ordinati crescenti, rango = ⌈p/100 × n⌉ (minimo 1), valore
  in posizione rango − 1;
- `distance`: somma dei tratti fra punti tenuti consecutivi, in km a 2 decimali;
- `points_total`: numero di `locations`; `points_discarded`: punti con flag `false`.

- [ ] **Step 1: aggiungere i parametri in `config/wm-package.php`**

Dopo la riga di `ugc_track_max_deviation_meters`:

```php
    // oc:8742: percentile del campo speed dei punti tenuti usato come velocità massima di una
    // traccia UGC. Vuota, <= 0 o > 100: vale il default.
    'ugc_track_max_speed_percentile' => (float) env('UGC_TRACK_MAX_SPEED_PERCENTILE', UgcTrackStatsService::DEFAULT_MAX_SPEED_PERCENTILE),
    // oc:8742: un tratto fra due punti conta come movimento se la sua velocità (km/h) è almeno
    // questa. Vuota o <= 0: vale il default.
    'ugc_track_moving_min_speed_kmh' => (float) env('UGC_TRACK_MOVING_MIN_SPEED_KMH', UgcTrackStatsService::DEFAULT_MOVING_MIN_SPEED_KMH),
```

e in testa al file, accanto all'`use` di `UgcTrackCleanupService`:

```php
use Wm\WmPackage\Services\Models\UgcTrackStatsService;
```

- [ ] **Step 2: scrivere i test che falliscono**

`tests/Unit/Services/UgcTrackStatsServiceTest.php`:

```php
<?php

declare(strict_types=1);

use Wm\WmPackage\Services\Models\UgcTrackStatsService;

beforeEach(function () {
    config()->set('wm-package.ugc_track_max_accuracy_meters', 40.0);
    config()->set('wm-package.ugc_track_max_deviation_meters', 50.0);
    config()->set('wm-package.ugc_track_max_speed_percentile', 95.0);
    config()->set('wm-package.ugc_track_moving_min_speed_kmh', 1.0);
    $this->stats = UgcTrackStatsService::make();
});

/** Gradi di latitudine pari a $meters metri verso nord (R = 6371000). */
function statsNorth(float $meters): float
{
    return rad2deg($meters / 6371000.0);
}

/** Punto per i test di stats: lat cresce di $meters dal punto di partenza 43,0 / 13,0. */
function statsPoint(float $meters, int $timeMs, ?float $speed = 4.0, float $accuracy = 5.0): array
{
    $point = ['latitude' => 43.0 + statsNorth($meters), 'longitude' => 13.0, 'time' => $timeMs, 'accuracy' => $accuracy, 'altitude' => 100.0];
    if ($speed !== null) {
        $point['speed'] = $speed;
    }

    return $point;
}

it('restituisce null con meno di due punti tenuti', function () {
    expect($this->stats->localStats([statsPoint(0, 0)]))->toBeNull();
    expect($this->stats->localStats([]))->toBeNull();
});

it('calcola distanza, tempi e media su un cammino regolare', function () {
    // 11 punti, 10 m ogni 10 s: 100 m in 100 s = 3,6 km/h
    $points = array_map(fn ($i) => statsPoint($i * 10, $i * 10_000), range(0, 10));

    $s = $this->stats->localStats($points);

    expect($s['distance'])->toBe(0.1);
    expect($s['duration'])->toBe(2);          // 100 s → 1,67 min → 2
    expect($s['duration_moving'])->toBe(2);
    expect($s['avg_speed'])->toBe(3.6);
    expect($s['max_speed'])->toBe(4.0);
    expect($s['points_total'])->toBe(11);
    expect($s['points_discarded'])->toBe(0);
    foreach (UgcTrackStatsService::DEM_KEYS as $key) {
        expect($s)->toHaveKey($key);
        expect($s[$key])->toBeNull();
    }
    expect($s['computed_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});

it('esclude dal tempo effettivo una sosta registrata come un solo tratto lento', function () {
    // 100 m in 100 s, poi 10 m in 26 minuti (distance filter), poi 100 m in 100 s
    $points = array_map(fn ($i) => statsPoint($i * 10, $i * 10_000), range(0, 10));
    $points[] = statsPoint(110, 100_000 + 26 * 60_000);
    foreach (range(1, 10) as $i) {
        $points[] = statsPoint(110 + $i * 10, 100_000 + 26 * 60_000 + $i * 10_000);
    }

    $s = $this->stats->localStats($points);

    expect($s['duration'])->toBe(29);          // 200 s + 26 min
    expect($s['duration_moving'])->toBe(3);    // 200 s → 3,33 → 3
    expect($s['avg_speed'])->toBe(3.6);
});

it('conta come movimento un lungo intervallo con centinaia di metri (cammino senza segnale)', function () {
    $points = [statsPoint(0, 0), statsPoint(10, 10_000), statsPoint(231, 610_000), statsPoint(241, 620_000)];

    $s = $this->stats->localStats($points);

    expect($s['duration_moving'])->toBe(10);   // 620 s, tutti in movimento
});

it('calcola la media sui soli tratti con GPS buono', function () {
    // tratti buoni: 10 m ogni 10 s; un tratto con accuracy 45 m (sospetto ma tenuto) salta 30 m in 1 s
    $points = array_map(fn ($i) => statsPoint($i * 10, $i * 10_000), range(0, 5));
    $points[] = statsPoint(80, 51_000, 0.0, 45.0);
    foreach (range(1, 5) as $i) {
        $points[] = statsPoint(80 + $i * 10, 51_000 + $i * 10_000);
    }

    $s = $this->stats->localStats($points);

    expect($s['avg_speed'])->toBe(3.6);
    expect($s['points_discarded'])->toBe(0);
});

it('usa il percentile del campo speed come velocità massima, ignorando un picco isolato', function () {
    $points = array_map(fn ($i) => statsPoint($i * 10, $i * 10_000, 4.0), range(0, 39));
    $points[20]['speed'] = 890.0;

    expect($this->stats->localStats($points)['max_speed'])->toBe(4.0);
});

it('senza campo speed usa il percentile della velocità fra punti consecutivi', function () {
    $points = array_map(fn ($i) => statsPoint($i * 10, $i * 10_000, null), range(0, 10));

    expect($this->stats->localStats($points)['max_speed'])->toBe(3.6);
});

it('ignora i tratti con time mancante o non crescente senza errori', function () {
    $points = [statsPoint(0, 0), statsPoint(10, 10_000), statsPoint(20, 5_000), statsPoint(30, 30_000)];
    unset($points[3]['time']);

    $s = $this->stats->localStats($points);

    expect($s['distance'])->toBe(0.03);
    expect($s['duration'])->toBe(0);           // primo 0, ultimo time numerico 5 s → round(0,08) = 0
    expect($s['avg_speed'])->toBe(3.6);        // solo il primo tratto è valido
});

it('calcola il percentile con il metodo nearest-rank', function () {
    expect($this->stats->percentile([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 95))->toBe(10.0);
    expect($this->stats->percentile([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 50))->toBe(5.0);
    expect($this->stats->percentile([7], 95))->toBe(7.0);
    expect($this->stats->percentile([], 95))->toBeNull();
});
```

Nota sul test «ignora i tratti»: `duration` = (5 000 − 0) / 60 000 min = 0,08 → 0. Un valore 0
calcolato **non** è un dato mancante; `null` solo se Δ ≤ 0 — qui Δ = 5 s > 0, quindi `0`.

- [ ] **Step 3: eseguire i test e vederli fallire**

Run: `PEST tests/Unit/Services/UgcTrackStatsServiceTest.php`
Expected: FAIL, `Class "Wm\WmPackage\Services\Models\UgcTrackStatsService" not found`.

- [ ] **Step 4: scrivere il servizio**

`src/Services/Models/UgcTrackStatsService.php`:

```php
<?php

namespace Wm\WmPackage\Services\Models;

/**
 * Dati tecnici di una UgcTrack registrata (oc:8742), calcolati sui soli punti tenuti dalla
 * pulizia GPS di oc:8719. La specifica completa, che l'app replica per le tracce non ancora
 * sincronizzate, è in docs/knowledge/dati-tecnici-delle-tracce-ugc.md: ogni regola qui deve
 * restare identica a quella pagina e ai casi in tests/Fixtures/ugc-track-stats/.
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
            'points_total' => count($locations),
            'points_discarded' => count($locations) - count($kept),
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
```

Nota sul test «ignora i tratti»: `durationMinutes` usa il primo e l'ultimo `time` numerico (0 e
5 000 → 5 s → 0 min, valore calcolato); il punto 3 senza `time` non entra nei tempi ma entra
nella distanza. Se il test di `duration` non torna, correggere il **test**, non la regola, e
annotarlo in `notes.md`.

- [ ] **Step 5: eseguire i test e vederli passare**

Run: `PEST tests/Unit/Services/UgcTrackStatsServiceTest.php`
Expected: PASS (9 test).

- [ ] **Step 6: commit (istruzione per il dev)**

```bash
git -C wm-package add config/wm-package.php src/Services/Models/UgcTrackStatsService.php tests/Unit/Services/UgcTrackStatsServiceTest.php
git -C wm-package commit -m "feat(oc:8742): calcolo dei dati tecnici delle UgcTrack sui punti tenuti"
```

---

### Task 2: casi di test condivisi con wm-core

**Files:**
- Create: `tests/Fixtures/ugc-track-stats/README.md`, `tests/Fixtures/ugc-track-stats/*.json`
- Test: `tests/Unit/Services/UgcTrackStatsFixturesTest.php`

**Interfaces:**
- Consumes: `UgcTrackStatsService::localStats()`.
- Produces: formato dei file, letto anche dai test di wm-core (oc:8743):

```json
{
  "description": "testo",
  "params": {"max_accuracy": 40, "max_deviation": 50, "max_speed_percentile": 95, "moving_min_speed": 1},
  "locations": [ {"time": 0, "latitude": 43.0, "longitude": 13.0, "accuracy": 5, "altitude": 100, "speed": 4} ],
  "expected_kept": [true],
  "expected": {"distance": 0.1, "duration": 2, "duration_moving": 2, "avg_speed": 3.6, "max_speed": 4.0, "points_total": 11, "points_discarded": 0}
}
```

`expected` contiene solo le chiavi locali senza `computed_at` (le chiavi DEM non si replicano).

- [ ] **Step 1: scrivere il test che legge le fixture**

```php
<?php

declare(strict_types=1);

use Wm\WmPackage\Services\Models\UgcTrackCleanupService;
use Wm\WmPackage\Services\Models\UgcTrackStatsService;

dataset('ugc-track-stats-fixtures', function () {
    foreach (glob(__DIR__.'/../../Fixtures/ugc-track-stats/*.json') as $file) {
        yield basename($file) => [$file];
    }
});

it('rispetta il caso condiviso', function (string $file) {
    $case = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    config()->set('wm-package.ugc_track_max_accuracy_meters', (float) $case['params']['max_accuracy']);
    config()->set('wm-package.ugc_track_max_deviation_meters', (float) $case['params']['max_deviation']);
    config()->set('wm-package.ugc_track_max_speed_percentile', (float) $case['params']['max_speed_percentile']);
    config()->set('wm-package.ugc_track_moving_min_speed_kmh', (float) $case['params']['moving_min_speed']);

    expect(UgcTrackCleanupService::make()->keptFlags($case['locations']))->toBe($case['expected_kept']);

    $stats = UgcTrackStatsService::make()->localStats($case['locations']);
    if ($case['expected'] === null) {
        expect($stats)->toBeNull();

        return;
    }
    foreach ($case['expected'] as $key => $value) {
        expect($stats[$key])->toBe($value, "$key in ".basename($file));
    }
})->with('ugc-track-stats-fixtures');
```

- [ ] **Step 2: generare le fixture dai dati di prova**

Cinque casi, generati con uno script tinker usa e getta (non versionato) che costruisce i
punti, chiama `keptFlags()` e `localStats()` e scrive il JSON; **ogni valore atteso va poi
ricontrollato a mano** con la formula della specifica (Task 9) prima del commit:

| File | Contenuto |
|---|---|
| `01-cammino-regolare.json` | 11 punti, 10 m ogni 10 s, speed 4 |
| `02-sosta-distance-filter.json` | come il test «sosta» del Task 1 |
| `03-gps-disturbato-tenuto.json` | come il test «GPS buono» del Task 1 |
| `04-punto-scartato.json` | punti buoni con in mezzo un punto accuracy 3000 a 50 km (scartato) e uno con picco speed 890 |
| `05-due-punti-di-cui-uno-scartato.json` | 2 punti, uno con coordinate (0,0): `expected` = `null` |

`README.md`: formato, il fatto che sono la fonte comune per wm-package e wm-core (oc:8743), e
come rigenerarli (con la richiesta di ricontrollare i valori a mano).

- [ ] **Step 3: eseguire il test**

Run: `PEST tests/Unit/Services/UgcTrackStatsFixturesTest.php`
Expected: PASS (5 casi).

- [ ] **Step 4: commit (istruzione per il dev)**

```bash
git -C wm-package add tests/Fixtures/ugc-track-stats tests/Unit/Services/UgcTrackStatsFixturesTest.php
git -C wm-package commit -m "feat(oc:8742): casi di test condivisi con wm-core per i dati tecnici UGC"
```

---

### Task 3: observer — quando calcolare e quando conservare `stats`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-e-4-eseguiti-insieme)

**Files:**
- Modify: `src/Observers/UgcTrackGeometryCleanupObserver.php:19-30`
- Test: `tests/Feature/UgcTrackStatsObserverTest.php`

**Interfaces:**
- Consumes: `UgcTrackStatsService::localStats()`, `UgcTrackCleanupService::locationsOf()`.
- Produces: `properties.stats` sempre coerente al salvataggio; nel `saved()` accoda
  `UpdateUgcTrackDemStatsJob` (Task 4) con `(int $ugcTrackId, string $computedAt)`.

Regole:
1. La geometria si ricostruisce **come oggi** (riga 21: solo se nuova o con `geometry`/`properties`
   dirty): l'edit dell'app rimanda la geometria grezza con gli stessi punti (test esistente
   «ripulisce la geometria grezza rimandata dall'app con la route edit»).
2. `stats`:
   - nessuna `locations` utilizzabile (`locationsOf()` null o `localStats()` null) → si toglie `stats`;
   - traccia nuova, oppure `locations` diverse da quelle in `getOriginal('properties')` → nuovo
     `localStats()` (chiavi DEM a `null`);
   - altrimenti → `stats` = quello di `getOriginal('properties')` (o chiave tolta se non c'era):
     un `stats` del client non sopravvive mai.
3. `saved()`: se `stats` esiste, ha tutte le chiavi DEM a `null` e la traccia è nuova o
   `properties` è cambiata → `UpdateUgcTrackDemStatsJob::dispatch($id, $computedAt)->afterCommit()`.
   Una traccia il cui DEM era fallito riprova così al salvataggio successivo.

- [ ] **Step 1: scrivere i test che falliscono**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Wm\WmPackage\Jobs\UpdateUgcTrackDemStatsJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;

beforeEach(function () {
    config()->set('wm-package.ugc_track_max_accuracy_meters', 40.0);
    $this->withoutMiddleware('auth.jwt');
    $this->artisan('jwt:secret --always-no');
    $this->app_ = App::factory()->createQuietly();
    $this->user = User::factory()->create(['app_id' => $this->app_->id]);
    Bus::fake([UpdateUgcTrackDemStatsJob::class]);
});

/** @return list<array<string, mixed>> */
function statsWalk(int $points = 11): array
{
    return array_map(fn ($i) => [
        'time' => $i * 10_000, 'latitude' => 43.0 + $i * 0.00009, 'longitude' => 13.0,
        'accuracy' => 5.0, 'altitude' => 100.0, 'speed' => 4.0,
    ], range(0, $points - 1));
}

function statsFeature(array $locations, array $extra = []): array
{
    return [
        'type' => 'Feature',
        'geometry' => ['type' => 'LineString', 'coordinates' => array_map(fn ($l) => [$l['longitude'], $l['latitude'], $l['altitude']], $locations)],
        'properties' => array_merge(['name' => 'stats', 'uuid' => (string) Str::uuid(), 'locations' => $locations], $extra),
    ];
}

function storeStatsTrack($test, array $feature): UgcTrack
{
    $id = $test->actingAs($test->user, 'api')->postJson('/api/v2/ugc/track/store', $feature)->assertStatus(201)->json('id');

    return UgcTrack::find($id);
}

it('calcola stats alla creazione e accoda il DEM', function () {
    $track = storeStatsTrack($this, statsFeature(statsWalk(), ['app_id' => $this->app_->id]));

    expect($track->properties['stats']['distance'])->toBe(0.1);
    expect($track->properties['stats']['ascent'])->toBeNull();
    Bus::assertDispatched(UpdateUgcTrackDemStatsJob::class, fn ($job) => $job->ugcTrackId === $track->id);
});

it('non salva mai uno stats mandato dal client alla creazione', function () {
    $track = storeStatsTrack($this, statsFeature(statsWalk(), ['app_id' => $this->app_->id, 'stats' => ['ascent' => 9999, 'distance' => 999]]));

    expect($track->properties['stats']['distance'])->toBe(0.1);
    expect($track->properties['stats']['ascent'])->toBeNull();
});

it('conserva stats, DEM compreso, quando cambiano solo altre properties', function () {
    $track = storeStatsTrack($this, statsFeature(statsWalk(), ['app_id' => $this->app_->id]));
    DB::update("UPDATE ugc_tracks SET properties = jsonb_set(properties, '{stats,ascent}', '313') WHERE id = ?", [$track->id]);
    $track->refresh();
    Bus::fake([UpdateUgcTrackDemStatsJob::class]);

    $properties = $track->properties;
    $properties['layer_id'] = 7;
    $track->properties = $properties;
    $track->save();

    expect($track->fresh()->properties['stats']['ascent'])->toBe(313);
    Bus::assertNotDispatched(UpdateUgcTrackDemStatsJob::class);
});

it('nell\'edit dell\'app con gli stessi punti ripulisce la geometria e conserva stats', function () {
    $feature = statsFeature(statsWalk(), ['app_id' => $this->app_->id]);
    $track = storeStatsTrack($this, $feature);
    DB::update("UPDATE ugc_tracks SET properties = jsonb_set(properties, '{stats,ascent}', '313') WHERE id = ?", [$track->id]);

    $feature['properties']['id'] = $track->id;
    $feature['properties']['stats'] = ['ascent' => 1, 'distance' => 1];
    $this->actingAs($this->user, 'api')->postJson('/api/v2/ugc/track/edit', $feature)->assertStatus(200);

    expect($track->fresh()->properties['stats']['ascent'])->toBe(313);
    expect($track->fresh()->properties['stats']['distance'])->toBe(0.1);
});

it('ricalcola stats e azzera il DEM quando cambiano i punti', function () {
    $feature = statsFeature(statsWalk(), ['app_id' => $this->app_->id]);
    $track = storeStatsTrack($this, $feature);
    DB::update("UPDATE ugc_tracks SET properties = jsonb_set(properties, '{stats,ascent}', '313') WHERE id = ?", [$track->id]);
    Bus::fake([UpdateUgcTrackDemStatsJob::class]);

    $feature = statsFeature(statsWalk(21), ['app_id' => $this->app_->id, 'id' => $track->id, 'uuid' => $feature['properties']['uuid']]);
    $this->actingAs($this->user, 'api')->postJson('/api/v2/ugc/track/edit', $feature)->assertStatus(200);

    expect($track->fresh()->properties['stats']['distance'])->toBe(0.2);
    expect($track->fresh()->properties['stats']['ascent'])->toBeNull();
    Bus::assertDispatched(UpdateUgcTrackDemStatsJob::class);
});

it('non scrive stats per una traccia senza locations e toglie quello del client', function () {
    $feature = statsFeature(statsWalk(), ['app_id' => $this->app_->id, 'stats' => ['distance' => 5]]);
    unset($feature['properties']['locations']);

    $track = storeStatsTrack($this, $feature);

    expect($track->properties)->not->toHaveKey('stats');
    Bus::assertNotDispatched(UpdateUgcTrackDemStatsJob::class);
});
```

- [ ] **Step 2: eseguire i test e vederli fallire**

Run: `PEST tests/Feature/UgcTrackStatsObserverTest.php`
Expected: FAIL (classe `UpdateUgcTrackDemStatsJob` mancante → creare prima lo scheletro del
Task 4, Step 3, con `handle()` vuoto, e fare subito il Task 4 Step 0; poi i test falliscono su
`stats` assente).

- [ ] **Step 3: scrivere l'observer**

`src/Observers/UgcTrackGeometryCleanupObserver.php`:

```php
<?php

namespace Wm\WmPackage\Observers;

use Wm\WmPackage\Jobs\UpdateUgcTrackDemStatsJob;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Services\Models\UgcTrackCleanupService;
use Wm\WmPackage\Services\Models\UgcTrackStatsService;

/**
 * (docblock esistente invariato, con in fondo:)
 * oc:8742: tiene allineato properties.stats ai punti GPS. Si ricalcola solo se la traccia è nuova
 * o se cambiano i punti; negli altri casi resta lo stats del DB, così un cambio di layer non perde
 * il dislivello del DEM e uno stats mandato dal client non viene mai salvato.
 */
class UgcTrackGeometryCleanupObserver
{
    public function saving(UgcTrack $track): void
    {
        if ($track->exists && ! $track->isDirty('geometry') && ! $track->isDirty('properties')) {
            return;
        }

        $expression = UgcTrackCleanupService::make()->geometryExpressionFor($track);

        if ($expression !== null) {
            $track->geometry = $expression;
        }

        $this->syncStats($track);
    }

    public function saved(UgcTrack $track): void
    {
        $stats = $track->properties['stats'] ?? null;
        if (! is_array($stats) || ! ($track->wasRecentlyCreated || $track->wasChanged('properties'))) {
            return;
        }

        foreach (UgcTrackStatsService::DEM_KEYS as $key) {
            if (($stats[$key] ?? null) !== null) {
                return;
            }
        }

        UpdateUgcTrackDemStatsJob::dispatch($track->id, (string) $stats['computed_at'])->afterCommit();
    }

    private function syncStats(UgcTrack $track): void
    {
        $properties = is_array($track->properties) ? $track->properties : [];
        $original = $track->exists ? ($track->getOriginal('properties') ?? []) : [];
        $locations = UgcTrackCleanupService::make()->locationsOf($track);

        if ($locations === null) {
            unset($properties['stats']);
        } elseif (! $track->exists || ($original['locations'] ?? null) != $properties['locations']) {
            $stats = UgcTrackStatsService::make()->localStats($locations);
            if ($stats === null) {
                unset($properties['stats']);
            } else {
                $properties['stats'] = $stats;
            }
        } elseif (isset($original['stats'])) {
            $properties['stats'] = $original['stats'];
        } else {
            unset($properties['stats']);
        }

        $track->properties = $properties;
    }
}
```

Il confronto `!=` (non `!==`) fra array è voluto: lo stesso elenco decodificato due volte dal
JSON può differire solo nel tipo numerico (int/float) dei valori.

- [ ] **Step 4: eseguire i test nuovi e quelli di oc:8719**

Run: `PEST tests/Feature/UgcTrackStatsObserverTest.php tests/Feature/UgcTrackGeometryCleanupTest.php tests/Feature/UgcStoreUuidTest.php tests/Feature/UgcStoreRetryRealCaseTest.php`
Expected: PASS.

- [ ] **Step 5: commit (istruzione per il dev)**

```bash
git -C wm-package add src/Observers/UgcTrackGeometryCleanupObserver.php tests/Feature/UgcTrackStatsObserverTest.php
git -C wm-package commit -m "feat(oc:8742): stats delle UgcTrack ricalcolato solo quando cambiano i punti GPS"
```

---

### Task 4: job DEM delle UgcTrack

**Files:**
- Create: `src/Jobs/UpdateUgcTrackDemStatsJob.php`
- Test: `tests/Feature/UpdateUgcTrackDemStatsJobTest.php`

**Interfaces:**
- Consumes: `EcTrackService::fetchDemTechData(array $geojson): array` (risposta con
  `properties.ascent`, `descent`, `ele_min`, `ele_max`, `ele_from`, `ele_to`); il servizio vuole
  la geometria 2D.
- Produces: `UpdateUgcTrackDemStatsJob::dispatch(int $ugcTrackId, string $computedAt)`, coda
  `dem`, `$tries = 3`, `$backoff = [60, 300]`.

- [ ] **Step 0: impedire che la suite chiami il servizio DEM vero**

Nei test la coda è `sync`: dopo il Task 3 ogni UgcTrack creata con `locations` eseguirebbe il
job e chiamerebbe `dem.maphub.it`. In `tests/Pest.php`, accanto al blocco già presente per
`UpdateTrailApplicationDemJob` (oc:8571), aggiungere un `beforeEach` per tutta la suite:

```php
// oc:8742: ogni UgcTrack con locations accoda il calcolo DEM; nessun test deve uscire verso il
// servizio. Chi vuole il job vero lo esegue a mano con EcTrackService finto. Attenzione: un
// Bus::fake([...]) dentro un test sostituisce questo, e va ripetuto il job nella sua lista.
uses()->beforeEach(function () {
    Bus::fake([UpdateUgcTrackDemStatsJob::class]);
})->in(__DIR__);
```

Poi cercare i test che chiamano `Bus::fake(` e creano UgcTrack con `locations`
(`grep -rln "Bus::fake" tests | xargs grep -l "locations"`) e aggiungere
`UpdateUgcTrackDemStatsJob::class` alla loro lista. Annotare in `notes.md` i file toccati.

- [ ] **Step 1: scrivere i test**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Jobs\UpdateUgcTrackDemStatsJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Models\EcTrackService;

beforeEach(function () {
    config()->set('wm-package.ugc_track_max_accuracy_meters', 40.0);
    $this->app_ = App::factory()->createQuietly();
    $this->user = User::factory()->create(['app_id' => $this->app_->id]);
    Bus::fake([UpdateUgcTrackDemStatsJob::class]);
    $this->track = UgcTrack::factory()->create([
        'user_id' => $this->user->id, 'app_id' => $this->app_->id,
        'properties' => ['name' => 'dem', 'locations' => array_map(fn ($i) => [
            'time' => $i * 10_000, 'latitude' => 43.0 + $i * 0.00009, 'longitude' => 13.0, 'accuracy' => 5.0, 'altitude' => 100.0, 'speed' => 4.0,
        ], range(0, 10))],
    ]);
});

function fakeDem(?array $properties, bool $fails = false): void
{
    $mock = Mockery::mock(EcTrackService::class);
    $expectation = $mock->shouldReceive('fetchDemTechData')->once();
    $fails ? $expectation->andThrow(new Exception('DEM giù')) : $expectation->andReturn(['properties' => $properties]);
    app()->instance(EcTrackService::class, $mock);
}

it('scrive solo le chiavi DEM di stats', function () {
    $before = $this->track->fresh()->properties['stats'];
    fakeDem(['ascent' => 313, 'descent' => 610, 'ele_min' => 935, 'ele_max' => 1456, 'ele_from' => 1303, 'ele_to' => 994, 'distance' => 99]);

    (new UpdateUgcTrackDemStatsJob($this->track->id, $before['computed_at']))->handle(app(EcTrackService::class));

    $after = $this->track->fresh()->properties['stats'];
    expect($after['ascent'])->toBe(313);
    expect($after['ele_to'])->toBe(994);
    expect($after['distance'])->toBe($before['distance']);
});

it('non scrive se nel frattempo i punti sono cambiati (computed_at diverso)', function () {
    fakeDem(['ascent' => 313, 'descent' => 610, 'ele_min' => 1, 'ele_max' => 2, 'ele_from' => 1, 'ele_to' => 2]);

    (new UpdateUgcTrackDemStatsJob($this->track->id, '2000-01-01T00:00:00Z'))->handle(app(EcTrackService::class));

    expect($this->track->fresh()->properties['stats']['ascent'])->toBeNull();
});

it('lascia le chiavi DEM a null e rilancia l\'eccezione se il servizio fallisce', function () {
    fakeDem(null, fails: true);
    $computedAt = $this->track->fresh()->properties['stats']['computed_at'];

    expect(fn () => (new UpdateUgcTrackDemStatsJob($this->track->id, $computedAt))->handle(app(EcTrackService::class)))
        ->toThrow(Exception::class);
    expect($this->track->fresh()->properties['stats']['ascent'])->toBeNull();
});
```

- [ ] **Step 2: eseguirli e vederli fallire**

Run: `PEST tests/Feature/UpdateUgcTrackDemStatsJobTest.php`
Expected: FAIL.

- [ ] **Step 3: scrivere il job**

```php
<?php

namespace Wm\WmPackage\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Services\Models\EcTrackService;
use Wm\WmPackage\Services\Models\UgcTrackStatsService;

/**
 * Dislivelli e quote di una UgcTrack dal servizio DEM (oc:8742), sulla geometria pulita.
 * Scrive in SQL solo le chiavi DEM di properties.stats: non riattiva gli observer e non tocca
 * i valori locali. Scrive solo se stats.computed_at è ancora quello del momento in cui è stato
 * accodato: se nel frattempo i punti sono cambiati, il dislivello di questa geometria è vecchio
 * e un altro job è già in coda.
 */
class UpdateUgcTrackDemStatsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $queue = 'dem';

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public int $ugcTrackId, public string $computedAt) {}

    public function handle(EcTrackService $ecTrackService): void
    {
        $track = UgcTrack::find($this->ugcTrackId);
        if (! $track || ($track->properties['stats']['computed_at'] ?? null) !== $this->computedAt) {
            return;
        }

        $row = DB::selectOne(
            "SELECT ST_AsGeoJSON(ST_Force2D(geometry::geometry)) AS g FROM {$track->getTable()} WHERE id = ?",
            [$track->id]
        );
        if ($row?->g === null) {
            return;
        }

        $response = $ecTrackService->fetchDemTechData([
            'type' => 'Feature',
            'properties' => ['id' => $track->id],
            'geometry' => json_decode($row->g, true),
        ]);

        $dem = [];
        foreach (UgcTrackStatsService::DEM_KEYS as $key) {
            $value = $response['properties'][$key] ?? null;
            $dem[$key] = is_numeric($value) ? $value + 0 : null;
        }

        DB::update(
            "UPDATE {$track->getTable()}
             SET properties = jsonb_set(properties, '{stats}', (properties->'stats') || ?::jsonb)
             WHERE id = ? AND properties->'stats'->>'computed_at' = ?",
            [json_encode($dem), $track->id, $this->computedAt]
        );
    }
}
```

- [ ] **Step 4: eseguire i test**

Run: `PEST tests/Feature/UpdateUgcTrackDemStatsJobTest.php tests/Feature/UgcTrackStatsObserverTest.php`
Expected: PASS.

- [ ] **Step 5: commit (istruzione per il dev)**

```bash
git -C wm-package add src/Jobs/UpdateUgcTrackDemStatsJob.php tests/Feature/UpdateUgcTrackDemStatsJobTest.php
git -C wm-package commit -m "feat(oc:8742): dislivello e quote delle UgcTrack dal servizio DEM"
```

---

### Task 5: command e job per le tracce esistenti

**Files:**
- Modify: `src/Jobs/CleanUgcTrackGeometryJob.php:28-49`
- Modify: `src/Commands/WmCleanUgcTrackGeometryCommand.php:20-58`
- Test: `tests/Feature/WmCleanUgcTrackGeometryCommandTest.php` (aggiunta di casi)

**Interfaces:**
- Consumes: `UgcTrackStatsService::localStats()`, `UpdateUgcTrackDemStatsJob`.
- Produces: il job calcola `stats` per ogni traccia con punti; il command accoda il job per ogni
  traccia con `locations`, non solo per quelle la cui geometria cambia.

Regole del job, dopo l'aggiornamento della geometria (che resta com'è):
- `localStats()` null → `UPDATE … SET properties = properties - 'stats'`;
- altrimenti nuovo `stats` locale; le chiavi DEM si copiano da quello salvato se tutte presenti e
  non `null` **e** la geometria non è cambiata in questo job (`$updated === 0`); altrimenti `null`;
- scrittura con `jsonb_set(properties, '{stats}', ?::jsonb)` in SQL;
- se le chiavi DEM sono `null` → `UpdateUgcTrackDemStatsJob::dispatch($id, $computedAt)`.

- [ ] **Step 1: aggiungere i test al file esistente**

```php
it('calcola stats anche per una traccia la cui geometria non cambia', function () {
    Bus::fake([UpdateUgcTrackDemStatsJob::class, UpdateModelWithGeometryTaxonomyWhere::class]);
    $locations = array_map(fn ($i) => ['time' => $i * 10_000, 'latitude' => 43.0 + $i * 0.00009, 'longitude' => 13.0, 'accuracy' => 5.0, 'altitude' => 100.0, 'speed' => 4.0], range(0, 10));
    $track = UgcTrack::factory()->create(['user_id' => $this->user->id, 'app_id' => $this->app_->id, 'properties' => ['name' => 'pulita', 'locations' => $locations]]);
    DB::update("UPDATE ugc_tracks SET properties = properties - 'stats' WHERE id = ?", [$track->id]);

    (new CleanUgcTrackGeometryJob($track->id))->handle(UgcTrackCleanupService::make());

    expect($track->fresh()->properties['stats']['distance'])->toBe(0.1);
    Bus::assertDispatched(UpdateUgcTrackDemStatsJob::class);
});

it('conserva i valori DEM se la geometria non cambia', function () {
    Bus::fake([UpdateUgcTrackDemStatsJob::class, UpdateModelWithGeometryTaxonomyWhere::class]);
    $locations = array_map(fn ($i) => ['time' => $i * 10_000, 'latitude' => 43.0 + $i * 0.00009, 'longitude' => 13.0, 'accuracy' => 5.0, 'altitude' => 100.0, 'speed' => 4.0], range(0, 10));
    $track = UgcTrack::factory()->create(['user_id' => $this->user->id, 'app_id' => $this->app_->id, 'properties' => ['name' => 'con dem', 'locations' => $locations]]);
    $dem = json_encode(['ascent' => 313, 'descent' => 610, 'ele_min' => 1, 'ele_max' => 2, 'ele_from' => 1, 'ele_to' => 2]);
    DB::update("UPDATE ugc_tracks SET properties = jsonb_set(properties, '{stats}', (properties->'stats') || ?::jsonb) WHERE id = ?", [$dem, $track->id]);

    (new CleanUgcTrackGeometryJob($track->id))->handle(UgcTrackCleanupService::make());

    expect($track->fresh()->properties['stats']['ascent'])->toBe(313);
    Bus::assertNotDispatched(UpdateUgcTrackDemStatsJob::class);
});

it('il command accoda il job per ogni traccia con locations, anche senza cambi di geometria', function () {
    Bus::fake([CleanUgcTrackGeometryJob::class, UpdateUgcTrackDemStatsJob::class]);
    $locations = array_map(fn ($i) => ['time' => $i * 10_000, 'latitude' => 43.0 + $i * 0.00009, 'longitude' => 13.0, 'accuracy' => 5.0, 'altitude' => 100.0], range(0, 10));
    UgcTrack::factory()->create(['user_id' => $this->user->id, 'app_id' => $this->app_->id, 'properties' => ['name' => 'a', 'locations' => $locations]]);

    $this->artisan('wm:clean-ugc-track-geometry')->assertSuccessful();

    Bus::assertDispatchedTimes(CleanUgcTrackGeometryJob::class, 1);
});
```

(aggiungere in testa `use Wm\WmPackage\Jobs\UpdateUgcTrackDemStatsJob;`). Controllare i test
esistenti del file che contano i job accodati: con il nuovo comportamento accodano un job per ogni
traccia con `locations`; aggiornare le attese di conteggio e annotarlo in `notes.md`.

- [ ] **Step 2: eseguirli e vederli fallire**

Run: `PEST tests/Feature/WmCleanUgcTrackGeometryCommandTest.php`
Expected: FAIL sui tre nuovi.

- [ ] **Step 3: modificare il job**

In `CleanUgcTrackGeometryJob::handle()`, dopo il blocco `if ($updated > 0 …) { … }`:

```php
        $this->syncStats($track->fresh() ?? $track, $updated > 0);
```

e i metodi (con `use Wm\WmPackage\Services\Models\UgcTrackStatsService;` in testa):

```php
    /**
     * oc:8742: stats per ogni traccia con punti, anche se la geometria non è cambiata (le tracce
     * senza punti scartati sono la maggioranza). I valori DEM si conservano solo se la geometria
     * è la stessa; altrimenti si richiedono al servizio.
     */
    private function syncStats(UgcTrack $track, bool $geometryChanged): void
    {
        $locations = UgcTrackCleanupService::make()->locationsOf($track);
        $stats = $locations === null ? null : UgcTrackStatsService::make()->localStats($locations);

        if ($stats === null) {
            DB::update("UPDATE {$track->getTable()} SET properties = properties - 'stats' WHERE id = ?", [$track->id]);

            return;
        }

        $saved = $track->properties['stats'] ?? [];
        $demComplete = true;
        foreach (UgcTrackStatsService::DEM_KEYS as $key) {
            if (($saved[$key] ?? null) === null) {
                $demComplete = false;
            }
        }
        if ($demComplete && ! $geometryChanged) {
            foreach (UgcTrackStatsService::DEM_KEYS as $key) {
                $stats[$key] = $saved[$key];
            }
        }

        DB::update(
            "UPDATE {$track->getTable()} SET properties = jsonb_set(properties, '{stats}', ?::jsonb) WHERE id = ?",
            [json_encode($stats), $track->id]
        );

        if (! $demComplete || $geometryChanged) {
            UpdateUgcTrackDemStatsJob::dispatch($track->id, $stats['computed_at']);
        }
    }
```

Aggiornare il docblock della classe: «Rilanciarlo non cambia la geometria; riscrive stats
(computed_at nuovo) e richiede il DEM solo dove manca».

- [ ] **Step 4: modificare il command**

Nel `chunkById`, sostituire il corpo del `foreach` con: accodamento del job per **ogni** traccia
(senza `continue` su `wouldChange`), e la riga della tabella solo per quelle che cambiano:

```php
            foreach ($tracks as $track) {
                if ($cleanup->wouldChange($track)) {
                    $summary = $cleanup->summary($cleanup->locationsOf($track) ?? []);
                    $rows[] = [
                        $track->id,
                        $summary['discarded'].'/'.$summary['total'],
                        sprintf('%.1f', $this->storedLengthKm($track)),
                        sprintf('%.1f', $summary['length_after_km']),
                    ];
                }
                $total++;

                if (! $dryRun) {
                    CleanUgcTrackGeometryJob::dispatch($track->id)->onQueue($queue);
                }
            }
```

con `$total = 0;` prima del chunk e `use (…, &$total)`; messaggi finali:

```php
        $this->info($dryRun
            ? count($rows).' tracce cambierebbero geometria; '.$total.' riceverebbero stats (dry-run, nessuna scrittura).'
            : $total.' job accodati sulla coda '.$queue.' ('.count($rows).' con geometria da ripulire).');
```

Aggiornare `$description`: «Ricostruisce la geometria delle UgcTrack da properties.locations e ne
calcola properties.stats (oc:8719, oc:8742).»

- [ ] **Step 5: eseguire i test**

Run: `PEST tests/Feature/WmCleanUgcTrackGeometryCommandTest.php tests/Feature/UpdateUgcTrackDemStatsJobTest.php`
Expected: PASS.

- [ ] **Step 6: commit (istruzione per il dev)**

```bash
git -C wm-package add src/Jobs/CleanUgcTrackGeometryJob.php src/Commands/WmCleanUgcTrackGeometryCommand.php tests/Feature/WmCleanUgcTrackGeometryCommandTest.php
git -C wm-package commit -m "feat(oc:8742): il command di pulizia GPS popola stats sulle tracce esistenti"
```

---

### Task 6: parametri nel `config.json` dell'app

**Files:**
- Modify: `src/Services/Models/App/AppConfigService.php:923-941`
- Test: `tests/Feature/AppConfigServiceGeolocationStatsTest.php`

**Interfaces:**
- Produces: `GEOLOCATION.record.stats = {max_accuracy, max_deviation, max_speed_percentile, moving_min_speed}`
  solo se `GEOLOCATION.record` esiste.

- [ ] **Step 1: test**

```php
<?php

declare(strict_types=1);

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\Models\App\AppConfigService;

it('espone i parametri dei dati tecnici quando la registrazione è attiva', function () {
    config()->set('wm-package.ugc_track_max_accuracy_meters', 40.0);
    config()->set('wm-package.ugc_track_max_deviation_meters', 50.0);
    config()->set('wm-package.ugc_track_max_speed_percentile', 95.0);
    config()->set('wm-package.ugc_track_moving_min_speed_kmh', 1.0);
    $app = App::factory()->createQuietly(['geolocation_record_enable' => true]);

    $config = (new AppConfigService($app))->config();

    expect($config['GEOLOCATION']['record']['stats'])->toBe([
        'max_accuracy' => 40.0, 'max_deviation' => 50.0, 'max_speed_percentile' => 95.0, 'moving_min_speed' => 1.0,
    ]);
});

it('non espone i parametri se la registrazione non è attiva', function () {
    $app = App::factory()->createQuietly(['geolocation_record_enable' => false]);

    $config = (new AppConfigService($app))->config();

    expect($config['GEOLOCATION']['record'] ?? null)->toBeNull();
});
```

Se `App::factory()` usa `api = 'elbrus'` di default, il ramo `elbrus` crea sempre `record`:
in quel caso il secondo test va scritto con un `api` diverso, verificando il default della factory
prima di eseguirlo.

- [ ] **Step 2: eseguirlo e vederlo fallire** — Run: `PEST tests/Feature/AppConfigServiceGeolocationStatsTest.php` → FAIL.

- [ ] **Step 3: implementare**

In `config_section_geolocation()`, prima di `if ($this->app->gps_accuracy_default)`:

```php
        // oc:8742: parametri del calcolo dei dati tecnici, perché l'app calcoli le tracce non
        // ancora sincronizzate con le stesse regole del server.
        if (isset($data['GEOLOCATION']['record'])) {
            $stats = UgcTrackStatsService::make();
            $cleanup = UgcTrackCleanupService::make();
            $data['GEOLOCATION']['record']['stats'] = [
                'max_accuracy' => $cleanup->maxAccuracyMeters(),
                'max_deviation' => $cleanup->maxDeviationMeters(),
                'max_speed_percentile' => $stats->maxSpeedPercentile(),
                'moving_min_speed' => $stats->movingMinSpeedKmh(),
            ];
        }
```

con gli `use` di `UgcTrackCleanupService` e `UgcTrackStatsService`.

- [ ] **Step 4: test** — Run: `PEST tests/Feature/AppConfigServiceGeolocationStatsTest.php tests/Feature/AppConfigServiceMinAppVersionTest.php` → PASS.

- [ ] **Step 5: commit (istruzione per il dev)**

```bash
git -C wm-package add src/Services/Models/App/AppConfigService.php tests/Feature/AppConfigServiceGeolocationStatsTest.php
git -C wm-package commit -m "feat(oc:8742): parametri dei dati tecnici UGC nel config.json"
```

---

### Task 7: dettaglio Nova

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-7-dati-tecnici-in-un-blocco-sotto-la-mappa)

**Files:**
- Modify: `src/Nova/UgcTrack.php` (dopo il campo «GPS cleanup»)
- Modify: `resources/lang/{en,it,de,es,fr}.json`
- Test: `tests/Feature/Nova/UgcTrackCleanupNovaTest.php` (aggiunta)

- [ ] **Step 1: test** (nel file esistente):

```php
it('mostra i dati tecnici di stats nel dettaglio', function () {
    $this->actingAs($this->user);
    $track = trackWithLocations($this->user->id, $this->app_->id);
    app()->setLocale('it');

    $request = NovaRequest::create('/nova-api/ugc-tracks/'.$track->id, 'GET');
    $field = (new UgcTrackResource($track->fresh()))->detailFields($request)
        ->first(fn ($field) => $field->name === __('Technical data'));
    $field->resolveForDisplay($track->fresh());

    expect($field->value)->toContain('km');
    expect($field->value)->toContain('km/h');
});
```

- [ ] **Step 2: eseguirlo e vederlo fallire.**

- [ ] **Step 3: campo** (dopo `Text::make(__('GPS cleanup'), …)->onlyOnDetail(),`):

```php
            Text::make(__('Technical data'), function () {
                $stats = $this->resource instanceof UgcTrackModel ? ($this->resource->properties['stats'] ?? null) : null;
                if (! is_array($stats)) {
                    return null;
                }
                $decimal = str_starts_with(app()->getLocale(), 'en') ? '.' : ',';
                $n = fn ($v, int $d) => $v === null ? '—' : number_format((float) $v, $d, $decimal, '');

                return __(':distance km · ascent :ascent m · descent :descent m · time :duration min (moving :moving min) · avg :avg km/h · max :max km/h', [
                    'distance' => $n($stats['distance'] ?? null, 2),
                    'ascent' => $n($stats['ascent'] ?? null, 0),
                    'descent' => $n($stats['descent'] ?? null, 0),
                    'duration' => $n($stats['duration'] ?? null, 0),
                    'moving' => $n($stats['duration_moving'] ?? null, 0),
                    'avg' => $n($stats['avg_speed'] ?? null, 1),
                    'max' => $n($stats['max_speed'] ?? null, 1),
                ]);
            })->onlyOnDetail(),
```

Traduzioni (stessa chiave in tutti i file, valore nella lingua):

| Chiave | it | de | es | fr |
|---|---|---|---|---|
| `Technical data` | Dati tecnici | Technische Daten | Datos técnicos | Données techniques |
| `:distance km · ascent :ascent m · descent :descent m · time :duration min (moving :moving min) · avg :avg km/h · max :max km/h` | `:distance km · salita :ascent m · discesa :descent m · tempo :duration min (in movimento :moving min) · media :avg km/h · max :max km/h` | `:distance km · Aufstieg :ascent m · Abstieg :descent m · Zeit :duration min (in Bewegung :moving min) · Ø :avg km/h · max :max km/h` | `:distance km · subida :ascent m · bajada :descent m · tiempo :duration min (en movimiento :moving min) · media :avg km/h · máx :max km/h` | `:distance km · montée :ascent m · descente :descent m · temps :duration min (en mouvement :moving min) · moy. :avg km/h · max :max km/h` |

`en.json`: valore uguale alla chiave.

- [ ] **Step 4: test** — Run: `PEST tests/Feature/Nova/UgcTrackCleanupNovaTest.php` → PASS.

- [ ] **Step 5: commit (istruzione per il dev)**

```bash
git -C wm-package add src/Nova/UgcTrack.php resources/lang tests/Feature/Nova/UgcTrackCleanupNovaTest.php
git -C wm-package commit -m "feat(oc:8742): dati tecnici della traccia UGC nel dettaglio Nova"
```

---

### Task 8: immagine di condivisione da `stats`

**Files:**
- Modify: `src/Services/Models/UgcTrackStatsService.php` (nuovo metodo)
- Modify: `src/Http/Controllers/Api/ShareStoryImageController.php:99`
- Test: `tests/Unit/Services/UgcTrackStatsServiceTest.php` (aggiunta)

**Interfaces:**
- Produces: `UgcTrackStatsService::forShareImage(array $stats): array{duration_seconds: int|null, distance_km: float|null, ascent_meters: float|null}`
  — il formato che `StoryShareImageService::compose()` legge già.

- [ ] **Step 1: test**

```php
it('converte stats nel formato dell\'immagine di condivisione', function () {
    expect($this->stats->forShareImage(['duration' => 165, 'distance' => 9.37, 'ascent' => 313]))
        ->toBe(['duration_seconds' => 9900, 'distance_km' => 9.37, 'ascent_meters' => 313.0]);
    expect($this->stats->forShareImage(['duration' => null, 'distance' => 1.0, 'ascent' => null]))
        ->toBe(['duration_seconds' => null, 'distance_km' => 1.0, 'ascent_meters' => null]);
});
```

- [ ] **Step 2: vederlo fallire, poi implementare**

```php
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
```

In `ShareStoryImageController::store()`, riga 99:

```php
            // oc:8742: stessi numeri di app e Nova; TrackStatsService solo per le tracce che non
            // hanno ancora stats (prima del command sulle tracce esistenti).
            $stats = is_array($ugcTrack->properties['stats'] ?? null)
                ? UgcTrackStatsService::make()->forShareImage($ugcTrack->properties['stats'])
                : $statsService->compute($ugcTrack->properties['locations'] ?? []);
```

- [ ] **Step 3: test** — Run: `PEST tests/Unit/Services/UgcTrackStatsServiceTest.php tests/Feature/ShareStoryImageControllerTest.php` → PASS.

- [ ] **Step 4: commit (istruzione per il dev)**

```bash
git -C wm-package add src/Services/Models/UgcTrackStatsService.php src/Http/Controllers/Api/ShareStoryImageController.php tests/Unit/Services/UgcTrackStatsServiceTest.php
git -C wm-package commit -m "feat(oc:8742): l'immagine di condivisione usa stats"
```

---

### Task 9: specifica del calcolo, verifica sui dati reali, chiusura

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-9-verifica-sulla-traccia-174-senza-test-automatico)

**Files:**
- Create: `docs/knowledge/dati-tecnici-delle-tracce-ugc.md` (wm-package)
- Modify: `CLAUDE.md` (wm-package, tabella «Conoscenza»)
- Modify: `.env-example` (camminiditalia) — righe commentate dei due parametri nuovi
- Modify: `CLAUDE.md` (camminiditalia, «Feature disponibili»), gitlink `wm-package`

- [ ] **Step 1: pagina di conoscenza — è il deliverable che il dev ha chiesto di curare di più.**
  Deve bastare a chi implementa il calcolo nell'app (oc:8743) **senza leggere il codice PHP**.
  Struttura obbligatoria:

  1. **Formato di `stats`**: l'esempio JSON della traccia 174 e la tabella chiave → unità → tipo
     (`int`/`float`/`null`) → decimali.
  2. **Passi comuni**, nell'ordine in cui si eseguono: (a) punti tenuti con la regola di oc:8719
     (rimando alla sua pagina, con i due parametri); (b) definizione di tratto fra due punti tenuti
     consecutivi; (c) distanza haversine, formula scritta per intero, R = 6.371.000 m; (d) Δt in
     secondi e quando un tratto ha un Δt valido; (e) tratto in movimento (≥ `moving_min_speed`);
     (f) tratto con GPS buono (accuracy di entrambi i punti ≤ `max_accuracy`).
  3. **Una sezione per ogni chiave** (`distance`, `duration`, `duration_moving`, `avg_speed`,
     `max_speed`, `points_total`, `points_discarded`, `computed_at`), ciascuna con: dati di
     partenza, formula, pseudocodice di 5–10 righe, arrotondamento (funzione e decimali), quando
     vale `null`, e il **valore sulla traccia 174** come esempio svolto.
  4. **Percentile nearest-rank** con un esempio numerico (10 valori, p = 95 → rango 10).
  5. **Chiavi DEM**: cosa le calcola (servizio, coda, quando si richiedono), che l'app **non** le
     replica e cosa mostra prima che arrivino.
  6. **Casi limite**, uno per riga con il risultato atteso: meno di 2 punti tenuti, `time` assente,
     `time` non crescente, `speed` assente su tutti i punti, `speed` negativo, `accuracy` assente,
     traccia senza `locations`.
  7. **Parametri**: tabella con chiave di `config/wm-package.php`, variabile d'ambiente, chiave in
     `GEOLOCATION.record.stats`, default e perché quel default (dati dell'overview).
  8. **Casi di test condivisi**: dove sono, formato, come li usa wm-core.
  9. **Quando si ricalcola**: observer (punti cambiati / non cambiati), command, job DEM con la
     guardia su `computed_at`.
  10. «Perché così» e «Come ci siamo arrivati» con le alternative scartate (massimo semplice,
      finestra mobile, pausa oltre 120 s, quota GPS, `duration_forward`, correzione max ≥ media).

- [ ] **Step 1b: verifica che la pagina basti da sola.** Lanciare un subagente che riceve **solo**
  il percorso della pagina e di una fixture (non il codice), e chiedergli di calcolare a mano i
  valori di `expected` di `01-cammino-regolare.json` e `03-gps-disturbato-tenuto.json`. Se un
  valore non coincide, o il subagente segnala un passo ambiguo, correggere la pagina e ripetere.
  Annotare l'esito in `notes.md`.

- [ ] **Step 2: verifica sui dati reali (DB di sviluppo, sola lettura)**

```bash
docker exec laravel-camminiditalia php artisan tinker --execute='
$t=\Wm\WmPackage\Models\UgcTrack::find(174);
print_r(\Wm\WmPackage\Services\Models\UgcTrackStatsService::make()->localStats($t->properties["locations"]));'
```

Expected (dati dell'overview): `distance` ≈ 9,37, `duration` 165, `duration_moving` ≈ 144,
`avg_speed` ≈ 3,4, `max_speed` 6,5, `points_total` 418, `points_discarded` 37.

- [ ] **Step 3: suite completa del package e PHPStan**

Run: `PEST` (tutta la suite) e `docker run … wm-phpfpm:8.4 composer analyse`.
Expected: nessun test nuovo fallito rispetto a `develop`.

- [ ] **Step 4: Pint** — `composer format` nel package, poi `git -C wm-package status` e
  `git -C wm-package checkout -- <file estranei>`.

- [ ] **Step 5: documentazione camminiditalia** — riga in «Feature disponibili» del `CLAUDE.md`
  con rimando alla pagina di wm-package e la procedura dopo il deploy:
  `php artisan wm:clean-ugc-track-geometry --dry-run`, poi senza `--dry-run`.

- [ ] **Step 6: commit (istruzioni per il dev)**

```bash
git -C wm-package add docs/knowledge/dati-tecnici-delle-tracce-ugc.md CLAUDE.md docs/features/8742-ugc-velocita-tempo-e-dislivello-calcolati-dal-server-sui-punti-gps-puliti
git -C wm-package commit -m "docs(oc:8742): specifica del calcolo dei dati tecnici UGC"
git -C /Users/bongiu/Documents/camminiditalia add wm-package CLAUDE.md .env-example
git -C /Users/bongiu/Documents/camminiditalia commit -m "feat(oc:8742): bump wm-package, dati tecnici delle tracce UGC"
```

PR: prima wm-package verso `develop`, poi camminiditalia verso `develop` con il gitlink del merge.
