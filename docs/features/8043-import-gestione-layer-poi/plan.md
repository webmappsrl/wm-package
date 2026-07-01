> Ticket: oc:8043

# Plan — Import: gestione dei layer con POI non supportata

## Task 1 — Config: aggiungere relazione `taxonomy_poi_types` al mapping `layer`

**File:** `wm-package/config/wm-geohub-import.php`

Nella sezione `import_mapping.layer.relations`, aggiungere:

```php
'taxonomy_poi_types' => [
    'pivot_table' => 'taxonomy_poi_typeables',
    'key' => 'taxonomy_poi_type_id',
    'foreign_key' => 'taxonomy_poi_typeable_id',
    'morphable_type' => ['key' => 'taxonomy_poi_typeable_type', 'value' => 'App\\Models\\Layer'],
],
```

---

## Task 2 — Service: implementare `associateLayersWithEcPoi()` in `GeohubImportService`

**File:** `wm-package/src/Services/Import/GeohubImportService.php`

Aggiungere il metodo pubblico `associateLayersWithEcPoi(Model $model)` seguendo
esattamente il pattern di `associateLayersWithEcTrack()`:

1. Legge la config via `getRelationConfig('layer', 'taxonomy_poi_types')`
2. Interroga `taxonomy_poi_typeables` su GeoHub per trovare i `taxonomy_poi_type_id`
   del layer (WHERE `taxonomy_poi_typeable_id = $model->properties['geohub_id']`
   AND `taxonomy_poi_typeable_type = 'App\Models\Layer'`)
3. Se nessun tipo trovato → `$this->logger->warning(...)` e `return`
4. Per ogni `taxonomy_poi_type_id`, interroga `taxonomy_poi_typeables` per trovare
   gli EcPoi GeoHub (WHERE `taxonomy_poi_type_id = X`
   AND `taxonomy_poi_typeable_type LIKE '%EcPoi%'`)
5. Per ogni EcPoi GeoHub trovato, cerca il corrispondente locale via
   `EcPoi::where('properties->geohub_id', $geohubPoiId)->first()`
6. Se trovato e non già associato → `$model->ecPois()->attach($ecPoi->id, ['created_at' => now(), 'updated_at' => now()])`
7. Log riepilogo: POI trovati in GeoHub / associati / già presenti / non trovati localmente

---

## Task 3 — Job: chiamare `associateLayersWithEcPoi()` in `ImportLayerJob`

**File:** `wm-package/src/Jobs/Import/ImportLayerJob.php`

In `processDependencies()`, aggiungere la chiamata dopo `associateLayersWithEcTrack`:

```php
$this->geohubImportService->associateLayersWithEcPoi($model);
```

---

## Task 4 — Test: verificare la corretta associazione POI→Layer

**File:** `wm-package/tests/Feature/Services/Import/GeohubImportServiceAssociateLayerPoiTest.php`

Casi da coprire:
- Layer con taxonomy_poi_types → i POI locali con quei tipi vengono associati
- Layer senza taxonomy_poi_types → nessun attach, nessuna eccezione
- EcPoi non ancora importato localmente → saltato, log "not found", nessuna eccezione
- Re-import: POI già associato non viene duplicato (check `alreadyExists`)

---

## Commit convention

```
feat(oc:8043): associate ec_pois to layer via taxonomy_poi_type on import
```
