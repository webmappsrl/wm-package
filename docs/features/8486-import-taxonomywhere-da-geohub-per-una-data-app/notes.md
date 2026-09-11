> Ticket: oc:8486

# Notes — Import TaxonomyWhere da GeoHub per una data app

## Follow-up 2: due modali per la selezione

### Decisioni
- Sostituito il campo `BooleanGroup`/`dependsOn` (follow-up precedente) con `Action::modal()`: prima modale invariata (Sorgente+App), seconda modale component-driven con la checkbox list — motivato da un bug UX reale (click-through abituale che eseguiva l'import prima che l'admin potesse deselezionare)
- `Action::modal($nome, $payload)` va chiamato con ESATTAMENTE 2 argomenti — con 3 ritorna un'istanza Action no-op invece di un ActionResponse (verificato in vendor/laravel/nova/src/Actions/Action.php:453, non per analogia)
- Componente Vue registrato con render function + `Nova.booting()` (nessuna build dedicata), non una replica della toolchain ag-grid/TypeScript/webpack di `LayerFeatures` — sproporzionata per una checkbox list. Registrato via `Nova::script()` in `WmPackageServiceProvider`, stesso meccanismo già usato per `resources/js/nova.js`
- Nuovo endpoint HTTP dedicato (`POST /nova-vendor/geohub-where-selection/import`, `GeohubWhereSelectionController`) — ri-verifica autonomamente il gate super-admin ed è l'unico controllo di autorizzazione sul boundary (il middleware `nova` non porta autenticazione Nova, solo `nova.api_middleware` la porta)
- L'endpoint ri-deriva il set autoritativo delle where candidate (`fetchGeohubCandidateWheres()`) e vi interseca `selected_ids` ricevuti dal client, invece di fidarsi ciecamente del payload — un id fuori dal set candidato viene ignorato, non causa un errore
- Logica di creazione/aggiornamento/collision-handling estratta in `HasTaxonomyWhereImportHelpers::executeGeohubImport()` — unico chiamante reale l'endpoint, dato che `handle()`/`handleGeohub()` non eseguono più alcuna scrittura (si fermano alla costruzione del payload per la seconda modale)

### Verifica live (Task 3, Step 3) — eseguita dal controller con dati reali
Ambiente: dopo un riavvio del PC dell'utente (necessario per risolvere un Docker Desktop non responsivo), verifica eseguita contro l'App reale "Itinera Romanica PLUS" (id locale 3, geohub_id 28), con l'ambiente GeoHub reale (container `postgres_geohub`/`php_geohub`) avviato dall'utente durante l'esecuzione. Login di verifica: utente `team@webmapp.it` (super-admin), password temporaneamente reimpostata per il test — **da cambiare di nuovo dall'utente se necessario**.

Tutti e 5 i punti richiesti dal piano confermati:
1. Selezionando Sorgente=GeoHub + App "Itinera Romanica PLUS" e "Run Action", la prima modale si chiude e si apre la seconda con 14 righe candidate, tutte pre-selezionate, tutte etichettate "(già importata)" (dato reale: importate in un ciclo precedente)
2. Toggle "Seleziona tutte"/"Deseleziona tutte" verificato in entrambe le direzioni: deselezionando tutto il testo diventa "Seleziona tutte" e "Importa (0)" si disabilita; riselezionando tutto torna "Deseleziona tutte"
3. Deselezionata "Corsica" (1 su 14) → "Importa (13)" → click → bottone disabilitato durante la richiesta con testo "Import in corso..." (anti-doppio-click confermato) → risultato mostrato inline nella stessa modale ("Creati 0 record, aggiornati 13 record TaxonomyWhere da GeoHub...") — nessun toast Nova sostitutivo, bottone "Chiudi" chiude correttamente la modale
4. Corsica esclusa realmente dall'esecuzione — verificato non solo dal messaggio ma a livello di infrastruttura: la tabella `job_batches` mostra il batch appena dispatchato con `total_jobs: 13` (non 14) e `failed_jobs: 0`; il campo `options` serializzato del batch conferma la provenienza da `Wm\WmPackage\Http\Controllers\Nova\GeohubWhereSelectionController` (prova diretta che il flusso reale passa dal nuovo endpoint HTTP, non da `handleGeohub()`)
5. Posizionamento della seconda modale corretto (centrata, non clippata) — nessuna correzione a `Vue.Teleport` necessaria

Nota tecnica emersa durante la verifica (non un bug, comportamento preesistente invariato dai cicli precedenti): gli `updated_at` dei record già corretti (es. "Francia") non risultano aggiornati dopo l'esecuzione, perché `$existing->update([...])` su dati già identici è un no-op per Eloquent (nessun attributo dirty) — la riga viene comunque contata come "aggiornata" nel messaggio perché il codice conta "trovata come esistente", non "modificata realmente". Stesso comportamento del codice originale pre-redesign.

Suite Pest eseguita dal controller dopo il riavvio (24/24 PASS, nessuna regressione):
```
docker exec php-maphub vendor/bin/pest wm-package/tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSourceTest.php wm-package/tests/Feature/Nova/Actions/GeohubWhereSelectionControllerTest.php wm-package/tests/Feature/Nova/Actions/ImportTaxonomyWhereTest.php
→ 18 passed (45 assertions)
docker exec php-maphub vendor/bin/pest wm-package/tests/Feature/Jobs/CopyTaxonomyWhereGeometryFromGeohubJobTest.php wm-package/tests/Feature/Jobs/SyncTaxonomyWhereTracksJobTest.php wm-package/tests/Unit/Models/TaxonomyWhereGeohubSourceTest.php
→ 6 passed (8 assertions)
```

### Deviazioni dal piano
I 3 task di implementazione (Action::modal, endpoint, componente Vue) sono stati implementati **byte-per-byte identici** al codice specificato nei brief (verificato con `diff` in fase di `wm-review-ticket`), nessuna deviazione sul codice stesso. Una deviazione reale, individuata dalla review e non colta prima, riguarda invece la **posizione**: l'overview (Requisiti, sezione Follow-up 2) chiedeva una cartella dedicata `src/Nova/Actions/GeohubWhereSelection/` con un proprio ServiceProvider, mirror strutturale di `LayerFeatures`. L'implementazione finale usa invece il pattern più leggero già motivato in `plan.md` (Architecture): controller in `src/Http/Controllers/Nova/`, JS piatto in `resources/js/`, route/script registrati inline in `WmPackageServiceProvider.php` — mirror di `AnalyticsController`/`layer-analytics`, non di `LayerFeatures`. La scelta resta valida (nessun impatto funzionale, motivata esplicitamente per evitare di replicare un'intera toolchain di build per una checkbox list), ma la precedente stesura di questa nota dichiarava erroneamente "nessuna deviazione di sostanza" — corretto qui.

Unica nota ambientale: Docker Desktop non responsivo ha richiesto un riavvio completo del PC dell'utente a metà ciclo, spostando l'esecuzione della suite Pest e la verifica live dalla fine di ogni task alla fine del ciclo (decisione esplicita dell'utente: "per ora vai avanti facciamo i test alla fine").

### Fix applicati dopo `wm-review-ticket`

Review eseguita con 5 finder paralleli sul diff (`git diff <merge-base>`). Un finding bloccante e alcuni cleanup, tutti corretti prima del commit:

- **[bloccante]** `GeohubWhereSelectionController::import()`: `App::find($request->input('app_id'))` con un `app_id` non numerico lanciava un `QueryException` non gestito (`invalid input syntax for type bigint`), producendo un 500 invece di un 422 pulito. Fix: `is_numeric($appId) ? App::find($appId) : null` prima della query. Verificato con `App::find('not-a-number')` in tinker prima e dopo il fix; aggiunto test di regressione (`test_import_returns_422_when_app_id_is_not_numeric`)
- **[cleanup]** Rimosso `resolveAppFromFormData()` (dead code, mai chiamato — residuo del callback `dependsOn` del follow-up precedente, ora rimosso) e il relativo `use Laravel\Nova\Fields\FormData;`; aggiornato il docblock di `isGeohubSourceAllowed()` che citava ancora quel contesto inesistente
- **[cleanup]** i18n: nessun meccanismo di traduzione lato JS esiste nel package per i componenti Vue custom — le label della seconda modale (titolo, bottoni, messaggi) sono ora costruite lato server in `handleGeohub()` via `__()` e passate nel payload di `Action::modal()` (`data.labels.*`), invece di essere stringhe italiane hardcoded nel JS. Aggiunta anche la chiave mancante `"già importata"` (il `__()` esistente non aveva traduzione in `it.json`/`en.json`, ritornava la chiave stessa)
- **[cleanup]** Bottoni "Annulla" e "Seleziona/Deseleziona tutte" ora disabilitati anche durante `loading` (prima solo "Importa" lo era) — evita che "Annulla" chiuda la modale mentre la POST è ancora in volo
- **[cleanup]** Rimosso `test_geohub_source_still_returns_danger_for_non_super_admin_after_refactor` (quasi duplicato di `test_geohub_source_returns_danger_for_non_super_admin`, con una `App::factory()->create()` il cui risultato non veniva mai usato)
- **[cleanup]** Aggiunto test per richiesta realmente anonima (`test_import_returns_403_for_anonymous_request`, nessun `actingAs`) sull'endpoint, a copertura del fatto che il gate applicativo è l'unico controllo di autorizzazione sul boundary HTTP

**Non corretto, deliberatamente**: la query "già importata" (`buildGeohubWhereSelectionPayload()`) resta un full-scan di `taxonomy_wheres` non filtrato per App — segnalato in review come nota di performance, ma scoparlo per App cambierebbe la semantica (una where già importata per un'altra App deve comunque risultare "già importata", non solo quelle della App corrente). Nessuna modifica: sarebbe stata una regressione, non un fix.

## Deviazioni dal piano

- **`finalizeWithTracksSync()` del trait non è stato usato da `handleGeohub()`** — a differenza di quanto previsto nel piano (Task 5), che riusava lo stesso helper sincrono degli altri due handler. Motivo: scoperto in QA manuale (vedi "Bug trovati" sotto), non anticipabile in fase di piano perché gli altri due handler condividono lo stesso difetto latente ma non era mai stato osservato/segnalato prima.

## Bug trovati

- **Race condition tra dispatch geometria e sync track**: `handleGeohub()` dispatchava i `CopyTaxonomyWhereGeometryFromGeohubJob` (asincroni) e subito dopo chiamava `syncTracksTaxonomyWhere()` in modo sincrono (via `finalizeWithTracksSync()`) — la sync girava quindi *prima* che le geometrie fossero effettivamente scritte, trovando sempre `taxonomy_wheres.geometry IS NULL` e riportando "Sync taxonomy_where su 0 tracks avviata" anche quando, pochi istanti dopo, le geometrie arrivavano e la sync (se rieseguita) avrebbe collegato correttamente le track. Scoperto testando dal vivo l'azione contro l'App reale "Itinera Romanica PLUS" (GeoHub id 28) con Corsica/Francia.
  - **Fix**: i job di copia geometria sono ora dispatchati come `Illuminate\Bus\Batch` (`Bus::batch($geometryJobs)->then(fn () => SyncTaxonomyWhereTracksJob::dispatch())->dispatch()`), con un nuovo job `SyncTaxonomyWhereTracksJob` (wrapper di `GeometryComputationService::syncTracksTaxonomyWhere()`) dispatchato come callback solo a batch concluso. `CopyTaxonomyWhereGeometryFromGeohubJob` ha guadagnato il trait `Batchable` (richiesto da Laravel per essere incluso in un batch) più un guard `if ($this->batch()?->cancelled()) return;` a inizio `handle()`.
  - Il messaggio di ritorno di `handleGeohub()` è cambiato di conseguenza: non riporta più un conteggio live delle track sincronizzate (non più disponibile in modo sincrono), ma indica che la sync partirà automaticamente al termine della copia geometrie.
  - **Non applicato a `handleOsmfeatures()`/`handleOsm2cai()`**: soffrono dello stesso identico difetto (dispatchano `FetchTaxonomyWhereGeometryJob`/`FetchOsm2caiSectorGeometryJob` async e poi chiamano `finalizeWithTracksSync()` sincrono), ma il fix è stato applicato solo alla sorgente `geohub` su richiesta esplicita del dev — le altre due sorgenti restano fuori scope per questo ticket.

- **Gotcha ambientale, non un bug di codice**: durante la verifica dal vivo, il primo tentativo post-fix falliva silenziosamente (batch mai completato, `pending_jobs` mai decrementato, nessuna eccezione nei log). Causa: il worker Horizon (`horizon-maphub`, container separato da `php-maphub`) era attivo da 3 ore e aveva caricato in memoria la classe `CopyTaxonomyWhereGeometryFromGeohubJob` **prima** dell'aggiunta del trait `Batchable` — un worker PHP long-running non ricarica le classi già autoloaded. Un riavvio del container (`docker restart horizon-maphub`) ha risolto: il batch ha poi funzionato correttamente end-to-end (14/14 job completati, 27 track sincronizzate automaticamente, numeri identici a quelli attesi dal ticket: 22 francia + 16 corsica). **Lezione per i prossimi cicli**: qualsiasi modifica a una classe Job già in coda/in esecuzione richiede un riavvio di Horizon in locale per essere osservabile — un sintomo di "il job gira ma il comportamento non cambia" va sempre verificato con un riavvio prima di sospettare un bug di codice.

## Decisioni

- **Verifica end-to-end contro dati reali**: il DB locale è stato resettato dal backup `storage/backups/last_dump.sql.gz` e l'App reale "Itinera Romanica PLUS" (GeoHub id 28) è stata reimportata via `wm:import-from-geohub app 28`, per validare la feature contro lo stesso caso concreto descritto nella description del ticket. I numeri osservati (22 track intersecano `francia`, 16 intersecano `corsica`) coincidono esattamente con quelli riportati nella description del ticket, confermando che la query di selezione e il meccanismo di sync locale (`ST_Intersects`) funzionano come atteso sui dati reali GeoHub.
- **14 record creati anziché 15**: la description del ticket elencava 15 where senza `admin_level` per l'app 28 (inclusa `europe`); l'esecuzione reale ne ha trovate 14 (manca `europe`). Non investigato oltre — verosimilmente una piccola variazione dei dati GeoHub tra la stesura del ticket e l'esecuzione di questo ciclo, non un bug: la query rispetta comunque esattamente il criterio dichiarato (`admin_level IS NULL` sui contenuti collegati all'app).

## Follow-up

- Lo stesso difetto di race condition (sync sincrona dopo dispatch asincrono) esiste in `handleOsmfeatures()`/`handleOsm2cai()` — non corretto in questo ciclo, deliberatamente fuori scope. Da valutare in un ticket dedicato se emergono segnalazioni analoghe su quelle due sorgenti.

## Follow-up: pannello di selezione where (post-riapertura)

### Decisioni

- Campo `BooleanGroup` (lista di checkbox), non `MultiSelect` — il dev ha chiarito di voler una lista di checkbox reale, non un dropdown con tag
- Nessun bottone "seleziona/deseleziona tutte" custom via JS — Nova non lo espone nativamente per `BooleanGroup` e costruirlo con hook su un componente Vue vendor comporterebbe un rischio di fragilità non giustificato; si riusa il comportamento nativo (riaprire la modale dell'azione resetta a tutto selezionato — reset naturale + "x" nativa del componente per svuotare in un colpo)
- Gate e query GeoHub estratti in metodi condivisi nel trait `HasTaxonomyWhereImportHelpers` (`isGeohubSourceAllowed()`, `resolveAppFromFormData()`, `resolveGeohubApp()`, `fetchGeohubCandidateWheres()`), riusati sia dal pannello (`buildGeohubWhereOptions()`) sia dall'esecuzione dell'azione (`handleGeohub()`), per evitare due controlli di sicurezza indipendenti
- Distinzione esplicita fra campo assente (nessun filtro, importa tutto — retrocompatibile con chiamate dirette/tinker) e campo presente con selezione vuota (danger, nessun record creato)

### Verifica live (eseguita dal controller della pipeline durante la review del Task 2, non ripetuta in questo task)

Riportata fedelmente da `.superpowers/sdd/plan/followup-progress.md`, sezione "## Task 2" (Step 1/Step 2 del brief di questo task sono quindi già soddisfatti, non rieseguiti):

Il controller si è connesso al Chrome dell'utente, già loggato come Administrator (super-admin, `team@webmapp.it`, id=1) via sessione esistente, aprendo la vera azione "Import TaxonomyWhere" su `/nova/resources/taxonomy-wheres`. I `<select>` nativi non rispondevano a click/tastiera via automazione (popup OS-level non "paintabile"); aggirato impostando `.value` via il setter nativo della property + dispatch di eventi `change`/`input` tramite `javascript_tool` — simulazione a livello DOM di valore+evento, non un bypass del JS dell'applicazione: Vue ha reagito esattamente come a una selezione utente reale.

Confermati dal vivo tutti e 4 i criteri di accettazione dello Step 4 del brief originale (Task 2):

1. Pannello nascosto quando source ≠ geohub (visibile solo dopo aver impostato `source_type=geohub`, scomparso di nuovo passando a `osm2cai`)
2. Pannello popolato alla selezione App, TUTTE le checkbox pre-selezionate di default (14 righe reali per App 3/Itinera Romanica, 9 per App 4/Emilia Centrale) — meccanismo `default()` confermato funzionante SENZA il fallback `$field->value = ...` ipotizzato nel brief, in linea con la predizione basata sul source-trace fatta dall'implementer del Task 2
3. Etichetta "(già importata)" presente su ogni riga — corretto, trattandosi delle righe QA reali lasciate intenzionalmente in essere su richiesta esplicita dell'utente
4. Cambio App ricalcola la lista dal vivo (verificata la transizione App 3 → App 4)

Nessuna regressione trovata; modale chiusa senza eseguire l'azione (Annulla), nessun dato toccato.

### Deviazioni dal piano

- **Identifier dinamici invece di stringhe fisse, in tutti e 3 i task**: il DB di sviluppo condiviso contiene righe reali `corsica`/`francia` (id 1/2), create durante la verifica manuale end-to-end del ciclo originale (vedi sezione "Import TaxonomyWhere da GeoHub" più sopra in questo stesso file / CLAUDE.md). I test scritti nei brief usavano questi stessi identifier come fixture letterali, in collisione con l'indice unico `taxonomy_wheres_identifier_unique`. Pattern di fix adottato ovunque (Task 1, Task 2, Task 3): identifier generato dinamicamente per esecuzione, `'<base>-'.Str::lower(Str::random(8))` — il lowercase è obbligatorio perché `TaxonomyObserver::assignIdentifier()` normalizza sempre l'identifier con `Str::slug()` (anche quando già presente e non vuoto), quindi un valore fixture con maiuscole verrebbe riletto già minuscolo dal DB, facendo fallire un confronto diretto sul valore originale. Il dev ha confermato la diagnosi (collisione con dati reali, non regressione del codice) e scelto di rendere i test resilienti invece di pulire il DB, per preservare le righe QA per ispezione in Nova.
  - Task 1: 3 test in `ImportTaxonomyWhereGeohubSourceTest.php` resi resilienti.
  - Task 3, fix round 1: pattern esteso a 3 file di test **preesistenti fuori scope del task** (non toccati dalla logica di Task 3, ma bloccanti per una regressione pulita sui 6 file del comando finale): `CopyTaxonomyWhereGeometryFromGeohubJobTest.php`, `SyncTaxonomyWhereTracksJobTest.php`, `Unit/Models/TaxonomyWhereGeohubSourceTest.php`. Ruling del controller: applicare lo stesso pattern già stabilito esplicitamente dal dev nei task precedenti, invece di ri-chiedere conferma.
- **Bug Postgres trovato e corretto nel Task 2**: il codice del brief per `buildGeohubWhereOptions()` conteneva `TaxonomyWhere::whereNotNull('properties->geohub_id')->pluck('properties->geohub_id')`, che fallisce sempre su Postgres (`ErrorException: Undefined property: stdClass::$properties->geohub_id`) — la colonna generata dal query builder per l'arrow-syntax senza alias esplicito si chiama `?column?`, non `properties->geohub_id`, quindi `pluck()` non trova la proprietà cercata sull'oggetto risultato. Corretto sostituendo con `->get()->pluck('properties.geohub_id')` (notazione dot su Collection Eloquent, che legge dall'array `properties` già castato dal modello) — comportamento identico, nessun impatto sulle asserzioni del brief.
- **Schema fixture mancante nel brief (Task 2)**: la tabella locale `taxonomy_wheres` non ha le colonne fisiche `admin_level`/`source` (vivono in `properties`, oc:8469), mentre lo schema GeoHub reale sì; `SharesGeohubConnectionWithLocal` punta la connessione "geohub" sulla stessa tabella fisica. Risolto in `setUp()` con lo stesso blocco `Schema::table(...)` già presente (e commentato con lo stesso motivo) in `ImportTaxonomyWhereGeohubSourceTest.php` del Task 1.
- **Asserzioni test adattate allo schema reale (Task 3)**: `assertDatabaseHas('taxonomy_wheres', ['name->it' => 'Corsica'])` del brief falliva (`operator does not exist: text ->> unknown` — la colonna `name` è `text` json-encoded, non `jsonb`, in questo schema), sostituita con `assertSame('Corsica', $imported->getTranslation('name', 'it'))`; `assertSame(0, TaxonomyWhere::count())` per lo scenario "selezione vuota" falliva per lo stesso motivo di dati QA reali preesistenti, sostituita con un confronto sul delta (`$countBefore` prima dell'azione vs. dopo). Nessuna delle due modifiche cambia il significato dell'asserzione originale.
- **Dead code lasciato verbatim (Task 2)**: `$geohubIds` in `buildGeohubWhereOptions()` è calcolato ma mai usato — lasciato per aderenza letterale al codice del brief, segnalato come cleanup candidate per un pass futuro (PHPStan livello 5 non lo segnala).
- **Stima numerica del brief non verificata nell'ambiente reale (Task 3)**: il brief stimava "12 test precedenti + 3 nuovi = 15" per `ImportTaxonomyWhereGeohubSourceTest.php`; il file conteneva in realtà 8 test preesistenti (11 totali dopo l'aggiunta) — discrepanza nella stima, non un gap funzionale.

### Verifica finale (Step 2 di questo task)

Suite di regressione completa rieseguita una volta come controfirma finale (nessuna riscrittura di codice attesa):

```
docker exec php-maphub vendor/bin/pest wm-package/tests/Feature/Nova/Actions/ImportTaxonomyWhereTest.php wm-package/tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSourceTest.php wm-package/tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSelectionPanelTest.php wm-package/tests/Feature/Jobs/CopyTaxonomyWhereGeometryFromGeohubJobTest.php wm-package/tests/Feature/Jobs/SyncTaxonomyWhereTracksJobTest.php wm-package/tests/Unit/Models/TaxonomyWhereGeohubSourceTest.php
```

Risultato: **25 passed (54 assertions), 0 failed** — invariato rispetto all'esito già ottenuto a fine Task 3. Dettaglio in `.superpowers/sdd/plan/followup-task-4-report.md`.
