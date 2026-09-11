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

---

## Follow-up post-rilascio: pannello di selezione where (ticket riaperto)

> Emerso da una discussione con il CTO (Giuseppe Bonfanti) dopo il primo rilascio — vedi trascrizione scrum interna. Riapre e sostituisce le voci "Out of scope" delle righe 49 e 54 sopra.

### Cosa cambia

Il filtro `admin_level IS NULL` resta il criterio per determinare l'**insieme candidato** delle where importabili, ma l'azione non le importa più tutte automaticamente: prima dell'esecuzione, un nuovo campo Nova `MultiSelect` ("Territori da importare") mostra la lista delle where candidate per l'App/Sorgente scelte — **tutte selezionate di default** — e l'admin può deselezionare quelle che non vuole importare. Solo le where rimaste selezionate al submit vengono effettivamente create/aggiornate.

Il campo si popola dinamicamente via `dependsOn(['source_type', 'app_id'], ...)` (Nova 5.7.6, verificato disponibile) — stesso meccanismo già usato in `AbstractUserResource::PermissionBooleanGroup` (unico precedente nel package: opzioni ricalcolate da query in base a un campo scelto in precedenza) — quindi **non serve un flusso a due submit**: la lista compare dentro la stessa modale dell'azione, prima di un unico "Run Action".

### Perché

**Studio dati eseguito su GeoHub reale** (68 app, 8101 `taxonomy_wheres` totali):
- 71 where senza `admin_level` su tutto GeoHub; 69/71 (97%) collegate al contenuto di almeno un'app — non sono in prevalenza rumore/orfane
- Per singola app la cardinalità **non è uniformemente piccola**: il caso originale del ticket (Itinera Romanica, 14) non è rappresentativo — alcune app arrivano a 40-48 (Sardegna Sentieri 48, Emergenza Sentieri Romagna/Webapp DEV/Infomont/APP del sentierista 46, MotoMappa 44, Sentiero Italia 42)
- Controllato il contenuto per il caso peggiore (Sardegna Sentieri): sono in maggioranza **sottoregioni storiche sarde legittime** (`barbagia-di-nuoro`, `ogliastra`, ecc.), non rumore — ma compaiono anche voci a bassa informatività (`europe`, `africa`, `area-b`...`area-t` generiche) mescolate alle altre
- **Non esiste un modo affidabile di distinguere "legittimo" da "generico" solo dai dati** — nessun campo su GeoHub lo indica, un'euristica di naming sarebbe fragile — da qui la scelta di lasciare la selezione all'admin invece di un filtro automatico più stretto

**Verificato e chiuso, non più un rischio**: il timore del CTO che il campo App (condiviso nel form tra le tre sorgenti) fosse stato introdotto/alterato da questo ticket per la sola sorgente `geohub` è **infondato** — verificato con `git show 6691f2d1:.../ImportTaxonomyWhere.php` (commit immediatamente precedente a oc:8486): il blocco `App::all()` + `Select::make('App', 'app_id')` esisteva già, identico, per `osmfeatures`/`osm2cai`, prima di questo ticket. Nessuna modifica necessaria su questo fronte — **nessuna nuova Action separata**, si resta sull'azione unica esistente.

### Requisiti

- [ ] Nuovo campo `MultiSelect::make('Territori da importare', 'geohub_where_ids')` nel form dell'azione, visibile solo quando `source_type === 'geohub'` (nascosto altrimenti via `dependsOn(...)->hide()`)
- [ ] Opzioni popolate dinamicamente da una query GeoHub (stessa logica SQL già usata in `handleGeohub()` — union EcPoi/EcTrack/Layer, `admin_level IS NULL` — da estrarre in un metodo condiviso richiamabile sia dal callback `dependsOn` sia da `handleGeohub()`, per non duplicare la query)
- [ ] Chiave opzione = id GeoHub della where (`$row->id`, coerente con quanto già usato internamente); label = nome tradotto + identifier
- [ ] Where già presenti localmente (stesso `geohub_id`) mostrate in lista con etichetta "già importata" — **incluse**, non escluse, per permettere un refresh forzato
- [ ] Valore di default del `MultiSelect` = tutte le chiavi disponibili (tutte selezionate all'apertura) — **confermato dal dev dopo challenge**: la maggior parte delle where servirà, deselezionare le poche non necessarie costa meno che selezionare manualmente le molte utili
- [ ] **Toggle "Seleziona tutte / Deseleziona tutte"** accanto al `MultiSelect`, per comodità su liste da 40+ opzioni
- [ ] Nessun trattamento speciale per le voci a bassa informatività (`europe`/`africa`/`area-*`) — stesso comportamento di selezione di default delle altre, nessuna euristica di naming
- [ ] Where già presenti localmente restano **selezionate di default insieme alle nuove** (nessuna distinzione di stato iniziale) — solo l'etichetta "già importata" le distingue visivamente; il dev accetta il rischio di riassegnamento involontario su re-run (già presente come rischio noto "ultima esecuzione vince" nel ciclo originale)
- [ ] **Gate super-admin estratto in un metodo condiviso** (es. `HasTaxonomyWhereImportHelpers::ensureGeohubSourceAllowed()`) e richiamato **sia** dal callback `dependsOn` **sia** da `handleGeohub()` — un solo punto di verità, non due check indipendenti che potrebbero disallinearsi in futuro. Se l'utente non è super-admin, il campo va nascosto o le opzioni devono restare vuote
- [ ] `handleGeohub()` filtra le righe candidate tenendo solo quelle il cui id è presente nell'array inviato da `geohub_where_ids`. **Distinzione esplicita fra campo assente e array vuoto**: se `geohub_where_ids` è del tutto assente dal payload (non inviato — copre chiamate dirette/test/tinker che non passano dal form Nova reale), nessun filtro applicato, si importa l'intero set candidato (comportamento pre-follow-up, retrocompatibile). Se il campo è presente ma è un array vuoto (form Nova reale, admin ha deselezionato tutto), `Action::danger('Nessuna where selezionata.')`, nessuna creazione/aggiornamento eseguita
- [ ] Scope limitato alla sola sorgente `geohub` — `osmfeatures`/`osm2cai` restano invariate (nessuno studio di cardinalità analogo è stato fatto per quelle sorgenti, estenderle sarebbe speculativo)
- [ ] Test: opzioni popolate correttamente per un'App con candidati noti, where già importate etichettate ma comunque selezionate di default, selezione parziale rispettata (solo i selezionati vengono creati/aggiornati), array vuoto esplicito → danger, campo assente → importa tutto (retrocompatibilità chiamate dirette), gate applicato anche al `dependsOn` tramite il metodo condiviso (verifica che un utente non super-admin non riceva le opzioni)

### Rischi

- **Il gate sul `dependsOn` è un punto di attenzione nuovo**: mitigato estraendo il check in un metodo condiviso riusato da entrambi i path (vedi Requisiti) invece di duplicare la logica — riduce ma non azzera il rischio di dimenticanza futura se qualcuno introduce un terzo punto di accesso alla query GeoHub senza passare dal metodo condiviso
- **TOCTOU fra apertura form e submit** (ID selezionati non più presenti nel set al momento dell'esecuzione): **non mitigato, accettato esplicitamente dal dev** — gli id GeoHub sono chiavi primarie stabili, lo scenario "sparisce tra apertura e submit" è considerato irrilevante in pratica
- **"Tutto selezionato di default" non garantisce che l'admin curi effettivamente la selezione**: accettato esplicitamente dal dev come compromesso consapevole (vedi Requisiti) — il pannello resta comunque un miglioramento rispetto a "nessun controllo possibile" anche se il default non forza una revisione attiva
- **Doppia esecuzione della query GeoHub per singola azione** (una volta per popolare il `MultiSelect`, una volta dentro `handleGeohub()` per i dati completi delle righe) — accettato: la query è economica (join su poche tabelle, filtrata per singola app), non vale la pena introdurre una cache per un'azione amministrativa a bassa frequenza
- **`MultiSelect` con 40-48 opzioni resta usabile ma non ottimale** — l'alternativa (componente Vue custom con tabella/anteprima) è stata scartata esplicitamente dal dev per costo/rischio non giustificato su un caso d'uso a bassa frequenza

### Out of scope (di questo follow-up)

- Estensione del pannello di selezione a `osmfeatures`/`osm2cai`
- Filtro automatico o euristica per escludere le voci a bassa informatività (`europe`/`africa`/generiche)
- Componente Vue custom con anteprima geometria/mappa per la selezione
- Persistenza della selezione tra un'esecuzione e l'altra (ogni apertura del form riparte con tutto selezionato)

---

## Follow-up 2: due modali separati per la selezione (UX reale del follow-up precedente insufficiente)

> Emerso da un test manuale reale del follow-up precedente (sezione sopra): la lista di checkbox compare nella **stessa** modale del bottone "Run Action" già cliccato — un click-through abituale (comportamento reale dell'utente, non un bug di rendering) esegue l'import prima che l'admin possa deselezionare qualunque cosa. Riapre e sostituisce l'approccio a singola modale (`MultiSelect`/`BooleanGroup` + `dependsOn`) descritto nella sezione "Follow-up post-rilascio" sopra — **non ne estende i requisiti, li rimpiazza per la parte UI**.

### Cosa cambia

Il form dell'azione torna a contenere **solo** Sorgente + App (nessun campo lista candidati). `handle()` (quindi `handleGeohub()`), quando la sorgente è `geohub`, non esegue più l'import direttamente: risolve App e gate come oggi e poi:

- se c'è un errore (gate, App senza `geohub_id`, nessuna where candidata) → `Action::danger(...)`, come oggi, **nessuna modale aggiuntiva**
- se tutto è risolvibile → invece di importare, ritorna **`Action::modal($nome, $payload)`** — attenzione all'arità: `Action::modal()` (`vendor/laravel/nova/src/Actions/Action.php:453`) si comporta diversamente in base al numero di argomenti passati (`func_num_args()`). Chiamato con **3 argomenti** (`Action::modal($nome, [], $payload)`) ritorna una **nuova istanza `Action` no-op**, pensata per essere registrata in `actions()` ed eseguita come azione a sé — non l'`ActionResponse` che serve qui. La forma corretta per restituire immediatamente la risposta che apre la seconda modale è chiamarlo con **esattamente 2 argomenti**: `Action::modal($nome, $payload)`, che internamente fa `return ActionResponse::modal($nome, $payload)` (`ActionResponse.php:263`, firma `modal(string $modal, array $data)`) — verificato leggendo entrambi i file del vendor installato, non per analogia. Nova chiude la prima modale e ne apre una seconda, **component-driven**, passandole `$payload` come prop `data`

La seconda modale è un **componente Vue custom** (non un Field Nova — l'`Action::modal()` non porta con sé il ciclo di vita Field/`dependsOn`, quindi il `BooleanGroup` introdotto nel follow-up precedente non è riusabile qui: verificato tracciando `useActions.js`/`ActionSelector.vue`, il componente della seconda modale riceve solo `data` come prop e deve emettere `confirm`/`close` da solo). Il componente:
- Renderizza la lista delle where candidate come checkbox HTML semplici (stato Vue locale, non un Field Nova), tutte pre-selezionate, con etichetta "(già importata)" dove applicabile — stessi dati e stessa logica di etichettatura del follow-up precedente, ma calcolati **prima** dal server (in `handle()`, non in un `dependsOn`) e passati già pronti nel payload
- Aggiunge un toggle **"Seleziona tutte / Deseleziona tutte"** — ora fattibile in modo pulito perché è puro stato Vue locale, non un componente Field vendor (il follow-up precedente lo aveva scartato per rischio di fragilità su hook di un componente Nova nativo — quel vincolo non si applica più)
- Al click su "Importa", esegue una `Nova.request().post(...)` verso un **nuovo endpoint HTTP dedicato** (`/nova-vendor/<nome-dominio>/...`, pattern identico a `LayerFeatures`), passando `{app_id, selected_ids}` — Nova **non** esegue alcuna azione automatica alla conferma della seconda modale (verificato: `@confirm` di `ActionSelector.vue` chiude semplicemente la modale, l'esecuzione reale è responsabilità del componente)
- Mentre la richiesta è in corso mostra uno spinner; a risposta ricevuta mostra il messaggio/i contatori (o l'errore) **nella stessa modale**, con un bottone "Chiudi" — nessun toast Nova, nessuna chiusura automatica

Il nuovo endpoint backend (PHP, registrato con un proprio `ServiceProvider` dedicato + route `Route::middleware(['nova'])`, mirror esatto del pattern `LayerFeatures`) **ri-verifica autonomamente il gate super-admin** (chiama lo stesso `isGeohubSourceAllowed()` del trait condiviso) ed esegue l'import vero e proprio: crea/aggiorna le `TaxonomyWhere` per gli id selezionati, dispatcha i job di copia geometria via `Bus::batch(...)->then(...)` (invariato dal ciclo precedente), e risponde con un JSON di esito (contatori o errore) che il componente Vue mostra inline.

**Attenzione al boundary di autenticazione**: il middleware `nova` (`config('nova.middleware')`) risolve a `['web', HandleInertiaRequests::class, 'nova:serving']` — **non include** `Authenticate`/`Authorize` di Nova, che vivono solo in `nova.api_middleware` (usato dalle route `nova-api/*`, non da endpoint custom sotto `nova-vendor/*`; verificato in `vendor/laravel/nova/config/nova.php` e `NovaCoreServiceProvider.php`). Lo stesso vale già oggi per `LayerFeatures`. Di conseguenza `isGeohubSourceAllowed(auth()->user())` **non è un controllo aggiuntivo sopra un'autenticazione Nova già garantita dal middleware** — è l'**unico** controllo di autorizzazione su questo boundary (nega correttamente anche `$user === null`, quindi resta funzionalmente sicuro, ma va documentato esplicitamente nel codice/nei test perché un futuro refactoring non lo rimuova scambiandolo per ridondante).

Il campo `BooleanGroup::make('geohub_where_ids', ...)` + il metodo `buildGeohubWhereOptions()` introdotti nel Task 2 del follow-up precedente vengono **rimossi** (codice morto una volta completato questo redesign) — la logica di costruzione delle opzioni (label tradotta + "già importata") viene **riusata**, non riscritta da zero, spostandola in un metodo condiviso richiamato sia da `handle()` (per costruire il payload della seconda modale) sia dal nuovo endpoint (per ri-derivare le stesse righe se necessario a scopo di validazione/label nella risposta).

### Perché

Il meccanismo a singola modale (checkbox dentro la stessa modale del submit) richiede che l'admin interrompa un gesto continuo (click "Run Action" → verifica lista → eventualmente deseleziona → click "Run Action" di nuovo) che l'abitudine rende un click-through singolo nella pratica reale, senza un punto di pausa naturale — non riproducibile in test automatizzati deliberati e cauti, ma reale nell'uso umano quotidiano. Due modali con due conferme esplicite (`Run Action` → apre modale 2 con la lista → un secondo bottone "Importa" separato) introducono la pausa mancante, restando comunque un'unica Action Nova (nessuna azione duplicata nel menu, come richiesto esplicitamente dal dev).

### Requisiti

- [ ] `handle()`/`handleGeohub()`: nessuna modifica alla risoluzione gate/App/query candidati esistente (riusa `isGeohubSourceAllowed()`, `resolveApp()`, `fetchGeohubCandidateWheres()` già nel trait) — cambia solo l'ultimo passo, da "importa e ritorna messaggio" a "costruisci payload e ritorna `Action::modal($nome, $payload)` con **esattamente 2 argomenti**" — `handleGeohub()`, dopo questo redesign, **non esegue più alcuna creazione/aggiornamento**: la sua unica responsabilità diventa gate + risoluzione + costruzione payload
- [ ] Metodo condiviso (nel trait `HasTaxonomyWhereImportHelpers` o accanto) che produce le righe pronte per il rendering: `[{id, label, checked: true}, ...]`, riusando la stessa logica di label (nome tradotto + identifier + "(già importata)") già scritta per `buildGeohubWhereOptions()` nel follow-up precedente — il payload della seconda modale contiene righe già pronte, il componente Vue non fa alcuna chiamata di rete per ottenerle
- [ ] Nuova cartella dedicata sotto `src/Nova/` (es. `src/Nova/Actions/GeohubWhereSelection/`, mirror strutturale di `src/Nova/Fields/LayerFeatures/`): componente Vue (`resources/js/`), `FieldServiceProvider`-equivalente, route `routes/api.php` con `Route::middleware(['nova'])->prefix('nova-vendor/geohub-where-selection')`, registrazione in `WmPackageServiceProvider` (pattern identico alla riga che registra `LayerFeatures\FieldServiceProvider`)
- [ ] Componente Vue: lista checkbox (tutte pre-selezionate), toggle "Seleziona tutte/Deseleziona tutte" (stato locale), bottone "Importa" che si disabilita se nessuna checkbox è selezionata **e anche non appena la richiesta parte** (anti-doppio-click: il bottone resta disabilitato per l'intera durata della POST, non solo sull'assenza di selezione), `Nova.request().post(...)` verso il nuovo endpoint, stato di caricamento (spinner) → risultato inline (messaggio/contatori o errore) → bottone "Chiudi" che emette `close`
- [ ] **Contratto esplicito del metodo condiviso di esecuzione import** (estratto da `handleGeohub()`, unico chiamante reale essendo `handle()` ridotto a sola costruzione payload): firma indicativa `executeGeohubImport(App $app, object $geohubApp, array $selectedIds): array{created:int, updated:int}` (o eccezione/valore sentinella per "nessun id valido") — riceve l'App e la riga GeoHub già risolte (non le ri-risolve da zero), **ri-deriva autonomamente il set autoritativo delle where candidate** (richiamando `fetchGeohubCandidateWheres()`) e lo interseca con `selectedIds` ricevuti dal client, invece di fidarsi ciecamente degli id nel payload POST — un payload alterato con id fuori dal set candidato per quell'app non deve produrre un import fuori scope
- [ ] Nuovo endpoint backend: **ri-verifica indipendentemente** il gate super-admin (stesso metodo condiviso, non un check duplicato scritto ad hoc) — è l'**unico** controllo di autorizzazione su questo boundary HTTP (il middleware `nova` non porta autenticazione Nova, vedi sopra) — prima di qualunque scrittura; riceve `{app_id, selected_ids[]}`; se `selected_ids` è vuoto (o l'intersezione con il set candidato risulta vuota) risponde con errore esplicito (stesso messaggio "Nessuna where selezionata." del ciclo precedente), altrimenti richiama il metodo condiviso sopra e dispatcha `Bus::batch(...)->then(...)` come oggi
- [ ] Rimozione del campo `BooleanGroup`/`dependsOn` e di `buildGeohubWhereOptions()` da `ImportTaxonomyWhere.php` (Task 2 del follow-up precedente) — la logica di costruzione label viene spostata, non duplicata
- [ ] **Verifica live in browser del meccanismo `Action::modal()` prima del merge** (non solo lettura del sorgente vendor): pattern net-new nel package, mai usato altrove — verificare concretamente che la prima modale si chiuda e la seconda si apra con il payload atteso, popolato correttamente, prima di considerare il task chiuso (stesso tipo di verifica già eseguita per il Task 2 del follow-up precedente)
- [ ] Test riscritti per colpire il **nuovo endpoint** invece di chiamare `handleGeohub()` direttamente per verificare il comportamento di import/selezione (danger su selezione vuota, filtro sugli id selezionati, idempotenza, id fuori dal set candidato ignorati) — i test sul comportamento di `handle()`/prima modale (gate, App non collegata, nessun candidato → danger, payload `Action::modal()` costruito correttamente con 2 argomenti) restano invece sull'Action
- [ ] Test sul nuovo endpoint: gate ri-verificato indipendentemente (accesso diretto all'endpoint da parte di un utente non super-admin → 403/danger, anche bypassando l'Action), selezione parziale rispettata, selezione vuota → errore esplicito, id non presenti nel set candidato per l'app ignorati silenziosamente (non causano errore, semplicemente non importati), nessuna creazione/aggiornamento eseguita nel caso "selezione vuota", dispatch del batch di job geometria invariato

### Rischi

- **Pattern net-new nel codebase**: `Action::modal()` non è mai stato usato altrove nel package. Arità critica (2 vs 3 argomenti) verificata leggendo il sorgente vendor installato e riportata come requisito esplicito sopra — resta comunque un rischio residuo di comportamenti specifici alla versione Nova 5.7.6 non coperti dalla sola lettura statica, mitigato dal requisito di verifica live in browser prima del merge (vedi Requisiti)
- **Boundary di autenticazione dell'endpoint più debole di quanto sembri a prima vista**: il middleware `nova` non porta autenticazione Nova (solo `nova.api_middleware`, usato da `nova-api/*`, la porta) — `isGeohubSourceAllowed()` è l'unico controllo reale sul boundary HTTP del nuovo endpoint, non un controllo "in più" sopra un'autenticazione già garantita. Funzionalmente sicuro (nega correttamente utente anonimo), ma va documentato esplicitamente (commento nel controller + test dedicato) perché un refactoring futuro non lo rimuova credendolo ridondante
- **Doppio click su "Importa"**: mitigato lato UI (bottone disabilitato per l'intera durata della richiesta, vedi Requisiti) ma non lato server — due POST concorrenti che superassero comunque la disabilitazione client-side (es. richiesta duplicata da uno script, non dal click reale) potrebbero entrambe superare il lookup idempotente a due passi (nessuna delle due trova ancora il record dell'altra) e creare una collisione. Rischio residuo accettato: la probabilità reale di due richieste concorrenti genuine dallo stesso admin è bassa, e un idempotency-lock lato server è stato valutato non giustificato per un'azione amministrativa a bassa frequenza — da rivalutare se osservato in pratica
- **Finestra TOCTOU ampliata rispetto al ciclo precedente, non rivalutata**: il rischio "id GeoHub scomparsi tra calcolo e submit" era stato accettato nel follow-up precedente assumendo un'esecuzione pressoché immediata. Il design a due modali introduce deliberatamente una pausa (è lo scopo della feature) tra il calcolo dei candidati in `handle()` e il click "Importa", che può durare quanto l'admin impiega a decidere — la finestra è quindi strutturalmente più ampia. Resta comunque accettato: gli id GeoHub sono chiavi primarie stabili, e il metodo condiviso di esecuzione ri-deriva comunque il set candidato aggiornato al momento del submit (vedi Requisiti), quindi un id sparito nel frattempo viene semplicemente escluso, non causa un errore
- **Due punti di verifica del gate super-admin** (uno in `handle()` per la prima modale, uno nel nuovo endpoint per l'esecuzione reale): mitigato riusando lo stesso metodo condiviso `isGeohubSourceAllowed()` in entrambi i punti — il rischio residuo è che un futuro terzo punto di accesso (es. un comando CLI) dimentichi di richiamarlo, stesso rischio già accettato nel follow-up precedente
- **Duplicazione temporanea durante la migrazione**: nella finestra tra la rimozione del `BooleanGroup` e il completamento del nuovo componente Vue, l'azione potrebbe restare in uno stato intermedio non funzionante se i task non vengono eseguiti nell'ordine corretto — mitigato pianificando la rimozione del codice vecchio come task esplicito, dopo che il nuovo flusso è verificato funzionante
- **Reimplementazione UI da zero**: passare da un Field Nova nativo (`BooleanGroup`, con accessibilità/keyboard nav gratuite) a HTML/Vue custom richiede reimplementare manualmente checkbox, focus, e il toggle seleziona/deseleziona tutte — rischio di qualità UI inferiore al componente nativo su dettagli minori (non bloccante, accettato per ottenere il flusso a due modali richiesto)

### Out of scope (di questo follow-up 2)

- Estensione del pattern a due modali a `osmfeatures`/`osm2cai`
- Anteprima mappa/geometria nella seconda modale
- Persistenza della selezione tra un'esecuzione e l'altra
- Redesign visivo oltre il necessario per la funzione (nessun mockup di design fornito, si riusano le classi Nova già verificate disponibili, stesso approccio di oc:7546)

### Moduli toccati (follow-up 2)

Tutti in `wm-package/`:
- `src/Nova/Actions/ImportTaxonomyWhere.php` — `handleGeohub()` ritorna `Action::modal($nome, $payload)` (2 argomenti) invece di eseguire l'import; rimozione campo `BooleanGroup`/`buildGeohubWhereOptions()`
- `src/Nova/Actions/Concerns/HasTaxonomyWhereImportHelpers.php` — nuovo metodo condiviso per costruire le righe del payload (label + "già importata"), riusato da `handle()` e dal nuovo endpoint; metodo di esecuzione import estratto per essere riusabile dal nuovo endpoint
- Nuova cartella `src/Nova/Actions/GeohubWhereSelection/` (nome esatto da confermare in fase di piano): componente Vue, controller, routes, service provider dedicato — mirror di `src/Nova/Fields/LayerFeatures/`
- `src/WmPackageServiceProvider.php` — registrazione del nuovo service provider dedicato
- `tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSourceTest.php` — adattato: verifica solo il comportamento di `handle()` (danger, payload `Action::modal()`), non più l'esecuzione diretta dell'import
- Nuovo file di test per l'endpoint (es. `tests/Feature/GeohubWhereSelectionEndpointTest.php`) — copre gate, selezione parziale/vuota, idempotenza, dispatch batch
- `tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSelectionPanelTest.php` (Task 2 del follow-up precedente) — da rimuovere o riscrivere, dato che il campo che testava viene eliminato
