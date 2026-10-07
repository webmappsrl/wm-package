> Ticket: oc:8719

# Pulizia automatica dei punti GPS errati nelle tracce UGC — piano di implementazione

> **Per chi esegue:** sotto-skill richiesta: `superpowers:subagent-driven-development` (consigliata)
> o `superpowers:executing-plans`. I passi usano le checkbox (`- [ ]`).
>
> **Nessun commit e nessun branch automatico.** I passi «Commit» sono istruzioni per il dev, da
> eseguire solo dopo la sua approvazione esplicita (review-gate di `wm-plan`).

**Obiettivo:** ricostruire la `geometry` delle UgcTrack da `properties.locations` scartando i punti
GPS inutilizzabili, al salvataggio e con un command per le tracce esistenti, e mostrare in Nova cosa
è stato scartato.

**Architettura:** un servizio unico (`UgcTrackCleanupService`) contiene le regole: lo usano un
observer dedicato sull'evento `saving` della UgcTrack (prima di `creating`/`updating`, quindi prima
del calcolo del cammino in `UgcObserver::created()`), un job + command per le tracce esistenti,
`TrackStatsService` per l'immagine di condivisione, e la risorsa Nova. La geometria pulita è
un'espressione PostGIS (`ST_GeomFromEWKT`) costruita solo da numeri.

**Tech stack:** Laravel, Nova 5, PostgreSQL/PostGIS, Pest, Vue 3 + OpenLayers (campo
`FeatureCollectionMap`, build con Laravel Mix).

**Spec:** [overview.md](overview.md)

## Vincoli globali

- Tutto il codice è in **wm-package**; nel repo camminiditalia cambia solo il puntatore al submodule.
- Soglia: `'ugc_track_max_accuracy_meters' => env('UGC_TRACK_MAX_ACCURACY_METERS', 40)` in
  `config/wm-package.php`. Nessun interruttore on/off.
- Regole di scarto: punto con latitudine **e** longitudine a 0; punto con accuracy numerica e
  > soglia; punto senza latitudine/longitudine numeriche. Nessuna regola su tempo o distanza.
- `properties.locations` non si modifica mai.
- Formato della geometria scritta: MultiLineString Z, SRID 4326, quota = `altitude` (0 se assente).
- Geometrie solo via SQL/espressioni PostGIS, mai oggetti geometrici costruiti in PHP con l'ORM.
- In un modello o servizio: `config()`, mai `env()`; il valore di `env()` va castato (`(float)`).
- Testi Nova: chiave inglese in `resources/lang/{it,en,de,es,fr}.json`.
- Documentazione e commenti in italiano.

## Come si lanciano i test

Dalla root del repo camminiditalia (i test del package usano il DB `wm_package` già presente nel
Postgres del progetto):

```bash
docker run --rm --network camminiditalia_default -e DB_HOST=postgres-camminiditalia \
  -v "$PWD/wm-package:/app" -w /app wm-phpfpm:8.4 vendor/bin/pest <percorso-o---filter>
```

## Review Focus

1. **Punto senza `accuracy`** (app o plugin GPS diversi): va tenuto, non c'è modo di giudicarlo.
   Test in Task 1.
2. **Traccia in cui restano meno di 2 punti dopo il filtro**: la geometria ricevuta resta com'è.
   Test in Task 1 (servizio) e Task 2 (salvataggio).
3. **Traccia senza `locations`** (app vecchia, import, creata da Nova): geometria intatta al
   salvataggio e campo mappa ancora modificabile in Nova. Test in Task 2 e Task 5.
4. **Modifica dall'app con la route `edit`**, che rimanda la geometria grezza: la traccia torna
   pulita. Test in Task 2.
5. **Punto (0,0) in testa alla geometria ma assente da `locations`** (tracce 217, 238): sparisce.
   Test in Task 2.

---

### Task 1: servizio con le regole di pulizia e soglia in config

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1-regola-di-scarto-con-distanza-dal-tratto)

**File:**
- Modifica: `config/wm-package.php` (vicino a `layer_user_presence_distance_meters`, riga 15)
- Crea: `src/Services/Models/UgcTrackCleanupService.php`
- Test: `tests/Unit/Services/UgcTrackCleanupServiceTest.php`

**Interfacce prodotte** (usate dai task successivi):
- `UgcTrackCleanupService::make(): static`
- `maxAccuracyMeters(): float`
- `locationsOf(UgcTrack $track): ?array` — `properties.locations` se è un array non vuoto, altrimenti `null`
- `isUsable(mixed $location): bool`
- `keptLocations(array $locations): array` — lista dei punti tenuti, nell'ordine originale
- `gaps(array $locations): array` — lista di `['from' => array, 'to' => array, 'discarded' => int, 'seconds' => int, 'max_accuracy' => float]`
- `summary(array $locations): array` — `['total' => int, 'discarded' => int, 'max_discarded_accuracy' => float, 'length_before_km' => float, 'length_after_km' => float]`
- `ewkt(array $kept): ?string` — `null` se meno di 2 punti
- `ewktFor(UgcTrack $track): ?string`
- `geometryExpressionFor(UgcTrack $track): ?Expression`
- `wouldChange(UgcTrack $track): bool`

- [ ] **Passo 1: config**

In `config/wm-package.php`, subito dopo la riga 15:

```php
    // oc:8719: i punti GPS di una traccia UGC con accuracy oltre questa soglia (metri) vengono
    // scartati quando la geometria viene ricostruita da properties.locations.
    'ugc_track_max_accuracy_meters' => (float) env('UGC_TRACK_MAX_ACCURACY_METERS', 40),
```

- [ ] **Passo 2: test che fallisce**

`tests/Unit/Services/UgcTrackCleanupServiceTest.php`:

```php
<?php

declare(strict_types=1);

use Wm\WmPackage\Services\Models\UgcTrackCleanupService;

// Base TestCase (Wm\WmPackage\Tests\TestCase) applicato globalmente da tests/Pest.php.

function cleanupPoint(float $lat, float $lon, ?float $accuracy, int $time, ?float $altitude = 100.0): array
{
    $point = ['latitude' => $lat, 'longitude' => $lon, 'time' => $time];
    if ($accuracy !== null) {
        $point['accuracy'] = $accuracy;
    }
    if ($altitude !== null) {
        $point['altitude'] = $altitude;
    }

    return $point;
}

beforeEach(function () {
    config()->set('wm-package.ugc_track_max_accuracy_meters', 40.0);
    $this->service = UgcTrackCleanupService::make();
});

it('tiene i punti con accuracy uguale o sotto la soglia e scarta quelli sopra', function () {
    expect($this->service->isUsable(cleanupPoint(43.0, 13.0, 40.0, 0)))->toBeTrue();
    expect($this->service->isUsable(cleanupPoint(43.0, 13.0, 40.01, 0)))->toBeFalse();
    expect($this->service->isUsable(cleanupPoint(43.0, 13.0, 2000.0, 0)))->toBeFalse();
});

it('scarta il punto (0,0) anche con accuracy buona', function () {
    expect($this->service->isUsable(cleanupPoint(0.0, 0.0, 5.0, 0)))->toBeFalse();
    expect($this->service->isUsable(cleanupPoint(0.0, 13.0, 5.0, 0)))->toBeTrue();
});

it('tiene un punto senza accuracy o con accuracy negativa', function () {
    expect($this->service->isUsable(cleanupPoint(43.0, 13.0, null, 0)))->toBeTrue();
    expect($this->service->isUsable(cleanupPoint(43.0, 13.0, -1.0, 0)))->toBeTrue();
});

it('scarta un punto senza coordinate numeriche', function () {
    expect($this->service->isUsable(['latitude' => 'x', 'longitude' => 13.0, 'accuracy' => 5]))->toBeFalse();
    expect($this->service->isUsable(['longitude' => 13.0, 'accuracy' => 5]))->toBeFalse();
    expect($this->service->isUsable('non un punto'))->toBeFalse();
});

it('legge la soglia dalla config', function () {
    config()->set('wm-package.ugc_track_max_accuracy_meters', 100.0);

    expect(UgcTrackCleanupService::make()->isUsable(cleanupPoint(43.0, 13.0, 90.0, 0)))->toBeTrue();
});

it('restituisce un tratto ricostruito per ogni sequenza di punti scartati fra due punti tenuti', function () {
    $locations = [
        cleanupPoint(43.000, 13.000, 5, 0),
        cleanupPoint(43.001, 13.000, 5, 10_000),
        cleanupPoint(43.300, 13.300, 2000, 20_000),
        cleanupPoint(43.400, 13.400, 7857, 30_000),
        cleanupPoint(43.002, 13.000, 6, 70_000),
        cleanupPoint(43.003, 13.000, 5, 80_000),
    ];

    $gaps = $this->service->gaps($locations);

    expect($gaps)->toHaveCount(1);
    expect($gaps[0]['from'])->toBe($locations[1]);
    expect($gaps[0]['to'])->toBe($locations[4]);
    expect($gaps[0]['discarded'])->toBe(2);
    expect($gaps[0]['seconds'])->toBe(60);
    expect($gaps[0]['max_accuracy'])->toBe(7857.0);
});

it('non restituisce tratti per i punti scartati in testa o in coda', function () {
    $locations = [
        cleanupPoint(0.0, 0.0, 5, 0),
        cleanupPoint(43.000, 13.000, 5, 10_000),
        cleanupPoint(43.001, 13.000, 5, 20_000),
        cleanupPoint(43.500, 13.500, 3000, 30_000),
    ];

    expect($this->service->gaps($locations))->toBe([]);
    expect($this->service->keptLocations($locations))->toBe([$locations[1], $locations[2]]);
});

it('riassume punti scartati, accuracy massima e lunghezza prima e dopo', function () {
    $locations = [
        cleanupPoint(44.0, 10.0, 5, 0),
        cleanupPoint(45.0, 10.0, 3000, 1000),
        cleanupPoint(44.0, 10.0, 5, 2000),
    ];

    $summary = $this->service->summary($locations);
    $oneDegreeKm = 6371 * deg2rad(1);

    expect($summary['total'])->toBe(3);
    expect($summary['discarded'])->toBe(1);
    expect($summary['max_discarded_accuracy'])->toBe(3000.0);
    expect($summary['length_before_km'])->toEqualWithDelta(2 * $oneDegreeKm, 0.01);
    expect($summary['length_after_km'])->toEqualWithDelta(0.0, 0.0001);
});

it('costruisce un EWKT MultiLineString Z con la quota, e null con meno di 2 punti', function () {
    $kept = [
        cleanupPoint(43.1, 13.2, 5, 0, 1170.1),
        cleanupPoint(43.3, 13.4, 5, 1000, null),
    ];

    expect($this->service->ewkt($kept))
        ->toBe('SRID=4326;MULTILINESTRING Z ((13.20000000 43.10000000 1170.100, 13.40000000 43.30000000 0.000))');
    expect($this->service->ewkt([$kept[0]]))->toBeNull();
    expect($this->service->ewkt([]))->toBeNull();
});
```

- [ ] **Passo 3: verifica che fallisce**

Run: `... vendor/bin/pest tests/Unit/Services/UgcTrackCleanupServiceTest.php`
Atteso: FAIL, `Class "Wm\WmPackage\Services\Models\UgcTrackCleanupService" not found`.

- [ ] **Passo 4: implementazione**

`src/Services/Models/UgcTrackCleanupService.php`:

```php
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
 * quali punti di `properties.locations` sono utilizzabili; tutto il resto del package (salvataggio,
 * command, statistiche di condivisione, Nova) usa queste regole, non ne ha di proprie.
 *
 * Nessuna regola su tempo o distanza: l'app non salva le pause, e un buco di tempo seguito da uno
 * spostamento è indistinguibile da un segnale perso. Un filtro sui salti scarterebbe punti giusti.
 */
class UgcTrackCleanupService
{
    public static function make(): static
    {
        return new static;
    }

    public function maxAccuracyMeters(): float
    {
        return (float) config('wm-package.ugc_track_max_accuracy_meters', 40);
    }

    /**
     * @return list<mixed>|null
     */
    public function locationsOf(UgcTrack $track): ?array
    {
        $locations = $track->properties['locations'] ?? null;

        return is_array($locations) && $locations !== [] ? array_values($locations) : null;
    }

    public function isUsable(mixed $location): bool
    {
        if (! is_array($location)
            || ! isset($location['latitude'], $location['longitude'])
            || ! is_numeric($location['latitude'])
            || ! is_numeric($location['longitude'])) {
            return false;
        }

        // L'app usa [0, 0, 0] quando la registrazione parte senza una posizione valida.
        if ((float) $location['latitude'] === 0.0 && (float) $location['longitude'] === 0.0) {
            return false;
        }

        $accuracy = $location['accuracy'] ?? null;

        // Accuracy assente o non valida: non c'è modo di giudicare il punto, quindi si tiene.
        if (! is_numeric($accuracy) || (float) $accuracy < 0) {
            return true;
        }

        return (float) $accuracy <= $this->maxAccuracyMeters();
    }

    /**
     * @param  array<int, mixed>  $locations
     * @return list<array<string, mixed>>
     */
    public function keptLocations(array $locations): array
    {
        return array_values(array_filter($locations, fn ($location) => $this->isUsable($location)));
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

        foreach (array_values($locations) as $location) {
            if (! $this->isUsable($location)) {
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
        $kept = $this->keptLocations($locations);
        $discarded = array_values(array_filter($locations, fn ($location) => ! $this->isUsable($location)));
        $withCoordinates = array_values(array_filter($locations, static fn ($location) => is_array($location)
            && is_numeric($location['latitude'] ?? null)
            && is_numeric($location['longitude'] ?? null)));

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

        $coordinates = implode(', ', array_map(static fn (array $location) => sprintf(
            '%.8F %.8F %.3F',
            (float) $location['longitude'],
            (float) $location['latitude'],
            is_numeric($location['altitude'] ?? null) ? (float) $location['altitude'] : 0.0,
        ), $kept));

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
     * Vero se la geometria salvata è diversa da quella pulita (confronto PostGIS, non fra stringhe).
     */
    public function wouldChange(UgcTrack $track): bool
    {
        $ewkt = $this->ewktFor($track);
        if ($ewkt === null || ! $track->exists) {
            return false;
        }

        $row = DB::selectOne(
            "SELECT (geometry IS NULL OR NOT ST_Equals(geometry::geometry, ST_GeomFromEWKT(?))) AS changes FROM {$track->getTable()} WHERE id = ?",
            [$ewkt, $track->id]
        );

        return (bool) ($row->changes ?? false);
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

        return 2 * 6371000 * asin(min(1.0, sqrt($h)));
    }
}
```

- [ ] **Passo 5: verifica che passa**

Run: `... vendor/bin/pest tests/Unit/Services/UgcTrackCleanupServiceTest.php`
Atteso: PASS, 9 test.

- [ ] **Passo 6: commit (solo dopo approvazione del dev)**

```bash
git -C wm-package add config/wm-package.php src/Services/Models/UgcTrackCleanupService.php tests/Unit/Services/UgcTrackCleanupServiceTest.php
git -C wm-package commit -m "feat(oc:8719): regole di pulizia dei punti GPS delle tracce UGC"
```

---

### Task 2: pulizia della geometria al salvataggio

**File:**
- Crea: `src/Observers/UgcTrackGeometryCleanupObserver.php`
- Modifica: `src/Models/UgcTrack.php:42-46` (`booted()`)
- Test: `tests/Feature/UgcTrackGeometryCleanupTest.php`

**Interfacce:**
- Usa: `UgcTrackCleanupService::geometryExpressionFor()`
- Produce: la geometria salvata di ogni UgcTrack con `locations` è quella pulita.

Perché `saving`: Laravel lo emette prima di `creating`/`updating`. `UgcObserver::creating/updating`
normalizza poi la geometria (3D, MultiLineString) e `UgcObserver::created()` calcola il cammino:
entrambi trovano già la geometria pulita. L'observer è registrato dal modello e non in
`UgcObserver`, perché camminiditalia registra una seconda volta una sottoclasse di `UgcObserver`.

- [ ] **Passo 1: test che fallisce**

`tests/Feature/UgcTrackGeometryCleanupTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;

// Base TestCase (Wm\WmPackage\Tests\TestCase, con RefreshDatabase) applicato da tests/Pest.php.

beforeEach(function () {
    config()->set('wm-package.ugc_track_max_accuracy_meters', 40.0);
    $this->withoutMiddleware('auth.jwt');
    $this->artisan('jwt:secret --always-no');
    $this->app_ = App::factory()->createQuietly();
    $this->user = User::factory()->create(['app_id' => $this->app_->id]);
});

/**
 * Traccia sintetica con le stesse caratteristiche della UgcTrack 169: punti buoni lungo un
 * meridiano, una sequenza di posizioni da rete cellulare a chilometri di distanza in mezzo.
 *
 * @return list<array<string, mixed>>
 */
function locationsWithSpikes(): array
{
    $points = [];
    for ($i = 0; $i < 6; $i++) {
        $points[] = ['time' => $i * 10_000, 'latitude' => 43.0 + $i * 0.0001, 'longitude' => 13.0, 'accuracy' => 5.0, 'altitude' => 1000.0 + $i, 'speed' => 1.2];
    }
    $points[] = ['time' => 60_000, 'latitude' => 43.05, 'longitude' => 13.08, 'accuracy' => 2000.0, 'altitude' => 900.0, 'speed' => 0];
    $points[] = ['time' => 70_000, 'latitude' => 42.97, 'longitude' => 12.93, 'accuracy' => 7857.0, 'altitude' => 900.0, 'speed' => 0];
    for ($i = 6; $i < 10; $i++) {
        $points[] = ['time' => ($i + 2) * 10_000, 'latitude' => 43.0 + $i * 0.0001, 'longitude' => 13.0, 'accuracy' => 8.0, 'altitude' => 1000.0 + $i, 'speed' => 1.2];
    }

    return $points;
}

/** @param  list<array<string, mixed>>  $locations */
function featureFromLocations(array $locations, array $extraProperties = [], bool $zeroPointFirst = false): array
{
    $coordinates = array_map(fn ($l) => [$l['longitude'], $l['latitude'], $l['altitude']], $locations);
    if ($zeroPointFirst) {
        array_unshift($coordinates, [0, 0, 0]);
    }

    return [
        'type' => 'Feature',
        'geometry' => ['type' => 'LineString', 'coordinates' => $coordinates],
        'properties' => array_merge(['name' => 'Traccia di prova', 'uuid' => (string) Str::uuid(), 'locations' => $locations], $extraProperties),
    ];
}

function storedPointCount(int $id): int
{
    return (int) DB::selectOne('SELECT ST_NPoints(geometry::geometry) AS n FROM ugc_tracks WHERE id = ?', [$id])->n;
}

function storedTypeAndDims(int $id): array
{
    $row = DB::selectOne('SELECT ST_GeometryType(geometry::geometry) AS t, ST_NDims(geometry::geometry) AS d FROM ugc_tracks WHERE id = ?', [$id]);

    return [$row->t, (int) $row->d];
}

it('salva via API una geometria senza i punti con accuracy oltre la soglia', function () {
    $feature = featureFromLocations(locationsWithSpikes(), ['app_id' => $this->app_->id]);

    $id = $this->actingAs($this->user, 'api')
        ->postJson('/api/v2/ugc/track/store', $feature)
        ->assertStatus(201)
        ->json('id');

    expect(storedPointCount($id))->toBe(10);
    expect(storedTypeAndDims($id))->toBe(['ST_MultiLineString', 3]);
    expect(UgcTrack::find($id)->properties['locations'])->toHaveCount(12);
});

it('toglie il punto (0,0) che l\'app mette in testa alla geometria', function () {
    $locations = array_slice(locationsWithSpikes(), 0, 6);
    $feature = featureFromLocations($locations, ['app_id' => $this->app_->id], zeroPointFirst: true);

    $id = $this->actingAs($this->user, 'api')->postJson('/api/v2/ugc/track/store', $feature)->assertStatus(201)->json('id');

    expect(storedPointCount($id))->toBe(6);
    $minLon = DB::selectOne('SELECT ST_XMin(geometry::geometry) AS x FROM ugc_tracks WHERE id = ?', [$id])->x;
    expect((float) $minLon)->toBeGreaterThan(12.9);
});

it('ripulisce la geometria grezza rimandata dall\'app con la route edit', function () {
    $feature = featureFromLocations(locationsWithSpikes(), ['app_id' => $this->app_->id]);
    $id = $this->actingAs($this->user, 'api')->postJson('/api/v2/ugc/track/store', $feature)->json('id');

    $feature['properties']['id'] = $id;
    $feature['properties']['name'] = 'Nome cambiato dall\'app';
    $this->actingAs($this->user, 'api')->postJson('/api/v2/ugc/track/edit', $feature)->assertStatus(200);

    expect(storedPointCount($id))->toBe(10);
});

it('restituisce all\'app la geometria pulita, in 3D, con uuid e properties invariati', function () {
    $feature = featureFromLocations(locationsWithSpikes(), ['app_id' => $this->app_->id]);
    $this->actingAs($this->user, 'api')->postJson('/api/v2/ugc/track/store', $feature)->assertStatus(201);

    $returned = $this->actingAs($this->user, 'api')
        ->getJson('/api/v2/ugc/track/index')
        ->assertStatus(200)
        ->json('features.0');

    expect($returned['geometry']['type'])->toBe('MultiLineString');
    expect($returned['geometry']['coordinates'][0])->toHaveCount(10);
    expect($returned['geometry']['coordinates'][0][0])->toHaveCount(3);
    expect($returned['properties']['uuid'])->toBe($feature['properties']['uuid']);
    expect($returned['properties']['locations'])->toHaveCount(12);
});

it('non tocca la geometria di una traccia senza locations', function () {
    $track = UgcTrack::factory()->create(['user_id' => $this->user->id, 'app_id' => $this->app_->id, 'properties' => ['name' => 'senza locations']]);
    $before = DB::selectOne('SELECT ST_AsText(geometry::geometry) AS g FROM ugc_tracks WHERE id = ?', [$track->id])->g;

    $track->update(['properties' => ['name' => 'rinominata']]);

    $after = DB::selectOne('SELECT ST_AsText(geometry::geometry) AS g FROM ugc_tracks WHERE id = ?', [$track->id])->g;
    expect($after)->toBe($before);
});

it('lascia la geometria ricevuta se dopo il filtro resta meno di 2 punti', function () {
    $locations = [
        ['time' => 0, 'latitude' => 43.0, 'longitude' => 13.0, 'accuracy' => 5.0, 'altitude' => 10.0],
        ['time' => 1000, 'latitude' => 43.1, 'longitude' => 13.1, 'accuracy' => 500.0, 'altitude' => 10.0],
        ['time' => 2000, 'latitude' => 43.2, 'longitude' => 13.2, 'accuracy' => 900.0, 'altitude' => 10.0],
    ];
    $feature = featureFromLocations($locations, ['app_id' => $this->app_->id]);

    $id = $this->actingAs($this->user, 'api')->postJson('/api/v2/ugc/track/store', $feature)->assertStatus(201)->json('id');

    expect(storedPointCount($id))->toBe(3);
});
```

Nota: il controllo «cammino calcolato sulla geometria pulita» discende dall'ordine degli eventi
(`saving` prima di `created`) e si verifica sul DB di sviluppo nel Task 7 con la traccia 223
(layer 54 sulla geometria grezza, 68 su quella pulita): `resolveLayerByProximity()` cerca il
pivot con `layerable_type = 'App\Models\EcTrack'` scritto nel codice, che nei test del package
richiederebbe di ricostruire a mano il morph map dello shard.

- [ ] **Passo 2: verifica che fallisce**

Run: `... vendor/bin/pest tests/Feature/UgcTrackGeometryCleanupTest.php`
Atteso: FAIL sui primi quattro test (la geometria ha 12 o 7 punti).

- [ ] **Passo 3: implementazione**

`src/Observers/UgcTrackGeometryCleanupObserver.php`:

```php
<?php

namespace Wm\WmPackage\Observers;

use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Services\Models\UgcTrackCleanupService;

/**
 * Ricostruisce la geometria di una UgcTrack da properties.locations, scartando i punti GPS
 * inutilizzabili (oc:8719).
 *
 * Su `saving`, che Laravel emette prima di `creating`/`updating`: la normalizzazione di
 * UgcObserver e il calcolo del cammino in UgcObserver::created() trovano già la geometria pulita.
 * Registrato dal modello e non dentro UgcObserver, perché un consumer (camminiditalia) registra
 * una seconda volta una sottoclasse di UgcObserver.
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
    }
}
```

In `src/Models/UgcTrack.php`, `booted()`:

```php
    protected static function booted()
    {
        parent::booted();
        UgcTrack::observe(UgcObserver::class);
        UgcTrack::observe(UgcTrackGeometryCleanupObserver::class);
    }
```

con `use Wm\WmPackage\Observers\UgcTrackGeometryCleanupObserver;` fra gli import.

- [ ] **Passo 4: verifica che passa**

Run: `... vendor/bin/pest tests/Feature/UgcTrackGeometryCleanupTest.php`
Atteso: PASS, 6 test.

Run anche i test esistenti che salvano UgcTrack:
`... vendor/bin/pest tests/Feature/ShareUgcTrackPageTest.php tests/Feature/ShareStoryImageControllerTest.php tests/Feature/Import/ImportUgcTrackJobTest.php`
Atteso: PASS, nessuna regressione.

- [ ] **Passo 5: commit (solo dopo approvazione del dev)**

```bash
git -C wm-package add src/Observers/UgcTrackGeometryCleanupObserver.php src/Models/UgcTrack.php tests/Feature/UgcTrackGeometryCleanupTest.php
git -C wm-package commit -m "feat(oc:8719): pulizia della geometria delle tracce UGC al salvataggio"
```

---

### Task 3: statistiche dell'immagine di condivisione sui soli punti tenuti

**File:**
- Modifica: `src/Services/Models/StoryShare/TrackStatsService.php` (costruttore e `compute()`, righe 47-72)
- Test: `tests/Unit/Services/StoryShare/TrackStatsServiceTest.php` (aggiunta in fondo)

**Interfacce:**
- Usa: `UgcTrackCleanupService::keptLocations()`
- Produce: `TrackStatsService::compute(array $locations)` invariato nella firma.

- [ ] **Passo 1: test che fallisce**

In fondo a `tests/Unit/Services/StoryShare/TrackStatsServiceTest.php`:

```php
it('ignora i punti con accuracy oltre la soglia di pulizia e il punto (0,0) (oc:8719)', function () {
    config()->set('wm-package.ugc_track_max_accuracy_meters', 40.0);

    $result = (new TrackStatsService)->compute([
        ['time' => 0, 'latitude' => 44.0, 'longitude' => 10.0, 'accuracy' => 5],
        ['time' => 500, 'latitude' => 0.0, 'longitude' => 0.0, 'accuracy' => 5],
        ['time' => 1000, 'latitude' => 45.0, 'longitude' => 10.0, 'accuracy' => 3000],
        ['time' => 2000, 'latitude' => 44.0, 'longitude' => 10.0, 'accuracy' => 5],
    ]);

    expect($result['distance_km'])->toEqualWithDelta(0.0, 0.0001);
    expect($result['duration_seconds'])->toBe(2);
});
```

- [ ] **Passo 2: verifica che fallisce**

Run: `... vendor/bin/pest tests/Unit/Services/StoryShare/TrackStatsServiceTest.php`
Atteso: FAIL sul nuovo test (distanza di migliaia di km).

- [ ] **Passo 3: implementazione**

In `TrackStatsService`, aggiungi il costruttore e sostituisci il filtro iniziale di `compute()`:

```php
    private UgcTrackCleanupService $cleanup;

    /**
     * Le statistiche si calcolano sugli stessi punti della geometria pulita (oc:8719): senza,
     * l'immagine condivisa mostrerebbe la distanza gonfiata dai punti da rete cellulare.
     */
    public function __construct(?UgcTrackCleanupService $cleanup = null)
    {
        $this->cleanup = $cleanup ?? UgcTrackCleanupService::make();
    }

    public function compute(array $locations): array
    {
        $points = $this->cleanup->keptLocations($locations);

        if (count($points) < 2) {
            // ... (blocco esistente invariato)
```

con `use Wm\WmPackage\Services\Models\UgcTrackCleanupService;`. `keptLocations()` scarta già i punti
senza coordinate numeriche, come il filtro sostituito; i punti senza `accuracy` dei test esistenti
restano tenuti. Aggiorna il docblock della classe («only on the raw `locations` array shape»)
aggiungendo che i punti passano dalle regole di `UgcTrackCleanupService`.

- [ ] **Passo 4: verifica che passa**

Run: `... vendor/bin/pest tests/Unit/Services/StoryShare/TrackStatsServiceTest.php tests/Feature/ShareStoryImageControllerTest.php`
Atteso: PASS, inclusi tutti i test esistenti.

- [ ] **Passo 5: commit (solo dopo approvazione del dev)**

```bash
git -C wm-package add src/Services/Models/StoryShare/TrackStatsService.php tests/Unit/Services/StoryShare/TrackStatsServiceTest.php
git -C wm-package commit -m "feat(oc:8719): statistiche di condivisione sui soli punti GPS utilizzabili"
```

---

### Task 4: job e command per le tracce esistenti

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-4-confronto-delle-geometrie-con-tolleranza)

**File:**
- Crea: `src/Jobs/CleanUgcTrackGeometryJob.php`
- Crea: `src/Commands/WmCleanUgcTrackGeometryCommand.php`
- Modifica: `src/WmPackageServiceProvider.php` (import fra le righe 23-30 ed elenco dei command registrati, accanto a `WmSyncUgcTaxonomyWhereCommand`)
- Test: `tests/Feature/WmCleanUgcTrackGeometryCommandTest.php`

**Interfacce:**
- Usa: `ewktFor()`, `wouldChange()`, `summary()`, `locationsOf()`
- Produce: comando `wm:clean-ugc-track-geometry {--dry-run} {--app-id=} {--queue=default}`;
  job `CleanUgcTrackGeometryJob(int $ugcTrackId)`.

Il job scrive con un `UPDATE` SQL: non riattiva gli observer e non fa transitare la geometria
dall'ORM.

- [ ] **Passo 1: test che fallisce**

`tests/Feature/WmCleanUgcTrackGeometryCommandTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Jobs\CleanUgcTrackGeometryJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;

beforeEach(function () {
    config()->set('wm-package.ugc_track_max_accuracy_meters', 40.0);
    $this->app_ = App::factory()->createQuietly();
    $this->user = User::factory()->create(['app_id' => $this->app_->id]);
});

/**
 * Traccia «vecchia»: creata senza observer, con la geometria grezza (punto sbagliato incluso),
 * come quelle salvate prima di oc:8719.
 */
function legacyTrack(int $userId, int $appId): UgcTrack
{
    $locations = [
        ['time' => 0, 'latitude' => 43.0, 'longitude' => 13.0, 'accuracy' => 5.0, 'altitude' => 10.0],
        ['time' => 1000, 'latitude' => 43.5, 'longitude' => 13.5, 'accuracy' => 3000.0, 'altitude' => 10.0],
        ['time' => 2000, 'latitude' => 43.0001, 'longitude' => 13.0, 'accuracy' => 5.0, 'altitude' => 10.0],
    ];
    $track = UgcTrack::withoutEvents(fn () => UgcTrack::factory()->create([
        'user_id' => $userId,
        'app_id' => $appId,
        'properties' => ['name' => 'vecchia', 'locations' => $locations],
    ]));
    DB::update(
        "UPDATE ugc_tracks SET geometry = ST_GeomFromEWKT('SRID=4326;MULTILINESTRING Z ((13 43 10, 13.5 43.5 10, 13 43.0001 10))') WHERE id = ?",
        [$track->id]
    );

    return $track->refresh();
}

function pointCount(int $id): int
{
    return (int) DB::selectOne('SELECT ST_NPoints(geometry::geometry) AS n FROM ugc_tracks WHERE id = ?', [$id])->n;
}

it('con --dry-run elenca le tracce che cambierebbero senza scrivere né accodare', function () {
    Bus::fake();
    $track = legacyTrack($this->user->id, $this->app_->id);

    $this->artisan('wm:clean-ugc-track-geometry', ['--dry-run' => true])
        ->expectsOutputToContain('1 tracce cambierebbero')
        ->assertSuccessful();

    Bus::assertNotDispatched(CleanUgcTrackGeometryJob::class);
    expect(pointCount($track->id))->toBe(3);
});

it('senza --dry-run accoda un job per ogni traccia che cambia', function () {
    Bus::fake();
    $track = legacyTrack($this->user->id, $this->app_->id);

    $this->artisan('wm:clean-ugc-track-geometry')->assertSuccessful();

    Bus::assertDispatched(CleanUgcTrackGeometryJob::class, fn ($job) => $job->ugcTrackId === $track->id);
});

it('il job riscrive la geometria pulita ed è idempotente', function () {
    $track = legacyTrack($this->user->id, $this->app_->id);

    (new CleanUgcTrackGeometryJob($track->id))->handle(app(\Wm\WmPackage\Services\Models\UgcTrackCleanupService::class));
    expect(pointCount($track->id))->toBe(2);
    expect(\Wm\WmPackage\Services\Models\UgcTrackCleanupService::make()->wouldChange($track->refresh()))->toBeFalse();

    (new CleanUgcTrackGeometryJob($track->id))->handle(app(\Wm\WmPackage\Services\Models\UgcTrackCleanupService::class));
    expect(pointCount($track->id))->toBe(2);
});

it('salta le tracce già pulite e quelle senza locations', function () {
    Bus::fake();
    UgcTrack::factory()->create(['user_id' => $this->user->id, 'app_id' => $this->app_->id, 'properties' => ['name' => 'senza locations']]);

    $this->artisan('wm:clean-ugc-track-geometry', ['--dry-run' => true])
        ->expectsOutputToContain('0 tracce cambierebbero')
        ->assertSuccessful();
});
```

- [ ] **Passo 2: verifica che fallisce**

Run: `... vendor/bin/pest tests/Feature/WmCleanUgcTrackGeometryCommandTest.php`
Atteso: FAIL, comando `wm:clean-ugc-track-geometry` non definito.

- [ ] **Passo 3: implementazione**

`src/Jobs/CleanUgcTrackGeometryJob.php`:

```php
<?php

namespace Wm\WmPackage\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Services\Models\UgcTrackCleanupService;

/**
 * Applica la pulizia GPS (oc:8719) a una traccia già salvata. Scrive con un UPDATE SQL: non
 * riattiva gli observer e non fa transitare la geometria dall'ORM. Rilanciarlo non cambia nulla.
 */
class CleanUgcTrackGeometryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $ugcTrackId) {}

    public function handle(UgcTrackCleanupService $cleanup): void
    {
        $track = UgcTrack::find($this->ugcTrackId);
        if (! $track) {
            return;
        }

        $ewkt = $cleanup->ewktFor($track);
        if ($ewkt === null) {
            return;
        }

        DB::update(
            "UPDATE {$track->getTable()} SET geometry = ST_GeomFromEWKT(?), updated_at = NOW()
             WHERE id = ? AND (geometry IS NULL OR NOT ST_Equals(geometry::geometry, ST_GeomFromEWKT(?)))",
            [$ewkt, $track->id, $ewkt]
        );
    }
}
```

`src/Commands/WmCleanUgcTrackGeometryCommand.php`:

```php
<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Wm\WmPackage\Jobs\CleanUgcTrackGeometryJob;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Services\Models\UgcTrackCleanupService;

class WmCleanUgcTrackGeometryCommand extends Command
{
    protected $signature = 'wm:clean-ugc-track-geometry
                            {--dry-run : Elenca le tracce che cambierebbero, senza scrivere nulla}
                            {--app-id= : Limita ai record con questo app_id}
                            {--queue=default : Coda su cui accodare i job}';

    protected $description = 'Ricostruisce la geometria delle UgcTrack da properties.locations scartando i punti GPS inutilizzabili (oc:8719).';

    public function handle(UgcTrackCleanupService $cleanup): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $queue = (string) $this->option('queue');
        $appId = $this->option('app-id');

        $query = UgcTrack::query()->whereRaw("jsonb_typeof(properties::jsonb->'locations') = 'array'");
        if ($appId !== null && $appId !== '') {
            $query->where('app_id', $appId);
        }

        $rows = [];
        $query->chunkById(200, function ($tracks) use ($cleanup, $dryRun, $queue, &$rows) {
            foreach ($tracks as $track) {
                if (! $cleanup->wouldChange($track)) {
                    continue;
                }

                $summary = $cleanup->summary($cleanup->locationsOf($track) ?? []);
                $rows[] = [
                    $track->id,
                    $summary['discarded'].'/'.$summary['total'],
                    sprintf('%.1f', $summary['length_before_km']),
                    sprintf('%.1f', $summary['length_after_km']),
                ];

                if (! $dryRun) {
                    CleanUgcTrackGeometryJob::dispatch($track->id)->onQueue($queue);
                }
            }
        });

        $this->table(['id', 'punti scartati/totale', 'km prima', 'km dopo'], $rows);
        $this->info($dryRun
            ? count($rows).' tracce cambierebbero (dry-run, nessuna scrittura).'
            : count($rows).' job accodati sulla coda '.$queue.'.');

        return self::SUCCESS;
    }
}
```

In `src/WmPackageServiceProvider.php`: `use Wm\WmPackage\Commands\WmCleanUgcTrackGeometryCommand;`
e aggiungi `WmCleanUgcTrackGeometryCommand::class` all'elenco dei command registrati (stesso elenco
di `WmSyncUgcTaxonomyWhereCommand::class`).

- [ ] **Passo 4: verifica che passa**

Run: `... vendor/bin/pest tests/Feature/WmCleanUgcTrackGeometryCommandTest.php`
Atteso: PASS, 4 test.

- [ ] **Passo 5: commit (solo dopo approvazione del dev)**

```bash
git -C wm-package add src/Jobs/CleanUgcTrackGeometryJob.php src/Commands/WmCleanUgcTrackGeometryCommand.php src/WmPackageServiceProvider.php tests/Feature/WmCleanUgcTrackGeometryCommandTest.php
git -C wm-package commit -m "feat(oc:8719): command per pulire la geometria delle tracce UGC esistenti"
```

---

### Task 5: Nova — geometria in sola lettura, riepilogo e tratti ricostruiti

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-5-due-modifiche-al-test-di-nova)

**File:**
- Modifica: `src/Nova/UgcTrack.php` (non usa più `MultiLinestringResourceTrait`, dichiara i campi)
- Modifica: `src/Models/UgcTrack.php` (nuovo `getFeatureCollectionMap()`)
- Modifica: `resources/lang/{it,en,de,es,fr}.json`
- Test: `tests/Feature/Nova/UgcTrackCleanupNovaTest.php`

**Interfacce:**
- Usa: `locationsOf()`, `summary()`, `gaps()`
- Produce: feature con `properties.strokeDash = [8, 8]` per i tratti ricostruiti (usata dal Task 6).

Deviazione dichiarata dall'overview: il dettaglio di un tratto ricostruito compare **al passaggio
del mouse** (proprietà `tooltip`, già gestita dal campo per ogni feature), non al click: il popup
al click del campo mostra solo titolo e «Vai alla risorsa», non un testo libero.

- [ ] **Passo 1: test che fallisce**

`tests/Feature/Nova/UgcTrackCleanupNovaTest.php`:

```php
<?php

declare(strict_types=1);

use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Nova\UgcTrack as UgcTrackResource;

beforeEach(function () {
    config()->set('wm-package.ugc_track_max_accuracy_meters', 40.0);
    $this->app_ = App::factory()->createQuietly();
    $this->user = User::factory()->create(['app_id' => $this->app_->id]);
});

function trackWithLocations(int $userId, int $appId): UgcTrack
{
    return UgcTrack::factory()->create([
        'user_id' => $userId,
        'app_id' => $appId,
        'properties' => ['name' => 'con locations', 'locations' => [
            ['time' => 0, 'latitude' => 43.0, 'longitude' => 13.0, 'accuracy' => 5.0, 'altitude' => 10.0],
            ['time' => 10_000, 'latitude' => 43.5, 'longitude' => 13.5, 'accuracy' => 7857.0, 'altitude' => 10.0],
            ['time' => 70_000, 'latitude' => 43.0001, 'longitude' => 13.0, 'accuracy' => 6.0, 'altitude' => 10.0],
        ]],
    ]);
}

function updateFieldAttributes(UgcTrack $track): array
{
    $request = NovaRequest::create('/nova-api/ugc-tracks/'.$track->id.'/update-fields', 'GET');

    return (new UgcTrackResource($track))
        ->updateFields($request)
        ->map(fn ($field) => $field->attribute)
        ->all();
}

it('nasconde il campo geometria in modifica se la traccia ha locations', function () {
    $this->actingAs($this->user);

    expect(updateFieldAttributes(trackWithLocations($this->user->id, $this->app_->id)))->not->toContain('geometry');
});

it('lascia il campo geometria modificabile se la traccia non ha locations', function () {
    $this->actingAs($this->user);
    $track = UgcTrack::factory()->create(['user_id' => $this->user->id, 'app_id' => $this->app_->id, 'properties' => ['name' => 'senza']]);

    expect(updateFieldAttributes($track))->toContain('geometry');
});

it('aggiunge alla mappa un tratto tratteggiato per ogni sequenza di punti scartati', function () {
    $collection = trackWithLocations($this->user->id, $this->app_->id)->getFeatureCollectionMap();

    $dashed = array_values(array_filter($collection['features'], fn ($f) => isset($f['properties']['strokeDash'])));

    expect($dashed)->toHaveCount(1);
    expect($dashed[0]['geometry'])->toBe(['type' => 'LineString', 'coordinates' => [[13.0, 43.0], [13.0, 43.0001]]]);
    expect($dashed[0]['properties']['strokeDash'])->toBe([8, 8]);
    expect($dashed[0]['properties']['tooltip'])->toContain('7857');
});

it('non aggiunge tratti per una traccia senza locations', function () {
    $track = UgcTrack::factory()->create(['user_id' => $this->user->id, 'app_id' => $this->app_->id, 'properties' => ['name' => 'senza']]);

    $dashed = array_filter($track->getFeatureCollectionMap()['features'], fn ($f) => isset($f['properties']['strokeDash']));

    expect($dashed)->toBe([]);
});
```

- [ ] **Passo 2: verifica che fallisce**

Run: `... vendor/bin/pest tests/Feature/Nova/UgcTrackCleanupNovaTest.php`
Atteso: FAIL (campo `geometry` presente in modifica, nessuna feature con `strokeDash`).

- [ ] **Passo 3: risorsa Nova**

`src/Nova/UgcTrack.php` diventa:

```php
<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\UgcTrack as UgcTrackModel;
use Wm\WmPackage\Nova\Actions\DownloadUgcTrackAction;
use Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src\FeatureCollectionMap;
use Wm\WmPackage\Services\Models\UgcTrackCleanupService;

class UgcTrack extends AbstractUgcResource
{
    public static $model = \Wm\WmPackage\Models\UgcTrack::class;

    public static function label(): string
    {
        return __('Tracks');
    }

    public static function singularLabel(): string
    {
        return __('UGC Track');
    }

    public function fields(NovaRequest $request): array
    {
        $cleanup = UgcTrackCleanupService::make();

        return [
            ...parent::fields($request),
            FeatureCollectionMap::make('Geometry', 'geometry')
                ->hideFromIndex()
                ->required()
                // oc:8719: la geometria di una traccia registrata dall'app deriva da
                // properties.locations e viene ricostruita a ogni salvataggio: un GPX caricato
                // qui verrebbe sovrascritto in silenzio.
                ->hideWhenUpdating(fn ($request, $resource) => $resource instanceof UgcTrackModel
                    && $cleanup->locationsOf($resource) !== null),
            Text::make(__('GPS cleanup'), function () use ($cleanup) {
                $locations = $this->resource instanceof UgcTrackModel ? $cleanup->locationsOf($this->resource) : null;
                if ($locations === null) {
                    return null;
                }

                $summary = $cleanup->summary($locations);
                if ($summary['discarded'] === 0) {
                    return __('No points discarded');
                }

                return __(':discarded of :total points discarded (max accuracy :accuracy m) · length :before km → :after km', [
                    'discarded' => $summary['discarded'],
                    'total' => $summary['total'],
                    'accuracy' => (int) round($summary['max_discarded_accuracy']),
                    'before' => number_format($summary['length_before_km'], 1, ',', ''),
                    'after' => number_format($summary['length_after_km'], 1, ',', ''),
                ]);
            })->onlyOnDetail(),
        ];
    }

    public function actions(NovaRequest $request): array
    {
        return [
            ...parent::actions($request),
            new DownloadUgcTrackAction,
        ];
    }
}
```

(Il trait `MultiLinestringResourceTrait` resta per `EcTrack`, che lo usa ancora.)

- [ ] **Passo 4: tratti ricostruiti nel modello**

In `src/Models/UgcTrack.php` aggiungi (con `use Wm\WmPackage\Services\Models\UgcTrackCleanupService;`):

```php
    /**
     * Mappa Nova: oltre alla geometria, i tratti ricostruiti al posto dei punti GPS scartati
     * (oc:8719), tratteggiati. I punti scartati non si disegnano: sono a chilometri dalla traccia
     * e allargherebbero la mappa.
     */
    public function getFeatureCollectionMap(): array
    {
        $collection = parent::getFeatureCollectionMap();
        $cleanup = UgcTrackCleanupService::make();
        $locations = $cleanup->locationsOf($this);

        if ($locations === null) {
            return $collection;
        }

        foreach ($cleanup->gaps($locations) as $gap) {
            $collection['features'][] = [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'LineString',
                    'coordinates' => [
                        [(float) $gap['from']['longitude'], (float) $gap['from']['latitude']],
                        [(float) $gap['to']['longitude'], (float) $gap['to']['latitude']],
                    ],
                ],
                'properties' => [
                    'strokeColor' => 'rgba(234, 88, 12, 1)',
                    'strokeWidth' => 4,
                    'strokeDash' => [8, 8],
                    'tooltip' => __('Reconstructed segment: :discarded points discarded in :minutes min (max accuracy :accuracy m)', [
                        'discarded' => $gap['discarded'],
                        'minutes' => (int) ceil($gap['seconds'] / 60),
                        'accuracy' => (int) round($gap['max_accuracy']),
                    ]),
                ],
            ];
        }

        return $collection;
    }
```

- [ ] **Passo 5: traduzioni**

Aggiungi le quattro chiavi in ciascun file, mantenendo l'ordine alfabetico se il file lo segue:

| Chiave (en.json = chiave) | it | de | es | fr |
|---|---|---|---|---|
| `GPS cleanup` | Pulizia GPS | GPS-Bereinigung | Limpieza GPS | Nettoyage GPS |
| `No points discarded` | Nessun punto scartato | Keine Punkte verworfen | Ningún punto descartado | Aucun point écarté |
| `:discarded of :total points discarded (max accuracy :accuracy m) · length :before km → :after km` | :discarded punti scartati su :total (accuracy massima :accuracy m) · lunghezza :before km → :after km | :discarded von :total Punkten verworfen (maximale Genauigkeit :accuracy m) · Länge :before km → :after km | :discarded de :total puntos descartados (precisión máxima :accuracy m) · longitud :before km → :after km | :discarded points écartés sur :total (précision maximale :accuracy m) · longueur :before km → :after km |
| `Reconstructed segment: :discarded points discarded in :minutes min (max accuracy :accuracy m)` | Tratto ricostruito: :discarded punti scartati in :minutes min (accuracy massima :accuracy m) | Rekonstruierter Abschnitt: :discarded Punkte in :minutes Min. verworfen (maximale Genauigkeit :accuracy m) | Tramo reconstruido: :discarded puntos descartados en :minutes min (precisión máxima :accuracy m) | Tronçon reconstruit : :discarded points écartés en :minutes min (précision maximale :accuracy m) |

Verifica che i file restino JSON valido:
`for f in it en de es fr; do php -r "json_decode(file_get_contents('wm-package/resources/lang/$f.json'), flags: JSON_THROW_ON_ERROR);" && echo "$f ok"; done`

- [ ] **Passo 6: verifica che passa**

Run: `... vendor/bin/pest tests/Feature/Nova/UgcTrackCleanupNovaTest.php tests/Unit/Policies/UgcTrackPolicyTest.php`
Atteso: PASS.

- [ ] **Passo 7: commit (solo dopo approvazione del dev)**

```bash
git -C wm-package add src/Nova/UgcTrack.php src/Models/UgcTrack.php resources/lang tests/Feature/Nova/UgcTrackCleanupNovaTest.php
git -C wm-package commit -m "feat(oc:8719): riepilogo della pulizia GPS e tratti ricostruiti in Nova"
```

---

### Task 6: tratteggio nel campo `FeatureCollectionMap`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-6-legenda-nel-campo-mappa)

**File:**
- Modifica: `src/Nova/Fields/FeatureCollectionMap/resources/js/components/FeatureCollectionMap.vue` (stile delle linee, righe 326-334)
- Modifica: `src/Nova/Fields/FeatureCollectionMap/README.md` (tabella «Properties per Linee/Poligoni», riga 228)
- Rigenera: `src/Nova/Fields/FeatureCollectionMap/dist/`

- [ ] **Passo 1: stile**

Nel `return new Style({...})` finale della funzione di stile:

```js
            return new Style({
                stroke: new Stroke({
                    color: featureProps.strokeColor || 'rgba(0, 0, 255, 1)',
                    width: featureProps.strokeWidth || 3,
                    // Tratteggio opzionale, es. [8, 8] per i tratti ricostruiti delle tracce UGC
                    // (oc:8719). Senza la proprietà la linea resta continua come prima.
                    lineDash: Array.isArray(featureProps.strokeDash) ? featureProps.strokeDash : undefined
                }),
                fill: new Fill({
                    color: featureProps.fillColor || 'rgba(0, 0, 255, 0.3)'
                })
            });
```

- [ ] **Passo 2: README**

Nella tabella «Properties per Linee/Poligoni», dopo `strokeWidth`:

```markdown
| `strokeDash` | number[] | Tratteggio della linea (es. `[8, 8]`); assente = linea continua | - |
```

- [ ] **Passo 3: build**

```bash
cd wm-package/src/Nova/Fields/FeatureCollectionMap && npm run prod
```

Atteso: build completata. Poi `git -C wm-package diff --stat -- src/Nova/Fields/FeatureCollectionMap/dist`
deve mostrare solo `dist/js/field.js` (ed eventualmente `mix-manifest.json`); se compaiono altre
differenze non legate a `lineDash`, fermati e segnalalo.

- [ ] **Passo 4: test JS esistenti**

```bash
cd wm-package/src/Nova/Fields/FeatureCollectionMap && npm test
```

Atteso: PASS.

- [ ] **Passo 5: commit (solo dopo approvazione del dev)**

```bash
git -C wm-package add src/Nova/Fields/FeatureCollectionMap
git -C wm-package commit -m "feat(oc:8719): tratteggio opzionale delle linee nel campo FeatureCollectionMap"
```

---

### Task 7: verifiche finali e aggiornamento del submodule in camminiditalia

- [ ] **Passo 1: suite dei test toccati**

```bash
docker run --rm --network camminiditalia_default -e DB_HOST=postgres-camminiditalia \
  -v "$PWD/wm-package:/app" -w /app wm-phpfpm:8.4 vendor/bin/pest \
  tests/Unit/Services/UgcTrackCleanupServiceTest.php tests/Feature/UgcTrackGeometryCleanupTest.php \
  tests/Unit/Services/StoryShare tests/Feature/WmCleanUgcTrackGeometryCommandTest.php \
  tests/Feature/Nova/UgcTrackCleanupNovaTest.php tests/Feature/ShareUgcTrackPageTest.php \
  tests/Feature/ShareStoryImageControllerTest.php tests/Feature/Import/ImportUgcTrackJobTest.php
```

Atteso: tutto PASS.

- [ ] **Passo 2: dry-run sul DB di sviluppo di camminiditalia**

Riavvia Horizon prima (le classi Job modificate non vengono ricaricate da un worker attivo):
`docker restart horizon-camminiditalia`.

```bash
docker exec laravel-camminiditalia php artisan wm:clean-ugc-track-geometry --dry-run
```

Atteso: circa 57 tracce; la 169 con `100+/1016` circa e km da ~112 a ~28; le 217 e 238 presenti.

- [ ] **Passo 3: pulizia reale e verifica del cammino sulla 223**

Solo dopo l'ok del dev (scrive sul DB di sviluppo):

```bash
docker exec laravel-camminiditalia php artisan wm:clean-ugc-track-geometry --queue=default
```

Poi verifica in Nova `/nova/resources/ugc-tracks/169`: linea senza salti, tratti tratteggiati arancioni
con il tooltip, riepilogo «Pulizia GPS», campo geometria assente nel form di modifica.

Per il cammino calcolato sulla geometria pulita: in tinker, sulla 223, azzera `layer_id` e risalva
la traccia con `properties` invariate, poi rileggi `properties.layer_id`; atteso il layer della
tappa vicina alla geometria pulita (68), non 54.

- [ ] **Passo 4: verifica manuale sul dispositivo**

Con un account di test: registra una traccia, sincronizza, poi sincronizza di nuovo e verifica che
la traccia mostrata dall'app sia quella del server (pulita).

- [ ] **Passo 5: puntatore del submodule in camminiditalia (solo dopo il merge in wm-package)**

```bash
git -C /Users/bongiu/Documents/camminiditalia add wm-package
git -C /Users/bongiu/Documents/camminiditalia commit -m "feat(oc:8719): aggiorna wm-package con la pulizia GPS delle tracce UGC"
```
