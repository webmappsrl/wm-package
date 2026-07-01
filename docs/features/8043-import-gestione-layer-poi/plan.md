> Ticket: oc:8043 — COMPLETATO

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
