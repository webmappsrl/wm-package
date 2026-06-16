> Ticket: oc:7756

# Plan — Campo bounding box

## Repo coinvolto
`wm-package` — tutte le modifiche sono nel package.

---

## Step 1 — Fix SQL injection in `GeometryComputationService`

**File:** `src/Services/GeometryComputationService.php`

`sanitizeBbox` non fa cast a float — i valori vengono interpolati raw in `ST_MakeEnvelope`. Aggiungere `floatval` dopo il trim per rendere sicura l'interpolazione.

```php
// prima
private function sanitizeBbox(?string $bbox): ?array
{
    $bbox = str_replace(['[', ']', '"'], '', $bbox);
    $bbox = explode(',', $bbox);

    if (count($bbox) !== 4) {
        return null;
    }

    $bbox = array_map('trim', $bbox);

    return $bbox;
}

// dopo
private function sanitizeBbox(?string $bbox): ?array
{
    $bbox = str_replace(['[', ']', '"'], '', $bbox);
    $bbox = explode(',', $bbox);

    if (count($bbox) !== 4) {
        return null;
    }

    $bbox = array_map('floatval', array_map('trim', $bbox));

    return $bbox;
}
```

**Commit:** `fix(oc:7756): cast bbox values to float in sanitizeBbox to prevent SQL injection`

---

## Step 2 — Validazione WGS84 sul campo `map_bbox` in `Nova/App.php`

**File:** `src/Nova/App.php`

Estendere la closure di validazione esistente (riga ~1079) con controlli su:
- esattamente 4 elementi numerici
- longitudine ∈ [-180, 180]
- latitudine ∈ [-90, 90]
- minLon < maxLon e minLat < maxLat

```php
->rules([
    function ($attribute, $value, $fail) {
        if ($value === null || $value === '') {
            return;
        }
        $decoded = json_decode($value, true);
        if (! is_array($decoded) || count($decoded) !== 4) {
            $fail(__('The :attribute must be a JSON array of 4 numbers. Example: [9.9456,43.9116,11.3524,45.0186]', ['attribute' => $attribute]));
            return;
        }
        [$minLon, $minLat, $maxLon, $maxLat] = array_map('floatval', $decoded);
        if ($minLon < -180 || $maxLon > 180 || $minLat < -90 || $maxLat > 90) {
            $fail(__('The :attribute has coordinates out of WGS84 range (lon: -180/180, lat: -90/90).', ['attribute' => $attribute]));
            return;
        }
        if ($minLon >= $maxLon || $minLat >= $maxLat) {
            $fail(__('The :attribute min values must be less than max values.', ['attribute' => $attribute]));
        }
    },
])
```

**Commit:** `fix(oc:7756): add WGS84 range and order validation to map_bbox field`

---

## Step 3 — Aggiorna campo testo `map_bbox` con `copyable()` e nuovo help

**File:** `src/Nova/App.php`

Aggiungere `->copyable()` e aggiornare l'help text al campo `Text` esistente (riga ~1076).

```php
// prima
Text::make(__('Bounding BOX'), 'map_bbox')
    ->nullable()
    ->hideFromIndex()
    ->rules([...])
    ->help(__('Bounding the map view. Example: [9.9456,43.9116,11.3524,45.0186]')),

// dopo
Text::make(__('Bounding BOX'), 'map_bbox')
    ->nullable()
    ->hideFromIndex()
    ->copyable()
    ->rules([...])
    ->help(__('Automatically calculated from the tracks associated with the app. To visualize the area: <a href="https://boundingbox.klokantech.com/" target="_blank">boundingbox.klokantech.com</a>')),
```

**Commit:** `feat(oc:7756): add copyable() and updated help text to map_bbox field`

---

## Step 4 — Aggiunge preview mappa `FeatureCollectionMap` in `map_tab()`

**File:** `src/Nova/App.php`

Aggiungere import e campo preview subito dopo il campo testo, nella sezione `// --- BBOX ---`.

**Import da aggiungere:**
```php
use Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src\FeatureCollectionMap;
use Wm\WmPackage\Services\GeometryComputationService;
```

**Campo da aggiungere dopo il Text::make('Bounding BOX'):**
```php
FeatureCollectionMap::make(__('Bounding Box Map'), 'map_bbox')
    ->resolveUsing(function ($value, $resource) {
        if (empty($value)) {
            return null;
        }
        try {
            return app(GeometryComputationService::class)->bboxToPolygon($value);
        } catch (\Throwable $e) {
            return null;
        }
    })
    ->onlyOnDetail()
    ->hideFromIndex(),
```

> **Nota:** `resolveUsing` restituisce la WKT geometry (`POLYGON(...)`) che `FeatureCollectionMap::resolve()` passa a `geometryToGeojson()` per il rendering. Il `try/catch` garantisce che un bbox malformato non faccia crashare la pagina detail.

**Commit:** `feat(oc:7756): add FeatureCollectionMap bbox preview in map tab`

---

## Checklist pre-PR

- [ ] Step 1: `sanitizeBbox` usa `floatval`
- [ ] Step 2: validazione WGS84 estesa nella closure
- [ ] Step 3: `->copyable()` + help text aggiornato
- [ ] Step 4: campo preview `FeatureCollectionMap` in `map_tab()` dopo il campo testo
- [ ] Test manuale: App con `map_bbox` valido → preview mappa visibile nel detail
- [ ] Test manuale: click "Copy" sul campo testo → valore copiato in clipboard
- [ ] Test manuale: inserire bbox con coordinate fuori WGS84 → messaggio di errore
- [ ] Test manuale: `map_bbox` null → pagina detail non crasha
