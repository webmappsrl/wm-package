# Import da GeoHub, OSM e tassonomie

Come il package importa contenuti e tassonomie da sorgenti esterne, e come deriva gli
identifier.

## Stato attuale

### Le tre sorgenti di `ImportTaxonomyWhere`

L'Action `ImportTaxonomyWhere` ha tre handler — `handleOsmfeatures()`, `handleOsm2cai()`,
`handleGeohub()` — che condividono `HasTaxonomyWhereImportHelpers` (risoluzione dell'App dal
Select, `assignTaxonomyUserFromApp()`, sync finale delle tracce).

`handleGeohub()` importa solo le where GeoHub con `admin_level IS NULL` — i territori non
coperti da OSMFeatures — collegate ai contenuti di un'App, via query raw sulla connessione
`geohub` di `config/wm-geohub-import.php`. È l'unico handler dietro un gate super-admin
(`RolesAndPermissionsService::allowsUser(auth()->user())` dentro il metodo, non su
`canSee()`/`canRun()`, che resterebbero più permissivi per le altre due sorgenti) (oc:8486).

**Lookup di idempotenza a due passi**: prima `properties->>'geohub_id'`, poi fallback su
`identifier` (lo slug GeoHub) — evita di duplicare un record già creato da un'altra sorgente o a
mano con lo stesso identifier (oc:8486).

### Identifier delle tassonomie

- La regola di derivazione **non** sta nell'observer: `TaxonomyObserver` chiama
  `generateIdentifier()`, sovrascrivibile dai modelli figli. Un `if ($model instanceof X)`
  nell'observer farebbe crescere una classe condivisa da cinque modelli in tutti i progetti.
- Il guard `if (empty($identifier))` **non va rimosso**: senza, l'identifier di
  `TaxonomyActivity` e `TaxonomyPoiType` verrebbe ricalcolato a ogni rename, rompendo i
  riferimenti persistiti nella config App.
- `TaxonomyWhere::generateIdentifier()` deriva da `properties['source']` + id della sorgente,
  **mai dal nome** quando un id esiste: i nomi OSMFeatures sono assenti o in lingue arbitrarie, e
  `Str::slug()` su alfabeti non latini restituisce stringa vuota. La collisione è impossibile per
  costruzione; la collisione residua è gestita da `withCollisionCounter()`, reso `public` per
  l'import GeoHub.
- `getSourceId()` riconosce `osmfeatures_id`, `osm2cai_id` e `properties['geohub_id']`, in
  quest'ordine.
- Il backfill della migration è in SQL puro: `TaxonomyWhere` estende `Polygon`, e risalvare via
  Eloquent farebbe transitare la geometria PostGIS attraverso l'ORM (oc:8469, oc:8486).

### Import GeoHub: ID e dipendenze

- `ImportTaxonomyJob::processDependencies()` passa l'ID **GeoHub** a
  `getTaxonomyMorphableRecords()`, non l'ID locale: i due divergono e il metodo interroga il DB
  GeoHub (oc:8013). Per i POI usa `$model->properties['geohub_id']` come ID autoritativo, che
  `$this->entityId` può non essere in scenari di re-import (oc:8041).
- `processDependencies()` usa `syncWithoutDetaching()`, non `sync()`: `sync()` azzerava tutte le
  associazioni lasciando solo l'ultimo tema importato (oc:8014).
- `ImportTaxonomyThemeJob` ha un `Log::error()` nel catch che il job gemello per le activity non
  ha: senza, i fallimenti appaiono come "completed" in Horizon e non si diagnosticano (oc:8014).
- I progetti con `wm-geohub-import.php` già pubblicato devono aggiornare a mano
  `default_dependencies['app']` (o ripubblicare con `--force`) quando ne viene aggiunta una.
  `taxonomy_poi_types` era assente, il che rendeva ininfluente il fix ID nel flusso standard
  (oc:8041). `taxonomy_when` e `taxonomy_target` hanno tuttora `'job' => ''` (oc:8014).

### Import da OSM

- L'Action `ImportEcPoiFromOsm` è registrata di default in `EcPoi::actions()` con `canSee` **e**
  `canRun` su `RolesAndPermissionsService::allows()`: `canSee` da solo non blocca l'esecuzione
  diretta, il gate è lato server (oc:8239).
- `OsmPoiImporter::findExistingEcPoiByOsmid()` filtra sempre per `app_id`: senza, due App sullo
  stesso DB con lo stesso `osmid` si sovrascriverebbero a vicenda (oc:8239).
- `OsmfeaturesClient::getAdminAreasIds()` non deve ripiegare su `reset($nameObj)`: l'endpoint
  `admin-areas/list` espone solo le traduzioni `name:<lang>`, mai il tag `name` base, che va
  letto dal dettaglio (oc:8469).
- Nessun `User-Agent` custom su `OsmClient`: rischio noto di rate-limit condiviso fra consumer
  che importano in parallelo, non risolto (oc:8239).

### Import Excel

`EcPoiRowProcessor::apply()` deve sincronizzare `properties['name']` da `getTranslations('name')`
dopo il loop: `saveQuietly()` bypassa `AbstractObserver::saving()`, che normalmente fa la sync.
La logica è quindi duplicata fra observer e processor — debito accettato per tenere il fix
minimale. `EcTrackRowProcessor` non è affetto, non usa `setTranslation` per il nome (oc:8063).

## Come ci siamo arrivati

- `handleGeohub()` chiamava `syncTracksTaxonomyWhere()` in modo sincrono subito dopo il dispatch
  asincrono dei job di geometria, come fanno ancora gli altri due handler: è una race condition —
  la sync trova sempre geometrie vuote e riporta "0 tracks". Corretto solo per GeoHub con
  `Bus::batch($jobs)->then(fn () => SyncTaxonomyWhereTracksJob::dispatch())`. **Lo stesso difetto
  esiste ancora in `handleOsmfeatures()`/`handleOsm2cai()`**, mai osservato in pratica (oc:8486).
- Prima di oc:8486 l'Action non aveva alcuna copertura di test, su nessuna delle tre sorgenti.

## Trappole dell'import, raccolte dai cantieri

**Ordine e transazioni**

- `GeohubImportService::MODEL_IMPORT_ORDER` **non garantisce l'ordine**: il path di produzione
  (`ImportAppJob::queueEntityImport()`) dispatcha un `Bus::batch()` indipendente per dipendenza,
  senza chaining. L'ordine vale solo per `importAll()` da CLI (oc:8094).
- Un errore Postgres non gestito dentro un batch sincrono **aborta la transazione per tutte le
  query successive**, incluso il tracking del batch: il comando fallisce con
  `current transaction is aborted`, che nasconde la causa vera (oc:8158).
- Un `catch` che logga senza `throw` fa apparire il job **completato** su Horizon: nessun retry,
  nessun fallimento visibile. La convenzione di `BaseImportJob`/`BaseUgcImportJob` è rilanciare
  sempre (oc:8158). Sotto `QUEUE_CONNECTION=sync` il `release()` non rimette il job in coda: i
  retry manuali non si riproducono in locale (oc:8158).

**Pivot e match**

- `syncWithoutDetaching([$id => $pivotData])` con pivot data **non vuoto** chiama
  `updateExistingPivot()` anche su un pivot già esistente, sovrascrivendo valori reali (es.
  `duration_forward`/`backward` azzerati a ogni re-import). Con array vuoto non tocca nulla:
  serve l'exists-check prima di `attach()` (oc:8094).
- Il match per identifier ha un fallback `LIKE '%…%'`: su 502 `taxonomy_themes` reali produce 33
  collisioni di sottostringa. Il match esatto va tentato **prima** (oc:8094).
- `getTaxonomyMorphableRecords()` fa `whereIn()` senza batching: per una taxonomy molto diffusa
  supera il limite PDO di 65.535 parametri — ancora senza chunking (oc:8094).
- `import_mapping.*.relations.morphable_models` usa la chiave `media` per
  `taxonomy_activity`/`poi_types` e `ec_media` per `taxonomy_theme`: non è una fonte affidabile
  da cui derivare un FQCN (oc:8094).

**Dati e colonne**

- `users.app_id` ha lo stesso nome e significato incompatibile fra i due sistemi: stringa SKU su
  GeoHub, intero FK in locale. Va esclusa esplicitamente dalla copia cieca delle colonne
  (oc:8158).
- `BaseImportJob::transformData()` forza `user_id` al proprietario dell'app quando c'è `app_id`:
  corretto per gli EC, sbagliato per gli UGC, che hanno un autore proprio — i job UGC devono
  sovrascriverlo (oc:8158).
- `json_decode()` di una stringa malformata ritorna `null`, non `[]`: il `?? '{}'` copre solo la
  chiave assente, serve un cast esplicito prima del `foreach` (oc:8094).
- Un `LineString` con un solo punto fa fallire persino `ST_GeomFromWKB()` a livello Postgres: non
  si intercetta con una query PostGIS, serve parsare i byte (E)WKB in PHP (oc:8158).
- `get_headers($url, 1)[0]` ritorna `false` su URL irraggiungibile, e
  `EcMediaImportService` lo tratta come «non è un'immagine» — messaggio fuorviante, corretto solo
  nel path UGC (oc:8158).

**Compatibilità e configurazione**

- `UniqueConstraintViolationException` esiste solo da Laravel 11, ma il package dichiara
  `^10.0 || ^11.0 || ^12.0 || ^13.0`: va intercettata `QueryException` con SQLSTATE `23505`
  (oc:8158).
- Doppia whitelist delle dipendenze — `ImportAppJob::getAllowedDependencies` (contiene gli UGC) e
  quella di default da config. Se non allineate, `--dependencies=...,ugc_poi` viene scartato
  **silenziosamente** (oc:8158).
- `GeohubImportService::$logger` è un `Illuminate\Log\Logger` concreto catturato nel costruttore:
  `Log::spy()` non intercetta quei log, va mockata la classe concreta e iniettata via reflection
  (oc:8094).
- `Wm\WmPackage\Models\UgcMedia` **non esiste**, ma è ancora referenziata da
  `AppClassificationService` (endpoint attivo `ranked_users_near_pois.json`) e da
  `GeometryComputationService` (oc:8158).
