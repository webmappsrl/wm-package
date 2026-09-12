# Layer: modalità auto/manuale e associazione dei contenuti

Come un Layer si lega a tracce e POI, e perché la modalità "manuale" non è ciò che sembra.

## Stato attuale

### Modalità auto/manuale

`isAutoTrackMode()`/`isAutoPoiMode()` leggono `configuration['track_mode']`/`['poi_mode']`, con
default da `config('wm-package.default_layer_mode')` (env `DEFAULT_LAYER_MODE`, default `auto`) —
la chiave permette a un consumer di cambiare il default senza impattare gli altri.

`setTrackMode()`/`setPoiMode()` scrivono con un `UPDATE ... jsonb_set` via `DB::statement()` +
`refresh()`, non con un read-modify-write Eloquent: quest'ultimo perdeva scritture concorrenti
quando i due pannelli Nova (Ec Tracks e Ec Pois) scrivevano quasi in contemporanea sulla stessa
colonna `configuration`. Conseguenza: non passando più da `save()`, `LayerObserver::saved()` non
scatta più al cambio modalità.

`persistMode()` va chiamato **prima** di `assignTracksByTaxonomy()`/`assignPoisByTaxonomy()` nel
ramo auto di `sync()`: questi leggono la modalità e devono vedere il valore già aggiornato.

Contratto mode-only: una richiesta senza `auto` né `features` non tocca il pivot e non rigenera i
PBF (oc:8314).

**Limite noto, non risolto**: `setTrackMode()`/`setPoiMode()` non sono invocati da nessun endpoint
o action in produzione, quindi le due modalità sono di fatto sempre `auto`, e qualunque evento di
tassonomia successivo sovrascrive con un `sync()` ricalcolato una selezione manuale fatta da Nova.
Il toggle "manuale" funziona solo fino al prossimo evento reattivo. Comportamento ereditato da
`track_mode`, replicato identico per `poi_mode` per parità esplicita (oc:8043).

### `poi_mode` come mirror di `track_mode`

`LayerService::assignPoisByTaxonomy()` è il mirror di `assignTracksByTaxonomy()` — stesso JOIN
`taxonomy_activityables` + `ST_Intersects` su `taxonomy_wheres` — ma **non** copre
`taxonomy_poi_types`. Stessi quattro observer reattivi (`TaxonomyActivityablesObserver`,
`TaxonomyWhereablesObserver`, `LayerObserver`, `EcPoiObserver`) e il job
`SyncAutoLayerAfterPoiTaxonomyChangeJob` (oc:8043).

**`regeneratePbfsForLayer()` non va chiamato per i POI**: i PBF referenziano solo
`ec_track_table`, non contengono mai contenuto POI. Senza questa guardia un layer POI-only in
un'app senza `map_bbox` fa fallire il job con un'eccezione non catturata, impedendo anche il
reindex a valle. La stessa guardia `$relationName === 'ecTracks'` è già in
`LayerFeatureController::sync()` e in `LayerableObserver::handleRelatedFeaturesUpdate()`
(oc:8043).

**`EcPoi` non ha il trait `Laravel\Scout\Searchable`**, a differenza di `EcTrack`: i POI non sono
indicizzati su Scout/Elasticsearch. Un job o observer che debba "reindicizzare" un EcPoi dopo un
`saveQuietly()` deve dispatchare `BuildAppPoisGeojsonJob`, non chiamare `->searchable()`
(oc:8043).

### Import GeoHub e associazione POI

`associateLayersWithEcPoi()` traversa tre meccanismi GeoHub in sequenza —
`taxonomy_themeables`, `taxonomy_whereables`, `taxonomy_poi_typeables`. GeoHub non ha un rapporto
diretto Layer→EcPoi: la relazione è sempre indiretta via tassonomia condivisa, e
**`taxonomy_theme` è il meccanismo primario**. L'`attach()` è protetto da un check
`alreadyExists` per l'idempotenza al re-import, e i `geohub_poi_id` sono deduplicati prima
dell'attach (oc:8043).

**Morph map locale contro stringhe GeoHub.** `Relation::morphMap()` in
`WmPackageServiceProvider` mappa `'App\Models\EcPoi'` e simili alle classi del package. Il check
di idempotenza sulla pivot locale `layerables` deve confrontare con l'**alias**, non con l'FQCN,
altrimenti l'idempotenza fallisce in silenzio e duplica le righe. Il match sul `morphable_type`
nelle tabelle GeoHub usa la stessa stringa, ma è un sistema diverso: coincidenza, non stesso
meccanismo (oc:8043).

**`config('wm-package.ec_poi_model')` non è registrata in `config/wm-package.php`**, a differenza
di `ec_track_model`, e il suo default corretto è `EcPoi::class` del package — **non** la stringa
`'App\Models\EcPoi'`. Non aggiungerla con quel default: in molti progetti la classe applicativa
`App\Models\EcPoi` non esiste, mentre `App\Models\EcTrack` tipicamente sì (oc:8043).

**Doppio meccanismo non coordinato sulla pivot `layerables`**: `associateLayersWithEcPoi()`
(import, `attach()` additivo) e `assignPoisByTaxonomy()` (auto mode, `sync()` a rimpiazzo totale)
scrivono sulla stessa pivot con semantiche diverse. Un layer in `poi_mode: auto` può perdere i POI
aggiunti dall'import — specialmente quelli associati solo via `taxonomy_poi_types`, che
`assignPoisByTaxonomy()` non copre — al primo ricalcolo. Stesso trade-off già esistente per
`ecTracks`, nessuna coordinazione implementata (oc:8043).

### Scope, observer e geometrie

- `scopeByWhereProperty()` fa early return **senza aggiungere condizioni** quando `taxonomy_where`
  è vuoto: chi lo usa come unico filtro (`scopeOnLayer()`) si ritrova tutti i modelli dell'app,
  non zero. Il guard va messo dal chiamante (`LayerService::hasValidAutoModeFilter`) (oc:8140).
- `EcTrackObserver::deleting()` usa un mass delete via query builder
  (`Layerable::where(...)->delete()`), che **non emette gli eventi per riga** — al contrario di
  quanto dice il commento accanto. La pulizia dei POI orfani introdotta con oc:8139 non scatta
  (oc:8180).
- Un consumer **non può** registrare un observer prima di quello del package: `EcTrack::booted()`
  registra `EcTrackObserver` alla prima `observe()`, quindi ogni registrazione successiva viene
  dopo. Per leggere il pivot prima della cancellazione serve
  `Event::listen('eloquent.deleting: ...')` (oc:8180).
- `UpdateModelWithGeometryTaxonomyWhere` fa `saveQuietly()` sul modello ricevuto: non è usabile
  con un modello temporaneo, inserirebbe una riga (oc:8180).
- `GeometryComputationService::isRoundtrip()` appiattisce prendendo `$coord[0]` di **ogni** parte:
  su una MultiLineString confronta due partenze invece di partenza e arrivo. Il commento che parla
  di «diff < 300 metri» è sbagliato: `0.001` gradi sono ≈111 m in latitudine e ≈82 m in
  longitudine a 42°N (oc:8180).
- Le geometrie PostGIS passano sempre da SQL puro (`ST_GeomFromGeoJSON`/`ST_AsGeoJSON`), mai
  attraverso l'ORM (oc:8486, oc:8469).

### Mappa nel pannello Nova

- `addFeaturesForMap()` in `FeatureCollectionMapTrait` inietta `$this->id` (l'ID del layer) in
  ogni feature: innocuo, perché il click usa la property `link`, non `id`. L'`id` conta solo in
  modalità popup, non usata qui.
- La tabella `ec_pois` è hardcoded nella raw SQL di `getFeatureCollectionMap()`, come
  `taxonomy_wheres` nello stesso metodo: nessun `ec_poi_table` in config, per simmetria con
  `ec_track_table` (oc:8160).
- I link costruiti a mano in un field Nova devono usare `Nova::path()`: un
  `/resources/<model>/<id>` hardcoded perde il prefisso `/nova` (oc:8089).

### `BulkEditAction`

`new BulkEditAction(Resource::class, $fields = [], $exclude = ['name', 'geometry', 'description'])`
— l'`$exclude` di default protegge da un bulk edit accidentale di nome, geometria e descrizione;
si disabilita con `[]`.

- `BelongsToMany`/`MorphToMany` implementano `ListableField`, non `RelatableField`: il filtro deve
  controllare **entrambe** le interfacce per escludere tutti i campi relazionali.
- Il readonly dinamico (closure) va azzerato con `->readonly(false)` prima di restituire il campo:
  valutarlo su `::newModel()` darebbe sempre `true` per i campi tipo `getMedia()->isEmpty()`.
- Nova serializza i campi `properties->*` come oggetto annidato sotto `properties` in
  `ActionFields`, **non** come chiavi letterali: `handle()` non deve iterare le chiavi top-level,
  tratterebbe `properties` come un unico valore sovrascrivendo il JSON. Si usa `resolveChanges()`,
  che cicla sui campi di `fields()` leggendo ognuno con `array_key_exists` o `data_get()`.
- Per i path arrow notation il merge è esplicito: si legge l'array corrente, `Arr::set()` sul solo
  path, si riassegna l'intero array, così i sibling restano invariati (oc:8133).

## Come ci siamo arrivati

- `LayerFeatureController::sync()`/`getFeatures()` avevano la logica auto/manuale hardcoded su
  `$relationName === 'ecTracks'`: nel pannello Ec Pois, "Selezione Automatica" eseguiva
  `$layer->ecPois()->sync([])`, cancellando tutte le associazioni POI. Resi relation-aware, e il
  meta key del field `LayerFeatures` è passato da `trackMode` a `mode` (oc:8043).
- `SyncAutoLayerAfterPoiTaxonomyChangeJob` chiamava `regeneratePbfsForLayer()`: bug trovato in
  review e corretto (oc:8043).
- Un side-effect del cambio modalità basato su `LayerObserver::saved()` era stato ipotizzato in
  fase di design: non è applicabile all'implementazione finale, che non passa più da `save()`
  (oc:8314).
- La relazione dei POI manuali sul Layer si chiamava `manualEcPois()`: oggi è `ecPois()`, e il
  vecchio nome resta solo come alias `@deprecated` (oc:8139).
