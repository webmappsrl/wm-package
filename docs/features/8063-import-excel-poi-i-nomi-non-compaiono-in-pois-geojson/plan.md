> Ticket: oc:8063

# Plan — Import Excel POI: i nomi non compaiono in pois.geojson

## Repo coinvolto

`wm-package` — tutto il codice e i test vivono qui. Nessuna modifica al repo principale (maphub).

## Branch

`oc_8063` (già creato in locale su wm-package)

## Commit convention

`fix(oc:8063): <descrizione>`

---

## Step 1 — Fix `EcPoiRowProcessor::apply()`

**File:** `src/Imports/Processors/EcPoiRowProcessor.php`

Aggiungere, **dopo il loop `foreach`** e **prima** di `$model->setAttribute('properties', $properties)` (attualmente riga 197), il blocco di sincronizzazione:

```php
if (method_exists($model, 'getTranslations')) {
    $name = $model->getTranslations('name');
    if ($name !== []) {
        $properties['name'] = $name;
    }
}
```

Il codice risultante alla fine di `apply()` sarà:

```php
        if ($lat !== null && $lng !== null && is_numeric($lat) && is_numeric($lng)) {
            $latF = (float) $lat;
            $lngF = (float) $lng;
            $model->setAttribute('geometry', "POINT Z ({$lngF} {$latF} 0)");
        }

        if (method_exists($model, 'getTranslations')) {
            $name = $model->getTranslations('name');
            if ($name !== []) {
                $properties['name'] = $name;
            }
        }

        $model->setAttribute('properties', $properties);
    }
```

**Logica:** replica `AbstractObserver::saving()` per il path `saveQuietly()`. La guard `method_exists` è coerente con il pattern già usato in `apply()` per `setTranslation`. La guard `!== []` evita di scrivere `properties['name'] = []` quando nessuna traduzione è stata fornita.

---

## Step 2 — Aggiornare `EcPoiRowProcessorTest`

**File:** `tests/Unit/Imports/Processors/EcPoiRowProcessorTest.php`

### 2a — Aggiornare il test esistente `apply_builds_point_geometry_from_lat_lng_and_writes_properties`

1. Aggiungere `getTranslations` all'anonymous model:

```php
public function getTranslations(string $key): array
{
    $map = $this->getAttribute($key) ?? [];
    return is_array($map) ? $map : [];
}
```

2. Aggiungere l'asserzione su `properties['name']` dopo quelle esistenti:

```php
$this->assertSame(['it' => 'Rifugio', 'en' => 'Refuge'], $properties['name']);
```

### 2b — Aggiungere test "solo name_it"

```php
/** @test */
public function apply_writes_only_provided_translations_to_properties_name(): void
{
    $model = new class extends Model
    {
        protected $guarded = [];
        public $timestamps = false;

        public function setTranslation(string $key, string $locale, string $value): void
        {
            $map = $this->getAttribute($key) ?? [];
            $map = is_array($map) ? $map : [];
            $map[$locale] = $value;
            $this->setAttribute($key, $map);
        }

        public function getTranslations(string $key): array
        {
            $map = $this->getAttribute($key) ?? [];
            return is_array($map) ? $map : [];
        }
    };

    $data = [
        'name_it' => 'Rifugio',
        'poi_type' => 'rifugio',
        'lat' => '45.5',
        'lng' => '10.25',
    ];

    (new EcPoiRowProcessor)->apply($model, $data);

    $properties = $model->getAttribute('properties');
    $this->assertSame(['it' => 'Rifugio'], $properties['name']);
    $this->assertArrayNotHasKey('en', $properties['name']);
}
```

### 2c — Aggiungere test "modello senza getTranslations"

```php
/** @test */
public function apply_does_not_write_properties_name_when_model_has_no_get_translations(): void
{
    $model = new class extends Model
    {
        protected $guarded = [];
        public $timestamps = false;

        public function setTranslation(string $key, string $locale, string $value): void
        {
            $map = $this->getAttribute($key) ?? [];
            $map = is_array($map) ? $map : [];
            $map[$locale] = $value;
            $this->setAttribute($key, $map);
        }
        // getTranslations NON implementato: verifica che il guard method_exists funzioni
    };

    $data = [
        'name_it' => 'Rifugio',
        'poi_type' => 'rifugio',
        'lat' => '45.5',
        'lng' => '10.25',
    ];

    (new EcPoiRowProcessor)->apply($model, $data);

    $properties = $model->getAttribute('properties');
    $this->assertArrayNotHasKey('name', $properties);
}
```

---

## Step 3 — Eseguire i test

```bash
# Dalla root di wm-package (nel container o con PHP 8.4 locale)
vendor/bin/pest tests/Unit/Imports/Processors/EcPoiRowProcessorTest.php
```

Tutti e 4 i test devono passare (il pre-esistente + i 3 aggiornati/nuovi).

---

## Step 4 — PHPStan

```bash
# Nel container Docker
docker exec -it php-${APP_NAME} bash -c "cd /var/www/html/wm-package && vendor/bin/phpstan analyse src/Imports/Processors/EcPoiRowProcessor.php --no-progress"
```

Se PHPStan segnala `Call to an undefined method` sul blocco `getTranslations`, aggiungere `@phpstan-ignore-next-line` — lo stesso approccio usato altrove nel codebase per il duck-typing.

---

## Step 5 — Commit

```
fix(oc:8063): sync properties['name'] from getTranslations in EcPoiRowProcessor::apply()
```

File da includere:
- `src/Imports/Processors/EcPoiRowProcessor.php`
- `tests/Unit/Imports/Processors/EcPoiRowProcessorTest.php`

> ⚠️ Nessun commit automatico. L'esecuzione del commit richiede approvazione esplicita del developer dopo revisione del diff.
