> Ticket: oc:8160

# Piano — Layer Nova: sposta Geometry fuori dal panel Ec Tracks e mostra anche EcPoi sulla mappa

## Step 1 — Crea branch in wm-package

```bash
cd wm-package
git checkout -b feature/oc-8160-layer-nova-map-panel-ec-poi
```

---

## Step 2 — `src/Nova/Layer.php`: riposiziona FeatureCollectionMap in panel dedicato

**File:** `wm-package/src/Nova/Layer.php`

Sostituisci il blocco righe 96–106:

```php
// PRIMA
Panel::make('Ec Tracks', [
    FeatureCollectionMap::make(__('Geometry'), 'geometry')->onlyOnDetail(),
    LayerFeatures::make(__('tracks'), $this->resource, config('wm-package.ec_track_model', 'Wm\WmPackage\Models\EcTrack'))
        ->hideWhenCreating()
        ->withMeta(['model_class' => config('wm-package.ec_track_model', 'Wm\WmPackage\Models\EcTrack')]),
]),
Panel::make('Ec Pois', [
    LayerFeatures::make(__('pois'), $this->resource, config('wm-package.ec_poi_model', 'Wm\WmPackage\Models\EcPoi'))
        ->hideWhenCreating()
        ->withMeta(['model_class' => config('wm-package.ec_poi_model', 'Wm\WmPackage\Models\EcPoi')]),
]),
```

Con:

```php
// DOPO
Panel::make(__('Map'), [
    FeatureCollectionMap::make(__('Geometry'), 'geometry')->onlyOnDetail(),
]),
Panel::make('Ec Tracks', [
    LayerFeatures::make(__('tracks'), $this->resource, config('wm-package.ec_track_model', 'Wm\WmPackage\Models\EcTrack'))
        ->hideWhenCreating()
        ->withMeta(['model_class' => config('wm-package.ec_track_model', 'Wm\WmPackage\Models\EcTrack')]),
]),
Panel::make('Ec Pois', [
    LayerFeatures::make(__('pois'), $this->resource, config('wm-package.ec_poi_model', 'Wm\WmPackage\Models\EcPoi'))
        ->hideWhenCreating()
        ->withMeta(['model_class' => config('wm-package.ec_poi_model', 'Wm\WmPackage\Models\EcPoi')]),
]),
```

---

## Step 3 — `resources/lang/it.json` e `en.json`: aggiungi traduzione "Map"

**File:** `wm-package/resources/lang/it.json`

Aggiungi prima della chiusura `}`:
```json
"Map": "Mappa"
```

**File:** `wm-package/resources/lang/en.json`

Aggiungi prima della chiusura `}`:
```json
"Map": "Map"
```

---

## Step 4 — `src/Models/Layer.php`: aggiungi EcPoi in `getFeatureCollectionMap()`

**File:** `wm-package/src/Models/Layer.php`

Subito dopo il blocco `if (! empty($trackIds)) { ... }` (riga ~412), prima del blocco `$whereIds = $this->taxonomyWheres()...`, aggiungi:

```php
$poiIds = $this->ecPois()->pluck('ec_pois.id')->toArray();

if (! empty($poiIds)) {
    $poiPlaceholders = implode(',', array_fill(0, count($poiIds), '?'));
    $poiSql = "SELECT id, name, ST_AsGeoJSON(geometry) as geometry FROM ec_pois WHERE id IN ({$poiPlaceholders}) AND geometry IS NOT NULL";
    $poiRows = DB::select($poiSql, $poiIds);

    foreach ($poiRows as $ecPoi) {
        $geometry = json_decode($ecPoi->geometry, true);
        $nameData = json_decode($ecPoi->name, true);
        $ecPoiName = $nameData['it'] ?? (is_array($nameData) && ! empty($nameData) ? reset($nameData) : 'Nome non disponibile');

        if ($geometry) {
            $this->addFeaturesForMap([[
                'type' => 'Feature',
                'geometry' => $geometry,
                'properties' => [
                    'tooltip' => $ecPoiName,
                    'link' => url('nova/resources/ec-pois/'.$ecPoi->id),
                ],
            ]]);
        }
    }
}
```

**Nota:** nessun colore esplicito nelle properties EcPoi — il Vue component applica i default (`pointFillColor: rgba(255,0,0,0.8)`, `pointRadius: 6`), identici a quanto visto nella pagina di dettaglio dell'EcPoi stesso.

---

## Step 5 — Test: `tests/Feature/LayerGetFeatureCollectionMapEcPoisTest.php`

**File:** `wm-package/tests/Feature/LayerGetFeatureCollectionMapEcPoisTest.php` (nuovo)

Usa `Wm\WmPackage\Tests\TestCase` e `DatabaseTransactions`. Crea il file con questi 3 casi:

**Caso 1 — EcPoi con geometria → presente nella FeatureCollection**
- Crea `App`, `Layer`, `EcPoi` con geometria Point (tramite factory + `DB::statement` raw per la geometria WKB, come negli altri test del package)
- Inserisci in `layerables` la relazione Layer→EcPoi
- Chiama `$layer->getFeatureCollectionMap()`
- Asserisci: `type === 'FeatureCollection'`, features contiene almeno una feature con `geometry.type === 'Point'`, `properties.link` contiene `ec-pois/{id}`, `properties.tooltip` non è vuoto

**Caso 2 — EcPoi senza geometria → non incluso nella FeatureCollection**
- Crea EcPoi senza geometria (colonna `geometry` a NULL)
- Inserisci in `layerables`
- Chiama `$layer->getFeatureCollectionMap()`
- Asserisci: nessuna feature Point nel risultato

**Caso 3 — Layer senza EcPoi associati → metodo non crasha, restituisce FeatureCollection valida**
- Crea Layer senza nulla in `layerables`
- Chiama `$layer->getFeatureCollectionMap()`
- Asserisci: ritorna array con `type === 'FeatureCollection'` e `features` è array (anche vuoto)

Esegui i test con:
```bash
docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/LayerGetFeatureCollectionMapEcPoisTest.php
```

---

## Step 6 — Verifica manuale in Nova

- Apri la pagina di dettaglio di un Layer in Nova
- Verifica che esista un panel "Mappa" (o "Map") separato con la mappa interattiva
- Verifica che il panel "Ec Tracks" non contenga più la mappa
- Verifica che punti rossi compaiano sulla mappa per gli EcPoi associati al layer
- Clicca un punto → verifica che apra la scheda Nova dell'EcPoi in nuova tab

---

## Commit convention

```
feat(oc:8160): move Geometry to dedicated Map panel and show EcPoi on layer map
```

Unico commit che include tutti i file modificati nei step 2–5.
