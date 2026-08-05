> Ticket: oc:8158

# Notes — Import GeoHub: estensione UGC (POI, Track, Media) e utenti autori UGC

## Deviazioni dal piano

- **Geometria `ugc_track`**: il piano non specificava la conversione da `LineStringZ` (formato sorgente GeoHub) a `MultiLineStringZ` (colonna locale). Verificato empiricamente che serve `ST_Multi()` esplicito oltre a `ST_Force3D()` — senza, l'insert avrebbe violato il vincolo di tipo della colonna. Confermato con test dedicato (`ImportUgcTrackJobTest`).
- **Skip su geometria mancante**: aggiunto un controllo difensivo (non nel piano originale) che scarta con log un record POI/Track privo di geometria, invece di forzare una geometria di default come fa il pattern EC. Per UGC non ha senso un default fittizio: se manca la geometria, il dato è incompleto.
- **`AppClassificationService::getRankedUsersNearPoisQuery()`**: durante la verifica del task "AppClassificationService/GeometryComputationService" (Requisiti) è emerso che il metodo è **raggiungibile** (route registrate in `routes/api.php` e `routes/web.php`), non dead code come ipotizzato nell'overview. Corretto il confronto `ugc_media.app_id` (ora integer FK) contro `$app->app_id` (stringa SKU, colonna diversa) — sostituito con `$app->id`. Corretto anche l'URL hardcoded `https://geohub.webmapp.it/storage/...` con `Storage::url()`, dato che i file ora vivono sullo storage locale Maphub, non più solo su GeoHub.
- **`GeometryComputationService::getRelatedUgcGeojson()`**: stessa ipotesi di reachability verificata, ma qui risultato **dead code** (nessun chiamante in wm-package né in maphub) — non modificato, come da criterio del task. Contiene comunque un bug preesistente non legato a questo ticket: la chiave `'App\Models\UgcMedia'` non è mai stata registrata in `Relation::morphMap()` (a differenza di UgcPoi/UgcTrack), quindi `$class::find()` fallirebbe con "Class not found" se mai richiamato con quella chiave. Segnalato qui per chi in futuro riattivasse questo metodo; non corretto perché fuori scope (nessun chiamante).

## Bug trovati

- Vedi sopra: `AppClassificationService` — bug reale su schema, corretto in questo ciclo.
- `GeometryComputationService::getRelatedUgcGeojson()` — bug di morph map mancante, preesistente, non corretto (dead code, fuori scope).

## Decisioni

- **UgcMedia: tabella dedicata confermata (non Spatie Media Library)**. Durante l'implementazione è emerso che `GeometryModel` (classe base di `UgcPoi`/`UgcTrack`) implementa già `HasMedia`/`InteractsWithMedia` di Spatie — lo stesso meccanismo usato da `EcMedia`. Verificato sui dati reali GeoHub (~9.000 righe `ugc_media`) che `user_id`/`app_id` del media coincidono **sempre** con quelli del POI/Track collegato (0 eccezioni), mentre la `geometry` è diversa nel 66% dei casi. Presentata la scelta al dev tra tabella dedicata e riuso di Spatie (con geolocalizzazione della foto salvata in `custom_properties`); confermata la tabella dedicata (opzione A, coerente con l'overview originale) per fedeltà 1:1 allo schema GeoHub.
- **Verifica end-to-end reale limitata ai test automatici**: il comando `wm:import-from-geohub app 49` non è stato eseguibile end-to-end contro il vero database GeoHub in locale — i progetti `maphub` e `geohub` girano su stack Docker Compose separati e isolati in rete. Ho verificato durante il tentativo che collegare `php-maphub` alla rete di `geohub` introduce un'ambiguità reale: entrambi i progetti usano l'alias `db` per il proprio container Postgres, e con entrambe le reti attive la connessione di **default** di Maphub rischia di risolvere verso il Postgres di GeoHub invece che verso il proprio (verificato concretamente: query di test fallita per credenziali sbagliate, sintomo della connessione sbagliata). Disconnessa subito la rete per sicurezza. Il dev ha confermato di procedere solo con la verifica indiretta (10 test Feature Pest costruiti su schema e dati reali osservati su GeoHub durante l'analisi, incluse le migration applicate e verificate sul DB locale).

## Review formale (wm-review-ticket, pre-commit)

Eseguita prima dei commit, sul diff non ancora committato (nessuna PR esistente a questo punto). 5 finder paralleli (correctness, side-effect/bug, deviazioni dal piano, cleanup, design). Verdetto iniziale: **da correggere** — 4 bug bloccanti confermati da più finder indipendenti, nessuno intercettato dai 10 test iniziali perché testavano i job direttamente, bypassando `ImportAppJob`. Tutti corretti in questo stesso ciclo, con test di regressione dedicati dove mancavano:

1. **Crash garantito su `Bus::batch()->then()`** (il più grave): la closure catturava implicitamente `$this` — quindi `$this->geohubImportService`, con una connessione DB viva — e Laravel serializza le closure `then()` in `job_batches.options` al momento del `dispatch()`, non dopo. PHP non serializza un handle PDO: crash immediato su ogni import con almeno un POI/Track, cioè lo scenario centrale del ticket. **Fix**: closure `static`, nuovo metodo statico `dispatchUgcMediaBatch()` che risolve un `GeohubImportService` fresco dal container invece di chiudere sull'istanza già iniettata. Test di regressione: `ImportAppJobUgcDependenciesTest`.
2. **`MODEL_IMPORT_ORDER` riapriva il bypass dell'opt-in**: le tre entry UGC aggiunte lì le rendevano raggiungibili anche da `wm:import-from-geohub` senza argomenti (`importAll()`), che non passa `app_id` e ignora il flag `--dependencies`. **Fix**: rimosse da `MODEL_IMPORT_ORDER` — non serve al flusso reale (`queueUgcDependencies()` non la usa).
3. **Race condition sulla creazione dell'utente autore** (non solo sul ruolo): `resolveShardUser()` faceva un check-then-act non atomico su `User::create()`. Stesso tipo di bug già mitigato per il ruolo Contributor (pre-seed), ma un livello sopra. **Fix**: try/catch su `QueryException`, ri-lettura per email in caso di conflitto di unicità invece di propagare l'eccezione.
4. **Download foto eseguito prima del check create-only**: confermato da 3 finder indipendenti. `beforePersist()` (download + `Storage::put()`) veniva chiamato prima di `importDataCreateOnly()`, quindi un re-import riscaricava e sovrascriveva silenziosamente ogni foto già importata — vanificando la garanzia "mai più toccato" documentata nell'overview. **Fix**: nuovo `GeohubImportService::existsForIdentifier()`, controllato in `BaseUgcImportJob::handle()` prima di `transformData()`/`beforePersist()`. Test di regressione: `ImportUgcMediaJobTest::test_reimport_does_not_redownload_or_overwrite_existing_media`.

Cleanup applicati nello stesso giro: null-safety in `UgcMediaFactory`, type hint mancante su `ImportUgcMediaJob::syncPivot()`. Cleanup **non** applicati (accettati come follow-up, non bloccanti):
- Duplicazione `assignEditorRole()`/`assignContributorRole()` (stessa struttura, solo il nome del ruolo cambia)
- Regex WKB/WKT duplicata in `ImportUgcTrackJob`/`ImportUgcMediaJob` invece di riusare `GeometryComputationService`
- Setup di test duplicato nei tre file `tests/Feature/Import/*JobTest.php`
- `BaseUgcImportJob::handle()` duplica per intero `BaseImportJob::handle()` invece di esporre hook di estensione (template method)
- Logica di sync pivot dentro il Job (`ImportUgcMediaJob::syncPivot()`) invece che nel Service, incoerente con il resto della pipeline (`associateLayersWithEcPoi()` vive in `GeohubImportService`)
- `queueUgcDependencies()` reimplementa la meccanica di batch di `queueEntityImport()` invece di estenderla — un quarto tipo UGC richiederebbe un altro blocco ad-hoc
- Bug preesistente (non introdotto da questo ticket, solo propagato per coerenza con righe già esistenti): `config('wm-geohub-import.queue.queue', ...)` non è il path corretto (`wm-geohub-import.queue.geohub-import.queue`) — innocuo oggi perché il default coincide col valore reale
- `resolveShardUser()`: nessun test dedicato alla race condition ora corretta (richiederebbe simulare concorrenza reale, non praticabile in un test Pest singolo processo)
- Pivot non risincronizzata per un `UgcMedia` già esistente se GeoHub aggiunge un nuovo collegamento dopo il primo import — conseguenza diretta e accettata del design create-only, non discussa esplicitamente per il caso pivot nell'overview originale

## Follow-up

- **Full e2e reale da eseguire quando l'ambiente lo consente**: comando pronto —
  ```bash
  php artisan wm:import-from-geohub app 49 --dependencies=ec_poi,ec_track,taxonomy_activity,taxonomy_theme,taxonomy_poi_types,layer,ec_media,ugc_poi,ugc_track,ugc_media
  ```
  Controlli da fare: conteggi attesi (`ugc_pois`: 47, `ugc_tracks`: 74, `ugc_media`: 18), campione di `user_id` corretti, pivot media↔POI/Track coerenti.
- **Conflitto alias `db` tra progetti Docker (maphub/geohub)**: non risolto, fuori scope di questo ticket. Se in futuro serve testare import cross-progetto più spesso, valutare di rinominare uno dei due alias nei rispettivi `compose.yml` — richiede coordinamento con chi mantiene GeoHub.
- **`GeometryComputationService::getRelatedUgcGeojson()`**: morph map mancante per `UgcMedia` (vedi sopra) — da correggere se questo metodo viene mai effettivamente richiamato in futuro (qui o in altri consumer del package).
