> Ticket: oc:8043

# Import: gestione dei layer con POI non supportata

## Cosa cambia

Durante l'import di un Layer da GeoHub, viene popolata la relazione `ecPois()` del
layer locale. Il metodo `associateLayersWithEcPoi()` in `GeohubImportService` legge
`taxonomy_poi_typeables` in GeoHub per trovare i POI che condividono gli stessi
`taxonomy_poi_type` del layer, poi li sincronizza via `ecPois()->sync()`.
Pattern identico a `associateLayersWithEcTrack()` ma per `taxonomy_poi_type`.

## Perché

I layer POI-only di GeoHub non avevano nessuna associazione POI→Layer creata
dall'import. Il pannello "Ec Pois" in Nova appariva vuoto anche dopo un import
completo. In GeoHub non esiste una pivot diretta `ec_poi_layer`: la relazione
è indiretta tramite `TaxonomyPoiType` (tabella morph `taxonomy_poi_typeables`).

## Requisiti

- [ ] Aggiungere la relazione `taxonomy_poi_types` al mapping `layer` in
  ```
  `wm-geohub-import.php` (pivot `taxonomy_poi_typeables`, key `taxonomy_poi_type_id`,
  foreign key `taxonomy_poi_typeable_id`, morphable_type `App\Models\Layer`)
  ```
- [ ] Creare `GeohubImportService::associateLayersWithEcPoi(Model $model)`:
  ```
  - legge i `taxonomy_poi_type_id` del layer da `taxonomy_poi_typeables` (GeoHub)
  - se nessun `taxonomy_poi_type_id` trovato → log warning e ritorna senza toccare la relazione
  - trova gli EcPoi GeoHub con gli stessi `taxonomy_poi_type_id`
  - mappa agli EcPoi locali via `properties->geohub_id`
  - per ogni EcPoi locale: `attach()` con check `alreadyExists` (stesso pattern di `associateLayersWithEcTrack()`)
  - logga conteggi: POI trovati in GeoHub / associati / già presenti / non trovati localmente
  ```
- [ ] Chiamare `associateLayersWithEcPoi($model)` in `ImportLayerJob::processDependencies()`
- [ ] Scrivere un test Feature che verifica la corretta associazione POI→Layer



## Rischi

- L'ordine `MODEL_IMPORT_ORDER` garantisce `taxonomy_poi_types` e `ec_poi` prima di
`layer`. In un import completo dell'app i POI locali con le loro taxonomy esistono
già al momento dell'esecuzione di `ImportLayerJob`. In un import parziale (solo
layer) le associazioni potrebbero essere incomplete — comportamento accettato.
- `attach()` con check è additivo: non rimuove associazioni manuali su Maphub né
associazioni stale (POI rimossi da GeoHub). Stesso trade-off già accettato per i
track in `associateLayersWithEcTrack()`.
- `LayerableObserver::created()` si innesca per ogni `attach()` e dispatcha un job
di ricalcolo geometria del layer. Con molti POI per layer, vengono accodati N job.
Mitigazione: il pattern è identico a quello già in produzione per i track — accettato.



## Out of scope

- Nessun `poi_mode` (auto/manual) nel `configuration` del layer
- Nessuna UI Nova aggiuntiva — il pannello "Ec Pois" (oc:8160) esiste già
- Aggiornamento dell'associazione `taxonomyPoiTypes()` locale del layer (non
necessario per il funzionamento della feature, gestito da `ImportTaxonomyPoiTypeJob`)



## Moduli toccati

Tutti in `wm-package`:

- `src/Services/Import/GeohubImportService.php` — nuovo metodo `associateLayersWithEcPoi()`
- `src/Jobs/Import/ImportLayerJob.php` — chiamata a `associateLayersWithEcPoi()`
- `config/wm-geohub-import.php` — relazione `taxonomy_poi_types` nel mapping `layer`
- `tests/Feature/Services/Import/GeohubImportServiceTest.php`

