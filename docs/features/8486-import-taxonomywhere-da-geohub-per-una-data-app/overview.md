> Ticket: oc:8486

# Import TaxonomyWhere da GeoHub per una data app

## Cosa cambia

L'azione Nova standalone `Import TaxonomyWhere` (`wm-package/src/Nova/Actions/ImportTaxonomyWhere.php`), che oggi supporta le sorgenti `osmfeatures_*` e `osm2cai`, guadagna una terza sorgente: **GeoHub**. Selezionando "GeoHub" e un'App, l'azione legge direttamente dal DB GeoHub (connessione `geohub`, già configurata) le `taxonomy_where` collegate ai contenuti di quell'app (POI, track, layer) che **non hanno `admin_level`** (es. `corsica`, `francia`, `europe`) — il sottoinsieme che rappresenta territori non coperti da OSMFeatures — e le importa nella tabella locale `taxonomy_wheres`, riusando l'identifier slug già presente su GeoHub invece di generarne uno nuovo.

A fine importazione, `GeometryComputationService::syncTracksTaxonomyWhere()` collega correttamente le track esistenti alle where appena importate, basandosi su `ST_Intersects` locale (non su OSMFeatures). **A differenza degli altri due handler**, che lo chiamano in modo sincrono subito dopo il dispatch dei job di geometria, `handleGeohub()` lo accoda come callback `Bus::batch(...)->then(...)` eseguita solo a copia geometrie completata — trovato in QA manuale che chiamarlo subito, mentre le geometrie sono ancora in copia asincrona, produce sempre "0 tracks sincronizzate" (dettaglio in `notes.md`). L'associazione automatica ai POI resta fuori scope (dipende da un meccanismo — `UpdateModelWithGeometryTaxonomyWhere` — che oggi chiama OSMFeatures e non legge la tabella locale; generalizzarlo è un ticket separato).

## Perché

Alcune app (es. Itinera Romanica, app 28 su GeoHub) hanno contenuti in territori — Corsica, Provenza — che OSMFeatures non copre affatto (verificato: su bbox corsa risponde solo "Sardegna", su bbox provenzale solo "Piemonte"/"Cuneo"). Questo lascia una parte reale di track e POI senza alcuna `taxonomy_where` associata. GeoHub ha già queste geometrie pronte (le where sono importate da OSM manualmente/storicamente su GeoHub); il modo più semplice per recuperarle, senza richiedere modifiche lato GeoHub, è leggerle direttamente dal suo DB.

Non deve diventare un meccanismo automatico: GeoHub verrà spento in futuro, la fonte primaria resta OSMFeatures, e il caso reale riguarda solo 2-3 app su 99. Resta quindi un setup manuale eseguito una tantum per le app che ne hanno bisogno.

## Requisiti

- [ ] Nuova opzione `geohub` nel campo Select "Sorgente" dell'azione `ImportTaxonomyWhere` (label it/en, es. "GeoHub — Where senza admin_level")
- [ ] **Gate super-admin per la sola sorgente `geohub`**: check runtime dentro `handleGeohub()` (`RolesAndPermissionsService::allows($request)`, stesso pattern di oc:8239) — le sorgenti `osmfeatures`/`osm2cai` restano invariate (nessun gate), nessuna modifica a `canSee()`/`canRun()` dell'Action
- [ ] **Refactoring**: estrarre la logica comune a `handleOsmfeatures()`, `handleOsm2cai()` e il nuovo `handleGeohub()` (risoluzione App dal Select, collision handling via `withCollisionCounter()`, dispatch finale `syncTracksTaxonomyWhere()`, formattazione messaggio contatori) in un service/trait condiviso — refactoring dei due handler esistenti incluso nello scope di questo ticket, comportamento esterno invariato
- [ ] Nuovo metodo/handler `handleGeohub(ActionFields $fields)` che:
  - Risolve l'App selezionata (via il service/trait condiviso sopra)
  - Restituisce `Action::danger(...)` se l'App non ha `geohub_id` valorizzato (nessun collegamento GeoHub noto per quell'app)
  - Risolve lo `user_id` GeoHub dell'app con una query sulla connessione `geohub` (`apps` table, by `id` = `geohub_id` dell'App locale) — non va confuso con `App::user_id` (FK verso lo User locale Maphub, valore diverso). Gestisce esplicitamente il caso `user_id` NULL o riga `apps` assente (`Action::danger`, nessuna eccezione non gestita)
  - Esegue la query di selezione (union taxonomy_whereables su EcPoi/EcTrack/Layer dell'app, vedi query nel ticket), filtrata su `admin_level IS NULL`, con `DISTINCT` sulle where
  - Per ogni where trovata: **lookup locale in due passi** — prima by `properties->geohub_id` (idempotenza re-import), se non trovato **fallback per `identifier`** (lo slug GeoHub) prima di creare un nuovo record, per non duplicare un record già esistente da un'altra sorgente/creato manualmente con lo stesso identifier. Se non trovato in nessuno dei due modi, crea con `identifier` impostato **direttamente** allo slug GeoHub (bypassa `generateIdentifier()` grazie al guard `if (empty($identifier))` in `TaxonomyObserver`), gestendo la collisione residua con `withCollisionCounter()` solo se lo slug risulta comunque già in uso da un record con `identifier` diverso
  - Properties: `geohub_id` (idempotenza re-import, stesso pattern di `osmfeatures_id`/`osm2cai_id`), `source => 'geohub'`, `admin_level => null`
  - **Geometria scritta da un job dedicato in coda** (nuovo `CopyTaxonomyWhereGeometryFromGeohubJob`, dispatchato per ogni where creata/aggiornata — stesso pattern asincrono di `FetchTaxonomyWhereGeometryJob`/`FetchOsm2caiSectorGeometryJob`), non sincrono nella request Nova. Il job legge la geometria da GeoHub (`ST_AsGeoJSON` sulla connessione `geohub`) e scrive in **SQL puro** locale (`UPDATE taxonomy_wheres SET geometry = ST_GeomFromGeoJSON(?)`, mai via ORM — pattern Forestas già documentato in `wm-package/CLAUDE.md`). Se la geometria letta da GeoHub è NULL/vuota, il job logga un `Log::warning` e non blocca — il record resta creato senza geometria (stesso comportamento tollerato degli altri due handler in attesa del fetch)
  - Chiama `assignTaxonomyUserFromApp()` (metodo privato già esistente) per attribuire lo user dell'app — **nessuna guardia anti-overwrite cross-app**: comportamento "ultima esecuzione vince", coerente con osmfeatures/osm2cai (decisione esplicita, non un bug da correggere in questo ciclo)
  - A fine ciclo dispatcha i job di copia geometria come `Illuminate\Bus\Batch`, con `SyncTaxonomyWhereTracksJob` (nuovo, wrapper di `GeometryComputationService::make()->syncTracksTaxonomyWhere(...)`) come callback `->then()` eseguita solo a batch concluso — non il pattern sincrono degli altri due handler, vedi `notes.md` per il perché
  - Ritorna un `Action::message(...)` con contatori (creati/aggiornati/skippati), stesso stile degli altri due handler
- [ ] Traduzioni nuova label e messaggi solo in it/en (`resources/lang/{it,en}.json`), coerente con precedente oc:8239 — fr/es/de esplicitamente non coperte
- [ ] Test Feature che copre: import con app senza `geohub_id` (danger), gate super-admin (danger per utente non autorizzato), import con where nuove (create), re-import idempotente (update, nessun duplicato), fallback lookup per `identifier` su record preesistente senza `geohub_id`, filtro `admin_level IS NULL` rispettato, dispatch del job geometria, geometria NULL gestita senza eccezioni, chiamata a `syncTracksTaxonomyWhere()` effettivamente eseguita, regressione sui due handler esistenti dopo il refactoring

## Rischi

- **Mapping App→GeoHub user_id non ha un precedente diretto riusabile 1:1**: gli altri punti del codice che usano `$appUserId` (`GeohubImportService`) lo ricevono già risolto dall'esterno (job/comando), non lo derivano da un'App Maphub già importata. La query `apps table where id = geohub_id` per ottenere `user_id` è una soluzione ragionevole per analogia ma non ha test di regressione preesistenti da cui copiare. Mitigato gestendo esplicitamente `user_id` NULL/riga assente come `Action::danger`
- **`admin_level IS NULL` come euristica di "territorio non coperto da OSMFeatures"**: è un'inferenza empirica su 2 casi osservati (Corsica, Provenza), non una proprietà garantita dallo schema GeoHub — un record con `admin_level` NULL per dati sporchi/mai classificati verrebbe comunque importato. Rischio accettato esplicitamente, nessuna validazione di plausibilità aggiuntiva in questo ciclo
- **`App::geohub_id` non ha integrità referenziale verso GeoHub**: è un JSON senza FK/vincolo di unicità; se il valore fosse storicamente errato, l'azione lo tratterebbe come fonte di verità senza cross-check. Rischio accettato (nessuna mitigazione aggiuntiva richiesta dal dev in fase di challenge)
- **Refactoring dei due handler esistenti nello stesso ciclo**: estrarre logica comune da `handleOsmfeatures()`/`handleOsm2cai()` (già in produzione, nessun test automatico oggi copre questa Action) rischia di introdurre una regressione silenziosa sulle due sorgenti esistenti. Mitigato dal requisito esplicito di test di regressione su entrambe dopo il refactoring
- **Nessuna associazione automatica ai POI**: esplicitamente accettato (vedi Out of scope) — il rischio è che il valore percepito della feature sembri incompleto finché il ticket di generalizzazione per gli EcPoi non viene fatto
- **Query cross-database raw accoppiata a uno schema esterno non versionato**: qualunque cambio di schema lato GeoHub (sistema in via di spegnimento, quindi a manutenzione calante) rompe silenziosamente `handleGeohub()` in produzione, senza che nessun test CI se ne accorga. Rischio accettato — nessun meccanismo di rilevazione drift previsto, coerente con la natura "temporanea" della feature (vedi Out of scope: rimozione manuale quando GeoHub verrà spento, nessun feature flag)

## Out of scope

- Import automatico durante `wm:import-from-geohub` — resta un'azione Nova manuale, mai agganciata al comando di import periodico
- Associazione automatica delle where importate ai POI (dipende da un ticket separato di generalizzazione di `UpdateModelWithGeometryTaxonomyWhere`/`syncTracksTaxonomyWhere` per includere gli EcPoi)
- Filtro/selezione manuale del livello da importare in UI — fisso a "solo where senza `admin_level`", non configurabile da Nova in questo ciclo
- Traduzioni fr/es/de per le nuove stringhe
- Import delle where con `admin_level` (L4/L6/L8) da GeoHub — restano di competenza esclusiva di OSMFeatures
- Feature flag / meccanismo "domini opzionali" (oc:8492) per disattivare la sorgente GeoHub — decisione esplicita: quando GeoHub verrà spento, il codice verrà rimosso manualmente, nessun toggle di config in questo ciclo
- Guardia anti-overwrite su `assignTaxonomyUserFromApp()` per il caso "due app condividono territorio" — comportamento "ultima esecuzione vince" mantenuto per coerenza con osmfeatures/osm2cai, non corretto in questo ciclo
- Controllo di plausibilità preventivo (dry-run/anteprima where prima di eseguire) — non richiesto dal dev in fase di challenge

## Moduli toccati

Tutti in `wm-package/`:
- `src/Nova/Actions/ImportTaxonomyWhere.php` — nuovo handler `handleGeohub()`, nuova opzione Select, refactoring dei due handler esistenti verso il service/trait condiviso
- Nuovo service/trait condiviso (nome esatto da definire in fase di piano, es. `Wm\WmPackage\Services\TaxonomyWhereImportHelper` o trait `HasTaxonomyWhereImportHandling`) — risoluzione app, collision handling, dispatch finale, formattazione messaggio
- `src/Jobs/TaxonomyWhere/CopyTaxonomyWhereGeometryFromGeohubJob.php` (nuovo) — fetch geometria da GeoHub + scrittura SQL puro locale, `Batchable`
- `src/Jobs/TaxonomyWhere/SyncTaxonomyWhereTracksJob.php` (nuovo) — wrapper di `syncTracksTaxonomyWhere()`, dispatchato come callback di fine batch
- `resources/lang/it.json`, `resources/lang/en.json` — nuove stringhe (label sorgente, eventuali messaggi)
- `tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSourceTest.php` (nuovo) — copertura del nuovo handler
- `tests/Feature/Nova/Actions/ImportTaxonomyWhereTest.php` (nuovo o esteso, se non esiste già) — regressione su osmfeatures/osm2cai dopo il refactoring
- `tests/Feature/Jobs/CopyTaxonomyWhereGeometryFromGeohubJobTest.php` (nuovo)
