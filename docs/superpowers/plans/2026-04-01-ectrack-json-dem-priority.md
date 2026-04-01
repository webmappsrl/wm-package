# EcTrack JSON DEM Priority Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Il JSON del track pubblicato su S3/MinIO espone i 9 campi DEM come valori flat calcolati con priorità MANUAL > OSM > DEM, senza esporre le sorgenti raw.

**Architecture:** Si modifica `EcTrackResource::toArray()` per sostituire il blocco dem_data attuale con la logica di priorità del trait `HasDemClassification`. Le sorgenti raw (`manual_data`, `dem_data`, `osm_data`) vengono rimosse dal JSON finale.

**Tech Stack:** Laravel 12, PHP 8.4, Pest, wm-package trait `HasDemClassification`.

---

## File Map

| Azione | File |
|--------|------|
| Modify | `wm-package/src/Http/Resources/EcTrackResource.php` |
| Create | `wm-package/tests/Unit/Http/Resources/EcTrackResourceDemPriorityTest.php` |

---

### Task 1: Scrivere i test fallenti

**Files:**
- Create: `wm-package/tests/Unit/Http/Resources/EcTrackResourceDemPriorityTest.php`

- [ ] **Step 1: Creare il file di test**

```php
<?php

use Wm\WmPackage\Http\Resources\EcTrackResource;
use Wm\WmPackage\Models\EcTrack;
use Illuminate\Http\Request;

function makeTrack(array $properties, ?int $osmid = null): EcTrack
{
    $track = Mockery::mock(EcTrack::class)->makePartial();
    $track->id = 1;
    $track->properties = $properties;
    $track->osmid = $osmid;
    $track->geometry = 'some-geometry';
    $track->shouldReceive('getGeojson')->andReturn([
        'type' => 'Feature',
        'geometry' => ['type' => 'LineString', 'coordinates' => [[0, 0], [1, 1]]],
        'properties' => array_merge(['id' => 1], $properties),
    ]);
    $track->shouldReceive('getMedia')->andReturn(collect());
    $track->shouldReceive('ecPois')->andReturn(collect());
    $track->shouldReceive('getTranslations')->andReturn([]);
    return $track;
}

it('usa dem_data quando è l\'unica sorgente', function () {
    $track = makeTrack([
        'dem_data' => ['ascent' => 500, 'distance' => 3000],
    ]);
    $resource = new EcTrackResource($track);
    $data = $resource->toArray(new Request());

    expect($data['properties']['ascent'])->toBe(500)
        ->and($data['properties']['distance'])->toBe(3000)
        ->and($data['properties'])->not->toHaveKey('dem_data');
});

it('manual_data vince su dem_data', function () {
    $track = makeTrack([
        'dem_data' => ['ascent' => 500],
        'manual_data' => ['ascent' => '73'],
    ]);
    $resource = new EcTrackResource($track);
    $data = $resource->toArray(new Request());

    expect($data['properties']['ascent'])->toBe('73')
        ->and($data['properties'])->not->toHaveKey('manual_data');
});

it('manual_data stringa vuota scende a osm_data se osmid presente', function () {
    $track = makeTrack([
        'dem_data' => ['ascent' => 500],
        'osm_data' => ['ascent' => 200],
        'manual_data' => ['ascent' => ''],
    ], osmid: 12345);
    $resource = new EcTrackResource($track);
    $data = $resource->toArray(new Request());

    expect($data['properties']['ascent'])->toBe(200)
        ->and($data['properties'])->not->toHaveKey('osm_data');
});

it('osm_data ignorato se osmid è null', function () {
    $track = makeTrack([
        'dem_data' => ['ascent' => 500],
        'osm_data' => ['ascent' => 200],
        'manual_data' => ['ascent' => ''],
    ], osmid: null);
    $resource = new EcTrackResource($track);
    $data = $resource->toArray(new Request());

    expect($data['properties']['ascent'])->toBe(500);
});

it('campo assente se tutte le sorgenti mancano', function () {
    $track = makeTrack([]);
    $resource = new EcTrackResource($track);
    $data = $resource->toArray(new Request());

    expect($data['properties'])->not->toHaveKey('ascent')
        ->and($data['properties'])->not->toHaveKey('distance');
});

it('properties null non causa crash', function () {
    $track = makeTrack([]);
    $track->properties = null;
    $resource = new EcTrackResource($track);

    expect(fn () => $resource->toArray(new Request()))->not->toThrow(\Exception::class);
});
```

- [ ] **Step 2: Eseguire i test per verificare che falliscano**

```bash
docker exec -it php-forestas vendor/bin/pest tests/Unit/Http/Resources/EcTrackResourceDemPriorityTest.php -v
```

Atteso: FAIL (i test non passano con la logica attuale).

---

### Task 2: Implementare la logica di priorità in `EcTrackResource`

**Files:**
- Modify: `wm-package/src/Http/Resources/EcTrackResource.php`

- [ ] **Step 1: Aggiungere il trait e la costante**

In cima alla classe, dopo `use JsonResource;`:

```php
use Wm\WmPackage\Nova\Traits\HasDemClassification;

class EcTrackResource extends JsonResource
{
    use HasDemClassification;

    private const DEM_FIELDS = [
        'distance', 'ascent', 'descent',
        'ele_max', 'ele_min', 'ele_from', 'ele_to',
        'duration_forward', 'duration_backward',
    ];
```

- [ ] **Step 2: Sostituire il blocco dem_data in `toArray()`**

Rimuovere il blocco attuale (righe 32-38):

```php
// Copia tutti gli attributi da dem_data se non presenti o null in properties
if (isset($geojson['properties']['dem_data']) && is_array($geojson['properties']['dem_data'])) {
    foreach ($geojson['properties']['dem_data'] as $key => $value) {
        if (! isset($properties[$key]) || $properties[$key] === null) {
            $properties[$key] = $value;
        }
    }
    // Rimuovi dem_data dalle properties finali (gli attributi sono stati copiati flat)
    unset($properties['dem_data']);
}
```

Sostituire con:

```php
// Applica priorità MANUAL > OSM > DEM per i 9 campi principali
foreach (self::DEM_FIELDS as $field) {
    $classified = $this->classifyField($this->resource, $field);
    if ($classified['currentValue'] !== null) {
        $properties[$field] = $classified['currentValue'];
    } else {
        unset($properties[$field]);
    }
}

// Rimuovi sorgenti raw: non servono lato frontend
unset($properties['manual_data'], $properties['dem_data'], $properties['osm_data']);
```

- [ ] **Step 3: Eseguire i test**

```bash
docker exec -it php-forestas vendor/bin/pest tests/Unit/Http/Resources/EcTrackResourceDemPriorityTest.php -v
```

Atteso: tutti i test PASS.

- [ ] **Step 4: Eseguire PHPStan**

```bash
docker exec -it php-forestas vendor/bin/phpstan analyse src/Http/Resources/EcTrackResource.php
```

Atteso: no errors.

- [ ] **Step 5: Verifica manuale**

Aprire `http://127.0.0.1:8000/nova/resources/ec-tracks/761`, fare una modifica (o forzare il job `UpdateEcTrackAwsJob`), poi controllare `http://localhost:9000/wmfe/forestas/tracks/761.json`:
- `manual_data` assente
- `dem_data` assente
- `osm_data` assente
- `ascent`, `distance`, `duration_forward`, `duration_backward` presenti come valori flat

---

## Note

- Nessun commit autonomo: il codice va revisionato dall'utente prima del commit.
- Tutto il codice va in wm-package.
