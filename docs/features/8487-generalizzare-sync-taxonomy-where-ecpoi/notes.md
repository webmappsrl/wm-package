> Ticket: oc:8487

# Notes — Generalizzare Sincronizza Taxonomy Where ed estenderla agli EcPoi

## Divergenze dal piano, task per task

### Task 3: file di test extra non pianificato

Il piano elencava solo 3 file per questo task (`EcTrackService.php`, `EcTrack.php`,
`tests/Feature/Nova/EcTrackRegenerateTaxonomyWhereActionTest.php`). L'implementazione ha
dovuto toccare anche un quarto file non pianificato: `tests/Unit/Services/EcTrackService/UpdateDataChainTest.php`,
che asserisce esplicitamente la vecchia classe `UpdateModelWithGeometryTaxonomyWhere` nella
chain — lasciarlo intatto avrebbe fatto regredire un test esistente e verde. La modifica è un
mirror 1:1 della sostituzione già fatta in `EcTrackService.php` (stesso cambio di classe, stessa
posizione nell'array), nessuna logica nuova introdotta. Verificato in review: necessario e
corretto.

### Task 5: test comportamentale mancante nel primo giro

Il piano specificava un test con `Bus::fake()` + `Bus::assertDispatched(SyncTaxonomyWhereJob::class)`
per verificare che l'azione dispatchasse davvero il job. Il primo giro di implementazione ha
consegnato un test diverso, che verificava solo le proprietà statiche dell'azione (`name`,
`standalone`, `onlyOnIndex`) senza mai chiamare `handle()` — un test che sarebbe passato anche
con un `handle()` vuoto. La causa: `Bus::fake()` falliva con un errore di bootstrap
apparentemente ambientale, diagnosticato (erroneamente) come un problema sistemico
dall'implementer, che ha aggiunto una modifica non richiesta a `phpunit.xml.dist`
(`QUEUE_CONNECTION=sync`) senza che risolvesse nulla. La causa reale era una dichiarazione
`uses(Tests\TestCase::class, DatabaseTransactions::class)` mancante nel test (trappola nota,
vedi `wm-package/.claude/rules/test.md`) — già risolta correttamente nel test del Task 3.
Corretto in un giro di fix: `phpunit.xml.dist` ripristinato, test riscritto secondo la
specifica del piano con la dichiarazione `uses()` corretta e l'accesso al messaggio via
`$result['message']` (proprietà privata su `ActionResponse`, raggiungibile solo tramite
`ArrayAccess` — un piccolo refuso nel codice di esempio del piano stesso, non
dell'implementazione).

### Task 7: migration GIST rimossa dopo verifica sui numeri reali (post-merge, prima del deploy)

Il Task 7 (indice GIST difensivo su `taxonomy_wheres.geometry`) è stato implementato come da
piano e committato, ma **rimosso** subito dopo con un commit dedicato, prima dell'apertura
della PR, a seguito di una verifica esplicita richiesta dal developer sui numeri reali di scala:

- `taxonomy_wheres` su GeoHub: **8.101 righe totali**, ma solo **71** con `admin_level IS NULL`
  — l'unico sottoinsieme che `handleGeohub()` importa davvero (i territori non coperti da
  OSMFeatures, collegati ai contenuti di un'App). Le altre 8.030 non entrano mai in questa
  installazione con il meccanismo di import attuale.
- `ec_tracks`/`ec_pois` locali: 86 / 302, invariati anche escludendo le app 26/11/32 (non
  presenti in questo DB di sviluppo — solo le app 1, 2, 3 hanno contenuti EC).
- Anche al tetto realistico (71 `taxonomy_wheres` × 388 contenuti EC ≈ 27.500 confronti
  `ST_Intersects`), il volume resta ordini di grandezza sotto la soglia a cui un indice GIST
  inizia a incidere sulle performance (tipicamente quando il lato indicizzato è nell'ordine
  delle migliaia di righe). Confermata quindi, con dati più solidi del solo DB locale (4 righe),
  la conclusione già emersa in fase di challenge: **l'indice non è necessario**.

Rimossa la migration stub (`database/migrations/zz_2026_09_15_000001_add_gist_index_to_taxonomy_wheres_table.php.stub`)
con un commit `refactor(oc:8487)` dedicato. Requisito rimosso da questo ciclo — se in futuro la
copertura `taxonomy_wheres` dovesse crescere di ordini di grandezza (es. import a livello di
comune invece che regione/provincia), andrà rivalutato da capo con i numeri di quel momento, non
riproposto automaticamente da questa nota.

## Decisioni prese in corso di pianificazione (non divergenze di esecuzione)

- **Task 4, scope ampliato durante `write-plan`**: in fase di scrittura del piano è emerso che
  esisteva già un meccanismo "nuove where → risincronizza i contenuti esistenti"
  (`SyncTaxonomyWhereTracksJob` + `HasTaxonomyWhereImportHelpers::finalizeWithTracksSync()`/`executeGeohubImport()`),
  non citato nell'overview iniziale. Generalizzato a `EcPoi` nello stesso task invece di
  lasciarlo hardcoded solo su `EcTrack` — deciso e recepito nel piano prima dell'esecuzione, con
  conferma esplicita del developer, quindi non una divergenza dell'implementazione dal piano.
- **Task 8, setup ambientale**: l'implementer ha dovuto installare il `vendor/` proprio di
  `wm-package` (mai esistito prima in questo ciclo di lavoro) per eseguire
  `tests/Feature/Import/ImportAppJobConfigRefreshBatchesTest.php`, che dipende dal bootstrap
  Testbench nativo del package (`Wm\WmPackage\Tests\TestCase`), diverso dal bootstrap consumer
  (`Tests\TestCase`) usato per il resto dei test di questo ciclo. Setup ambientale (composer
  install con auth.json temporaneo per il registry privato di Nova + pin temporaneo di
  `laravel/nova`, entrambi ripristinati; nuovo ruolo/database Postgres `wm_package` sul
  container condiviso), non codice — `composer.json` invariato, nessuna credenziale lasciata su
  disco (verificato). Non è una divergenza di codice dal piano, solo un prerequisito operativo
  scoperto durante l'esecuzione.

## Bug trovati

- Nessun bug introdotto da questa feature. Un bug **pre-esistente e fuori scope** è stato trovato durante il Task 3 in `ExecuteEcTrackDataChainAction::getTracksFromModel()`: `config('wm-package.ec_track_model')` risolve a `App\Models\EcTrack`, ma le risorse Nova risolvono sempre a `Wm\WmPackage\Models\EcTrack` direttamente (nessun override in questo consumer) — il controllo `instanceof` fallisce, rendendo ogni azione Nova a catena su EcTrack (incluse quelle già esistenti prima di questo ticket) un no-op quando eseguita su righe selezionate direttamente in questa installazione Maphub. Confermato reale, confermato non causato da questa modifica (il file non è mai toccato in questo diff). **Da aprire come ticket separato.**

## Decisioni

### Rischio critico: sync bulk automatico può azzerare dati esistenti (trovato in review finale whole-branch)

La generalizzazione del sync (Task 1: `UPDATE` incondizionato; Task 4: bulk job copre sia EcTrack sia EcPoi; Task 5: azione Nova che lo lancia; Task 8: hook automatico ad ogni import GeoHub, senza scoping per app) ha una conseguenza non quantificata nell'overview originale: se la copertura locale di `taxonomy_wheres` è insufficiente per l'area geografica di un contenuto, il sync bulk **azzera** (sovrascrive a `{}`) qualsiasi `taxonomy_where` già presente su quel contenuto, anche se calcolata in precedenza da un meccanismo diverso (es. il vecchio job via API OSMFeatures, che copriva l'Italia).

**Misurato sul DB di sviluppo Maphub in fase di review** (query di sola lettura): la tabella `taxonomy_wheres` locale conteneva solo 4 poligoni (Corsica, Francia, 2 in Sardegna) — nessuno copre l'Italia continentale. Su quel DB, 93 `EcPoi` su 93 con `taxonomy_where` oggi popolato (dati OSMFeatures ricchi: regione + comune, 5 lingue) sarebbero stati ridotti a `{}` da un lancio del sync bulk, perché nessuno dei 4 poligoni locali li copre. Non è un bug di codice — il meccanismo fa esattamente quello richiesto dalla spec ("un solo scrittore, un formato unico, sovrascrittura sempre") — ma è una conseguenza di **sequencing del deploy** non quantificata quando quella decisione era stata presa.

**Decisione del developer (2026-09-16, dopo aver visto la misura sopra)**: gestire il rischio con **sequencing manuale**, non con una modifica di codice. Prima di lanciare il sync bulk (sia manualmente via la nuova azione Nova, sia lasciandolo scattare automaticamente tramite l'hook sull'import — Task 8) su una data app, occorre **prima importare una copertura `taxonomy_wheres` sufficiente per l'area geografica di quell'app** (percorso oc:8486, import where da GeoHub). Questo vale sia per il deploy iniziale di questo ticket sia per ogni nuova app importata in futuro.

**Azione richiesta al deploy, da aggiungere al runbook di release** (accanto al riavvio di Horizon già noto, vedi overview.md → Rischi):
1. Per ogni app Maphub esistente con contenuti EC già `taxonomy_where`-popolati: verificare che `taxonomy_wheres` copra l'area geografica di quell'app (via oc:8486) **prima** di lanciare/lasciare scattare il primo sync bulk post-deploy.
2. Per ogni nuovo import futuro: l'ordine corretto è sempre "import where sufficienti → poi import/risync dei contenuti EC", mai il contrario.
3. Nessuna modifica di codice pianificata per questo rischio in questo ciclo — resta un rischio operativo tracciato, non eliminato dal codice.

### Ri-caratterizzazione: doppio dispatch di `SyncTaxonomyWhereJob` per import (trovato in review finale)

Il Task 8 aggancia `SyncTaxonomyWhereJob::dispatch()` sia al completamento del batch `ec_poi` sia al completamento del batch `ec_track` — un import GeoHub tipico dispatcha entrambi i batch, quindi il job bulk (che fa un `UPDATE` su **tutta la tabella**, non solo sui contenuti appena importati, e su **tutte le app**, non solo quella importata) viene eseguito due volte per ogni import, invece di una. La ledger di implementazione lo aveva caratterizzato come "lavoro duplicato sul contenuto importato" — la review finale ha chiarito che è più correttamente una **riscrittura completa duplicata su ogni app dell'installazione**, non solo sui dati dell'app importata.

Non è un bug di correttezza (l'operazione è idempotente), ma unito al rischio sopra (sovrascrittura di dati non coperti) significa che ogni import esegue quella riscrittura **due volte** invece di una. **Non risolto in questo ciclo** — follow-up naturale: aggiungere `ShouldBeUnique`/`uniqueFor()` a `SyncTaxonomyWhereJob` (mirror del suo sibling `UpdateAppConfigJob`, che già lo fa nello stesso metodo `ImportAppJob::attachBatchCompletionCallback()`), o valutare uno scoping per `app_id`/id importati invece del bulk incondizionato. **Da aprire come ticket separato.**

### Limite noto: `admin_level` non numerico interrompe l'intero UPDATE (pre-esistente, blast radius cresciuto)

`GeometryComputationService::syncTaxonomyWhere()` fa il cast `(tw.properties->>'admin_level')::int` — se una `taxonomy_where` ha un `admin_level` non numerico (stringa vuota, testo), l'intero `UPDATE` fallisce con eccezione Postgres. Questo comportamento esisteva già nel metodo originale (solo `EcTrack`, solo azione manuale) e non è stato introdotto da questo ticket — ma il suo raggio d'azione è cresciuto: ora il metodo gira automaticamente ad ogni import (Task 8) invece che solo su click esplicito di un admin. **Non risolto in questo ciclo** (sarebbe scope creep) — un fix minimale futuro sarebbe `NULLIF(tw.properties->>'admin_level', '')::int` o un guard regex.

## Follow-up

- Aprire ticket separato per il bug pre-esistente `ExecuteEcTrackDataChainAction::getTracksFromModel()` (vedi "Bug trovati").
- Aprire ticket separato per lo scoping/dedup di `SyncTaxonomyWhereJob` (vedi "Ri-caratterizzazione" sopra).
- Considerare, in un ciclo futuro, un guard più robusto su `admin_level` non numerico nel metodo SQL.
- Test opzionale non incluso in questo ciclo: `EcPoiService::updateDataChain()` non ha un test dedicato che verifichi il wiring del nuovo job nella chain (il suo gemello `EcTrackService` ce l'ha, aggiunto nel Task 3) — il Task 2 originale richiedeva solo una verifica via `grep`. Aggiunto in questo giro di fix finale (vedi commit corrispondente).
- Estrarre in un trait comune la parte boilerplate condivisa da `SyncModelTaxonomyWhereJob`/`SyncTaxonomyWhereJob` (`$tries`, `$backoff`, `failed()`) — segnalato in review formale (`wm-skills:wm-review-ticket`), non applicato in questo ciclo (cleanup minore, nessun impatto funzionale).
- Estrarre l'helper di test `createCorsicaTaxonomyWhere()` (duplicato fra 3 file di test) in un supporto Pest condiviso — segnalato in review formale, non applicato in questo ciclo.

## Fix applicati dopo la review formale (`wm-skills:wm-review-ticket`)

La review formale su oc:8487 (5 finder paralleli) non ha trovato bug bloccanti. Applicati i seguenti fix cleanup, verificati con 33/33 test passanti dopo l'applicazione:

- **Allineamento a `config('wm-package.ec_poi_model', EcPoi::class)`**: `SyncTaxonomyWhereJob::handle()` e `HasTaxonomyWhereImportHelpers::finalizeWithEcSync()` chiamavano `EcPoi::class` direttamente, mentre il codice `EcTrack` accanto usa `config('wm-package.ec_track_model', EcTrack::class)`. Verificato che `ec_poi_model` è un pattern già usato in almeno 8 punti del package (`Nova/Layer.php`, `Imports/EcPoiFromSpreadsheet.php`, `Jobs/Layer/SyncAutoLayerAfterPoiTaxonomyChangeJob.php`, `Services/Models/LayerService.php`, ecc.) pur non essendo mai stata registrata come chiave in `config/wm-package.php` — non contraddice la decisione oc:8043 (quella riguardava la registrazione della chiave, non l'uso del pattern nei call site). Allineato per coerenza; nessun cambio di comportamento (il default resta `EcPoi::class`).
- **Test scoped-per-id mancante su EcTrack**: il requisito del ticket copriva esplicitamente bulk+scoped × EcTrack+EcPoi, ma esisteva solo lo scoped-per-id per EcPoi (i test EcTrack esistenti usano `Bus::fake()`, verificano solo il wiring). Aggiunto `it('scopes the sync to a single EcTrack id without touching other rows', ...)` in `GeometryComputationServiceTaxonomyWhereTest.php`, con geometrie PostGIS reali.
- **Commento obsoleto** in `EcPoiService.php:27` ("the media model", relitto di copia-incolla) corretto in "the poi model".
- **Docblock impreciso** in `HasTaxonomyWhereImportHelpers::finalizeWithEcSync()` ("il contatore" singolare quando il metodo appende due contatori) corretto in "i contatori".
