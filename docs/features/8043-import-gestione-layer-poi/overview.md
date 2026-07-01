> Ticket: oc:8043

# Import: associazione EcPoi ai Layer via taxonomy

## Cosa cambia

Durante l'import di un Layer da GeoHub, viene popolata la relazione `ecPois()` del
layer locale. Il metodo `associateLayersWithEcPoi()` in `GeohubImportService` traversa
**tutti e tre i meccanismi di taxonomy GeoHub** — `taxonomy_themeables`,
`taxonomy_whereables` e `taxonomy_poi_typeables` — per trovare i POI che condividono
almeno una taxonomy con il layer. I geohub_poi_id raccolti vengono deduplicati, quindi
i corrispondenti EcPoi locali vengono collegati via `ecPois()->attach()`.

Il meccanismo primario per app 63 (Paneveggio) e app 44 (Metallifere Outdoor) è
`taxonomy_theme`.

## Perché

I layer di GeoHub non hanno una pivot diretta Layer→EcPoi. La relazione è indiretta:
Layer e EcPoi condividono le stesse taxonomy (theme, where, poi_type). La vecchia
implementazione controllava solo `taxonomy_poi_typeables`, che restituiva 0 risultati
per app 63 e app 44. Il risultato era il pannello "Ec Pois" in Nova sempre vuoto
dopo l'import.

## Implementazione

- **`associateLayersWithEcPoi(Model $model)`** — cicla su `['taxonomy_theme', 'taxonomy_where', 'taxonomy_poi_types']`,
  per ognuno interroga GeoHub per trovare i taxonomy ID del layer, poi i geohub_poi_id
  degli EcPoi con quelle stesse taxonomy. Raccoglie tutti gli ID, li deduplica, e per
  ogni EcPoi locale trovato fa `attach()` con check `alreadyExists` (idempotente).
- **`ImportLayerJob::processDependencies()`** — chiama `associateLayersWithEcPoi($model)`
  dopo `associateLayersWithEcTrack()`.
- **`config/wm-geohub-import.php`** — aggiunta config `taxonomy_where` sotto `layer.relations`.

## Verifiche

| App | Layer (geohub_id) | EcPois attesi (da GeoHub) | Risultato |
|-----|-------------------|---------------------------|-----------|
| 63  | 431               | 48                        | ✅ 48     |
| 63  | 432               | 11                        | ✅ 11     |
| 63  | 433               | 4                         | ✅ 4      |
| 44  | 370               | ~106                      | ✅ 106    |
| 44  | 367               | ~101                      | ✅ 101    |
| 44  | 194               | ~107                      | ✅ 107    |
| 44  | tutti gli altri   | 100+                      | ✅        |

## Test (7 casi)

- `taxonomy_poi_type` → attach ✅
- nessuna taxonomy → skip senza eccezione ✅
- EcPoi non importato localmente → skip senza eccezione ✅
- re-import → nessun duplicato ✅
- `taxonomy_theme` → attach ✅
- `taxonomy_where` → attach ✅
- POI trovato via più meccanismi → attach una sola volta ✅

## Rischi

- `attach()` è additivo: non rimuove associazioni manuali né stale (stesso trade-off di `associateLayersWithEcTrack()`).
- `LayerableObserver::created()` dispatcha un job di ricalcolo geometria per ogni `attach()`. Con molti POI per layer vengono accodati N job — comportamento atteso e già in produzione per i track.

## Out of scope

- Race condition nel batch parallelo: gestita a valle (i layer vengono importati per ultimi nel `MODEL_IMPORT_ORDER`).
- `poi_mode` (auto/manual) nel `configuration` del layer.
- Rimozione di associazioni stale (POI rimossi da GeoHub).

## Moduli toccati

Tutti in `wm-package`:

- `src/Services/Import/GeohubImportService.php` — `associateLayersWithEcPoi()` riscritta
- `src/Jobs/Import/ImportLayerJob.php` — chiamata a `associateLayersWithEcPoi()`
- `config/wm-geohub-import.php` — config `taxonomy_where` in `layer.relations`
- `tests/Feature/GeohubImportServiceAssociateLayerPoiTest.php` — 7 test Feature
