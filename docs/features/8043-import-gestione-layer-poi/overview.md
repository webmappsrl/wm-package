> Ticket: oc:8043

# Import: associazione EcPoi ai Layer via taxonomy (+ fix review: cleanup e parità poi_mode)

## Cosa cambia (round 2 — fix review Alessandro Peci, 01-07-2026)

Alessandro Peci ha revisionato l'implementazione originale (round 1, sotto) e segnalato 3 cleanup
tecnici + 1 gap funzionale non dichiarato out of scope. Questo round aggiunge:

1. **Cleanup** `associateLayersWithEcPoi()` (`GeohubImportService.php`):
  - match esatto sul `morphable_type` (`App\Models\EcPoi`) invece di `LIKE '%EcPoi%'`, come già fa `associateLayersWithEcTrack()`
  - check idempotenza (`alreadyExists`) filtrato anche su `layerable_type`, non solo `layerable_id`
  - logica di attach/skip fattorizzata in un metodo privato comune, condiviso tra `associateLayersWithEcTrack()` e `associateLayersWithEcPoi()`
2. **Parità** `poi_mode` **con** `track_mode` — la richiesta cliente originale (`customer_request` del ticket) chiedeva un modo per associare manualmente i POI a un Layer da Nova. Indagando, questo **esiste già** come campo `LayerFeatures` (già usato per `ecTracks`, introdotto a settembre 2025, non parte di questo ticket) con toggle "Selezione Manuale/Automatica" — ma la logica di auto/manuale è **hardcoded solo per** `ecTracks` in più punti:
  - `LayerFeatures.php`: il meta `trackMode` (usato dal frontend per decidere se un pannello è "auto" o "manuale") riflette sempre `Layer::isAutoTrackMode()`, **anche nel pannello Ec Pois** — il toggle mostrato lì oggi non ha alcun significato reale per i POI.
  - `LayerFeatureController::sync()`: il branch "automatico" è condizionato a `$relationName === 'ecTracks'`; per `ecPois` cade sempre nell'`else` e fa `$layer->ecPois()->sync([])` — **bug attivo**: cliccare "automatico" nel pannello POI cancella tutte le associazioni POI esistenti.
  - `LayerFeatureController::getFeatures()`: stesso problema, `$isAuto = $layer->isAutoTrackMode()` non relation-aware.
  - I 4 observer che ricalcolano live i layer in `track_mode: auto` (`TaxonomyActivityablesObserver`, `TaxonomyWhereablesObserver`, `LayerObserver`, `EcTrackObserver`) non hanno un equivalente per `ecPois`.
   Si implementa quindi `poi_mode` a piena parità con `track_mode`: nuovo flag `configuration['poi_mode']` sul Layer, `LayerService::assignPoisByTaxonomy()` (mirror di `assignTracksByTaxonomy()`, usa le relazioni locali `taxonomyActivities`/`taxonomyWheres` già presenti su `EcPoi` via trait), controller e field resi relation-aware, observer e job di resync estesi per `EcPoi`.



## Cosa cambia (round 1 — implementazione originale)

Durante l'import di un Layer da GeoHub, viene popolata la relazione `ecPois()` del
layer locale. Il metodo `associateLayersWithEcPoi()` in `GeohubImportService` traversa
**tutti e tre i meccanismi di taxonomy GeoHub** — `taxonomy_themeables`,
`taxonomy_whereables` e `taxonomy_poi_typeables` — per trovare i POI che condividono
almeno una taxonomy con il layer. I geohub_poi_id raccolti vengono deduplicati, quindi
i corrispondenti EcPoi locali vengono collegati via `ecPois()->attach()`.

Il meccanismo primario per app 63 (Paneveggio) e app 44 (Metallifere Outdoor) è
`taxonomy_theme`.

## Perché (round 1)

I layer di GeoHub non hanno una pivot diretta Layer→EcPoi. La relazione è indiretta:
Layer e EcPoi condividono le stesse taxonomy (theme, where, poi_type). La vecchia
implementazione controllava solo `taxonomy_poi_typeables`, che restituiva 0 risultati
per app 63 e app 44. Il risultato era il pannello "Ec Pois" in Nova sempre vuoto
dopo l'import.

## Perché (round 2)

La review non ha trovato bloccanti di correttezza (7/7 test verificati con esecuzione reale
su dati di produzione), ma ha segnalato che il `customer_request` originale del ticket
("in Nova non è presente alcun modo per associare un POI a un layer... né dalla scheda del
layer") non era esplicitamente riconciliato con l'overview: quest'ultima limitava lo scope
al solo import automatico e relegava `poi_mode` a "out of scope" senza che questa scelta
fosse comunicata/validata. Indagando si è scoperto che un meccanismo di associazione manuale
esiste già (`LayerFeatures`), ma è incompleto e in parte rotto per i POI — quindi la scelta
più corretta non è "dichiarare out of scope" ma completare la parità già impostata dal codice
esistente per i track.

## Requisiti (round 2)

- [x] `associateLayersWithEcPoi()` usa match esatto sul `morphable_type` (non `LIKE`)
- [x] Check idempotenza filtra anche su `layerable_type`
- [x] Logica di attach/skip fattorizzata in un metodo comune tra Track e Poi
- [x] `Layer::isAutoPoiMode()` / `setPoiMode()` — nuovo flag `configuration['poi_mode']`
- [x] `LayerService::assignPoisByTaxonomy()` — mirror di `assignTracksByTaxonomy()` per EcPoi
- [x] `LayerFeatureController` (`getFeatures()`, `sync()`) relation-aware su auto/manuale per `ecPois`
- [x] Toggle "Selezione Manuale/Automatica" nel pannello Ec Pois di Nova funzionante e non distruttivo (verificato via test sul controller; verifica visiva in Nova non eseguita, vedi notes.md)
- [x] Observer (`TaxonomyActivityablesObserver`, `TaxonomyWhereablesObserver`, `LayerObserver`, `EcPoiObserver`) ricalcolano i layer con `poi_mode: auto` alla modifica di tassonomie locali sui POI, a parità con i track (verificato per lettura di codice, non con test automatico — vedi notes.md)
- [x] Nessuna regressione sul comportamento `track_mode` esistente (suite Feature eseguibile in locale invariata; 2 file che usano `Wm\WmPackage\Tests\TestCase` non eseguibili in questo ambiente, verificati per lettura di codice)



## Implementazione

- `associateLayersWithEcPoi(Model $model)` — cicla su `['taxonomy_theme', 'taxonomy_where', 'taxonomy_poi_types']`,
per ognuno interroga GeoHub per trovare i taxonomy ID del layer, poi i geohub_poi_id
degli EcPoi con quelle stesse taxonomy. Raccoglie tutti gli ID, li deduplica, e per
ogni EcPoi locale trovato fa `attach()` con check `alreadyExists` (idempotente).
- `ImportLayerJob::processDependencies()` — chiama `associateLayersWithEcPoi($model)`
dopo `associateLayersWithEcTrack()`.
- `config/wm-geohub-import.php` — aggiunta config `taxonomy_where` sotto `layer.relations`.



## Verifiche


| App | Layer (geohub_id) | EcPois attesi (da GeoHub) | Risultato |
| --- | ----------------- | ------------------------- | --------- |
| 63  | 431               | 48                        | ✅ 48      |
| 63  | 432               | 11                        | ✅ 11      |
| 63  | 433               | 4                         | ✅ 4       |
| 44  | 370               | ~106                      | ✅ 106     |
| 44  | 367               | ~101                      | ✅ 101     |
| 44  | 194               | ~107                      | ✅ 107     |
| 44  | tutti gli altri   | 100+                      | ✅         |




## Test (7 casi)

- `taxonomy_poi_type` → attach ✅
- nessuna taxonomy → skip senza eccezione ✅
- EcPoi non importato localmente → skip senza eccezione ✅
- re-import → nessun duplicato ✅
- `taxonomy_theme` → attach ✅
- `taxonomy_where` → attach ✅
- POI trovato via più meccanismi → attach una sola volta ✅



## Rischi (round 1)

- `attach()` è additivo: non rimuove associazioni manuali né stale (stesso trade-off di `associateLayersWithEcTrack()`).
- `LayerableObserver::created()` dispatcha un job di ricalcolo geometria per ogni `attach()`. Con molti POI per layer vengono accodati N job — comportamento atteso e già in produzione per i track.



## Rischi (round 2)

- **Regressione su** `track_mode`: `TaxonomyActivityablesObserver`, `TaxonomyWhereablesObserver`, `LayerObserver` e `LayerFeatureController` sono file condivisi tra la logica Track (già approvata in review) e la nuova logica Poi. Ogni modifica va fatta per estensione (nuovo branch/chiamata condizionale), mai riscrivendo i branch esistenti — mitigato mantenendo i test Feature esistenti sui track verdi dopo le modifiche.
- **Debounce e code**: il resync via `SyncAutoLayerAfterPoiTaxonomyChangeJob` (mirror del job Track) introduce nuovi job in coda; con molte modifiche di tassonomia POI ravvicinate si accumulano job — stesso trade-off già accettato per i track (debounce 5s locale / 300s produzione).
- `sync()` **è distruttivo in auto mode**: `assignPoisByTaxonomy()` fa un `sync()` completo (non `attach()` additivo come l'import GeoHub) — un layer in `poi_mode: auto` perde ogni associazione manuale precedente al primo ricalcolo. Comportamento intenzionale, identico a `assignTracksByTaxonomy()`, ma da comunicare esplicitamente in nota di rilascio.
- **Nessun coordinamento con l'import GeoHub**: `associateLayersWithEcPoi()` (round 1, usa `attach()` additivo) e `assignPoisByTaxonomy()` (round 2, usa `sync()` a rimpiazzo) possono entrambe modificare la stessa pivot `layerables` per lo stesso layer, con semantiche diverse (additiva vs sostitutiva). Un layer in `poi_mode: auto` che viene ri-importato da GeoHub vede prima l'`attach()` dell'import e poi, al primo evento di ricalcolo taxonomy, il `sync()` automatico può rimuovere POI aggiunti dall'import se non condividono le taxonomy locali attese da `assignPoisByTaxonomy()`. Accettato per coerenza con il comportamento già esistente su `ecTracks` (stesso doppio meccanismo, stesso trade-off).



## Out of scope

- Race condition nel batch parallelo: gestita a valle (i layer vengono importati per ultimi nel `MODEL_IMPORT_ORDER`).
- Rimozione di associazioni stale (POI rimossi da GeoHub) nell'import automatico.
- Associazione manuale da scheda EcPoi o da scheda App (richiesta solo dalla scheda Layer, via `LayerFeatures` esistente).
- Reattività live per `taxonomy_poi_types` (analoga a `TaxonomyPoiTypeablesObserver`): `assignPoisByTaxonomy()` replica solo i meccanismi già usati da `assignTracksByTaxonomy()` (`taxonomyActivities`, `taxonomyWheres`), non `taxonomy_poi_types` — nessun caso d'uso noto lo richiede oggi per i track equivalenti.
- Migrazione dati per layer già in produzione con POI associati manualmente prima di questa fix: restano `manual` di default finché non attivati in `auto`.



## Moduli toccati

Tutti in `wm-package`.

**Round 1 (import):**

- `src/Services/Import/GeohubImportService.php` — `associateLayersWithEcPoi()` riscritta
- `src/Jobs/Import/ImportLayerJob.php` — chiamata a `associateLayersWithEcPoi()`
- `config/wm-geohub-import.php` — config `taxonomy_where` in `layer.relations`
- `tests/Feature/GeohubImportServiceAssociateLayerPoiTest.php` — 7 test Feature

**Round 2 (fix review + poi_mode):**

- `src/Services/Import/GeohubImportService.php` — cleanup `associateLayersWithEcPoi()` (match esatto, filtro `layerable_type`, metodo comune)
- `src/Models/Layer.php` — `isAutoPoiMode()`, `setPoiMode()`
- `src/Services/Models/LayerService.php` — `assignPoisByTaxonomy()`
- `src/Nova/Fields/LayerFeatures/src/LayerFeatures.php` — meta mode relation-aware
- `src/Nova/Fields/LayerFeatures/src/Http/Controllers/LayerFeatureController.php` — `getFeatures()`/`sync()` relation-aware
- `src/Nova/Fields/LayerFeatures/resources/js/components/LayerFeature.vue` — meta key `trackMode` → `mode`
- `src/Observers/TaxonomyActivityablesObserver.php` — branch resync per `EcPoi`
- `src/Observers/TaxonomyWhereablesObserver.php` — chiamata `assignPoisByTaxonomy()`
- `src/Observers/LayerObserver.php` — chiamata `assignPoisByTaxonomy()` in `saved()`
- `src/Observers/EcPoiObserver.php` — fallback resync su edit Nova (mirror `EcTrackObserver`)
- `src/Jobs/Layer/SyncAutoLayerAfterPoiTaxonomyChangeJob.php` — nuovo, mirror del job Track
- Test Feature nuovi per tutti i punti sopra

