> Ticket: oc:8043 — ROUND 1 COMPLETATO, ROUND 2 (fix review) IN CORSO

# Plan — Import: associazione EcPoi ai Layer via taxonomy

## Task 1 — Config: aggiungere relazioni taxonomy al mapping `layer` ✅

**File:** `wm-package/config/wm-geohub-import.php`

Nella sezione `import_mapping.layer.relations`, aggiunte:
- `taxonomy_theme` (pivot `taxonomy_themeables`, key `taxonomy_theme_id`)
- `taxonomy_poi_types` (pivot `taxonomy_poi_typeables`, key `taxonomy_poi_type_id`)
- `taxonomy_where` (pivot `taxonomy_whereables`, key `taxonomy_where_id`)

---

## Task 2 — Service: riscrivere `associateLayersWithEcPoi()` ✅

**File:** `wm-package/src/Services/Import/GeohubImportService.php`

Il metodo cicla su tutti e tre i meccanismi (`taxonomy_theme`, `taxonomy_where`,
`taxonomy_poi_types`). Per ognuno:
1. Trova i taxonomy ID del layer in GeoHub
2. Trova i geohub_poi_id degli EcPoi con quegli stessi taxonomy ID
3. Merge nella collection totale

Dopo il ciclo, deduplica e per ogni EcPoi locale trovato: `attach()` con check
`alreadyExists` (idempotente).

---

## Task 3 — Job: chiamare `associateLayersWithEcPoi()` in `ImportLayerJob` ✅

**File:** `wm-package/src/Jobs/Import/ImportLayerJob.php`

Aggiunta chiamata in `processDependencies()` dopo `associateLayersWithEcTrack()`.

---

## Task 4 — Test: 7 casi Feature ✅

**File:** `wm-package/tests/Feature/GeohubImportServiceAssociateLayerPoiTest.php`

- `taxonomy_poi_type` → attach
- nessuna taxonomy → skip
- EcPoi non importato → skip
- re-import → no duplicati
- `taxonomy_theme` → attach (caso primario app 63 / app 44)
- `taxonomy_where` → attach
- POI in più meccanismi → attach una sola volta

---

## Task 5 — Verifica su dati reali ✅

- App 63 (Paneveggio): layer 431=48, 432=11, 433=4 EcPois ✅
- App 44 (Metallifere Outdoor): tutti i 7 layer con 101-109 EcPois ✅

---

# Round 2 — Fix review Alessandro Peci (01-07-2026)

## Task 6 — Cleanup `associateLayersWithEcPoi()` ✅

**File:** `wm-package/src/Services/Import/GeohubImportService.php`

- Sostituire `->where($morphableTypeCol, 'like', '%EcPoi%')` con match esatto `->where($morphableTypeCol, 'App\\Models\\EcPoi')` (stesso pattern hardcoded di `associateLayersWithEcTrack()` riga 868)
- Aggiungere `->where('layerable_type', EcPoi::class)` al check `alreadyExists`
- Estrarre un metodo privato comune (es. `attachEcModelToLayer(Model $layer, string $relationMethod, Model $ecModel, string $morphableTypeClass): bool`) che fa il check `alreadyExists` + `attach()`, riutilizzato da entrambi `associateLayersWithEcTrack()` e `associateLayersWithEcPoi()`

## Task 7 — `Layer::isAutoPoiMode()` / `setPoiMode()` ✅

**File:** `wm-package/src/Models/Layer.php`

Mirror esatto di `isAutoTrackMode()`/`setTrackMode()` (righe 153-164), usando `configuration['poi_mode']` invece di `track_mode`.

## Task 8 — `LayerService::assignPoisByTaxonomy()` ✅

**File:** `wm-package/src/Services/Models/LayerService.php`

Mirror di `assignTracksByTaxonomy()` (righe 300+): stessa logica (join `taxonomy_activityables` + `ST_Intersects` su `taxonomy_wheres`), applicata a `EcPoi` invece di `EcTrack`. `EcPoi` ha già le relazioni `taxonomyActivities()`/`taxonomyWheres()` via trait (`TaxonomyAbleModel`, `TaxonomyWhereAbleModel`), nessuna migration necessaria. Guardia iniziale: `if (! $layer->isAutoPoiMode()) return;`.

## Task 9 — `LayerFeatureController` relation-aware ✅

**File:** `wm-package/src/Nova/Fields/LayerFeatures/src/Http/Controllers/LayerFeatureController.php`

- `getFeatures()`: `$isAuto = $relationName === 'ecTracks' ? $layer->isAutoTrackMode() : $layer->isAutoPoiMode();`
- Estendere il fallback di ricalcolo auto-se-pivot-vuota (righe ~116-145, oggi `$relationName === 'ecTracks'`) per chiamare `assignPoisByTaxonomy()` quando `$relationName === 'ecPois'`
- `sync()`: `$isAutoRequest = ! empty($validatedData['auto']) && in_array($relationName, ['ecTracks', 'ecPois']);`, dispatchare al metodo giusto (`assignTracksByTaxonomy` o `assignPoisByTaxonomy`) in base a `$relationName`

**Attenzione:** oggi il branch "auto" per `ecPois` cade nell'`else` e fa `$layer->ecPois()->sync([])` — bug attivo da correggere, non solo gap funzionale.

## Task 10 — `LayerFeatures` field: meta mode relation-aware ✅

**File:** `wm-package/src/Nova/Fields/LayerFeatures/src/LayerFeatures.php`

- In `loadEcFeatures()`, sostituire il meta `'trackMode' => $layer->isAutoTrackMode() ? 'auto' : 'manual'` (riga 82, oggi sempre basato su track anche nel pannello Poi) con un meta generico `'mode' => ($relationName === 'ecTracks' ? $layer->isAutoTrackMode() : $layer->isAutoPoiMode()) ? 'auto' : 'manual'`

**File:** `wm-package/src/Nova/Fields/LayerFeatures/resources/js/components/LayerFeature.vue`

- Aggiornare le 2 letture di `props.field?.trackMode` (righe ~139, ~197) in `props.field?.mode`
- Verificare se serve `npm run prod`/ricompilazione del dist per il field (vedi decisione architetturale oc:8093 in CLAUDE.md — verificare sorgente pulita prima della build)

## Task 11 — Observer: reattività live `poi_mode: auto` ✅

**File:** `wm-package/src/Observers/TaxonomyActivityablesObserver.php`

Nel branch `elseif ($isTrackType || str_contains($relatedTypeClass, '\EcPoi'))`, aggiungere un blocco analogo a quello esistente per `$relatedModel instanceof EcTrack` (righe 76-99), ma per `instanceof EcPoi`, filtrando `$layer->isAutoPoiMode()` e dispatchando `SyncAutoLayerAfterPoiTaxonomyChangeJob` (Task 12).

**File:** `wm-package/src/Observers/TaxonomyWhereablesObserver.php`

In `syncLayerIfNeeded()` (riga 37), aggiungere `$this->layerService->assignPoisByTaxonomy($layer);` accanto alla chiamata esistente per i track.

**File:** `wm-package/src/Observers/LayerObserver.php`

In `saved()` (riga 44-46), aggiungere `assignPoisByTaxonomy()` accanto ad `assignTracksByTaxonomy()` quando `hasTaxonomyActivitiesChanged()`.

**File:** `wm-package/src/Observers/EcPoiObserver.php`

Aggiungere un metodo analogo a `EcTrackObserver::syncAutoLayersAfterNovaTrackEdit()` (fallback per edit da Nova che non emettono eventi pivot), filtrando `isAutoPoiMode()` e dispatchando il nuovo job.

## Task 12 — Nuovo Job `SyncAutoLayerAfterPoiTaxonomyChangeJob` ✅

**File:** `wm-package/src/Jobs/Layer/SyncAutoLayerAfterPoiTaxonomyChangeJob.php` (nuovo)

Mirror di `SyncAutoLayerAfterTrackTaxonomyChangeJob`: stesso `uniqueId()`, `tries`, `timeout`, chiama `assignPoisByTaxonomy()` + `updateLayersPropertyOnAllLayeredFeaturesWithJobs()` + `regeneratePbfsForLayer()`, reindicizza l'EcPoi coinvolto invece dell'EcTrack.

## Task 13 — Test Feature ✅

**File:** `wm-package/tests/Feature/GeohubImportServiceAssociateLayerPoiTest.php` (estendere)

- Match esatto morphable_type non rompe i 7 test esistenti
- Idempotenza con `layerable_type` filtrato

**Nuovo file:** `wm-package/tests/Feature/LayerAssignPoisByTaxonomyTest.php`

- `assignPoisByTaxonomy()`: assegna POI con stessa `taxonomyActivity` del layer ✅
- `isAutoPoiMode()` default `auto` se non configurato ✅
- `assignPoisByTaxonomy()` è no-op quando il layer è in `poi_mode: manual` ✅
- `LayerFeatureController::sync()` con `auto: true` su `ecPois` non svuota la pivot ma ricalcola ✅
- `LayerFeatureController::sync()` manuale su `ecPois` sincronizza esattamente gli ID passati ✅
- Non verificato via test automatico (richiede `Wm\WmPackage\Tests\TestCase`, non eseguibile in questo ambiente): reattività degli observer su modifica `taxonomy_activityables`/`taxonomy_wheres` su un EcPoi, regressione sui test `track_mode` esistenti (`LayerServiceAssignTracksByTaxonomyTest`, `LayerFeatureControllerGetFeaturesTest`) — verificati per lettura di codice, nessuna modifica ai branch `ecTracks` esistenti

## Task 14 — Verifica manuale ⏳ (da fare su ambiente con Nova UI, non eseguibile headless)

- Toggle "Selezione Automatica"/"Selezione Manuale" nel pannello Ec Pois di Nova su un layer reale (app 63 o 44)
- Conferma che il toggle non svuota più la pivot POI
- Conferma ricalcolo corretto in base a taxonomy locale
