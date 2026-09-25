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

### Task 9: punto di test in collisione con dati reali del DB condiviso

Il piano usava il punto `[9.05, 42.05]` (lo stesso "Corsica" degli altri test del file) anche per il
test upgrade-only, assumendo che senza una `TaxonomyWhere` creata dal test la subquery non trovasse
nulla. Verificato in esecuzione: quel punto **interseca where reali già presenti nel DB di sviluppo
condiviso** (id 1 "Corsica", id 2 "Francia" — dati di produzione, non dati del test), quindi il
sync trovava comunque un match e il test verificava il ramo sbagliato (falliva per il motivo
sbagliato). Cambiato il punto a `[0.0, 0.0]` (Golfo di Guinea), verificato via query diretta
(`ST_Intersects` → 0 righe) prima di usarlo. Stesso punto riusato nel nuovo test di fallback del
Task 10 per lo stesso motivo.

### Task 10: stile del file di test diverso da quello assunto dal piano

Il piano assumeva lo stile Pest funzionale (`it(...)`, `uses(TestCase::class, ...)`) per
`tests/Feature/Jobs/SyncModelTaxonomyWhereJobTest.php`, come negli altri file toccati da questo
ciclo. Il file esistente usa invece lo stile PHPUnit a classe (`class ... extends TestCase`,
metodi `test_*`, `$this->assertX`). Adeguati i nuovi test allo stile reale del file invece di
introdurre un secondo stile nello stesso file.

Il piano citava anche `tests/Feature/Jobs/SyncTaxonomyWhereTracksJobTest.php` per la verifica di
non-regressione del path bulk (Task 9, Step 5): il file esistente si chiama
`SyncTaxonomyWhereJobTest.php` (rinominato insieme al job in un Task precedente, il piano non era
stato aggiornato). Eseguito il file corretto.

Un'asserzione del piano sull'ordine esatto delle chiavi (`assertSame(['name', 'admin_level',
'source'], array_keys(...))`) falliva per un motivo non legato al comportamento: Postgres non
garantisce l'ordine delle chiavi in un `jsonb`. Sostituita con `assertEqualsCanonicalizing`
(stesso insieme di chiavi, ordine ignorato) — il comportamento verificato resta identico,
cambia solo la rigidità dell'assert.

**Scoperta non pianificata, verificata come pre-esistente**: durante la verifica di
non-regressione (Task 9, Step 5 esteso anche al wiring Task 3) è emerso che
`tests/Unit/Services/EcTrackService/UpdateDataChainTest.php::test_update_data_chain_dispatches_at_least_one_job`
fallisce (`Bus::assertChained` non trova la catena attesa). Verificato con `git stash` che fallisce
**identicamente anche senza le modifiche di questo giro** (Task 9/10) — pre-esistente nel codice
già committato dei Task 1-8, non causato da questo lavoro. Non risolto in questo ciclo (fuori
scope): **da aprire come ticket separato**, si aggiunge a
`ExecuteEcTrackDataChainAction::getTracksFromModel()` già segnalato come bug pre-esistente nel
Task 3.

PHPStan (`composer analyse`, livello 4): il pacchetto ha 993 errori pre-esistenti (verificato,
nessuna baseline li coderna in questo file). La riga aggiunta in `SyncModelTaxonomyWhereJob.php`
(`$this->model->id` nel nuovo `DB::statement`) genera un ulteriore "Access to an undefined property
GeometryModel::$id" — stesso identico pattern già presente alla riga originale del file (267
occorrenze di questo pattern nell'intero pacchetto, sistemico, non introdotto da questo diff). Non
trattato come blocco: nessuna baseline dedicata esiste per questo file, il pattern è pre-esistente
e pervasivo nel codebase.

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

- Nessun bug introdotto da questa feature. Un bug **pre-esistente e fuori scope** è stato trovato durante il Task 3 in `ExecuteEcTrackDataChainAction::getTracksFromModel()`: `config('wm-package.ec_track_model')` risolve a `App\Models\EcTrack`, ma le risorse Nova risolvono sempre a `Wm\WmPackage\Models\EcTrack` direttamente (nessun override in questo consumer) — il controllo `instanceof` fallisce, rendendo ogni azione Nova a catena su EcTrack (incluse quelle già esistenti prima di questo ticket) un no-op quando eseguita su righe selezionate direttamente in questa installazione Maphub. Confermato reale, confermato non causato da questa modifica (il file non è mai toccato in questo diff). Confermato di nuovo dal vivo durante la verifica manuale del 2026-09-23 (vedi sopra). **oc:8633.**

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

## Decisioni — ripresa 2026-09-23 (fallback OSMFeatures)

Decisioni prese in reverse-interaction con il developer, prima di scrivere il piano:

- **Trigger**: caso reale, non ipotetico — molti shard/app non hanno ancora la copertura locale di `taxonomy_wheres` (oc:8486 in corso). Un `EcTrack`/`EcPoi` creato o modificato nel frattempo passa dal path automatico scoped-per-record e resta senza `taxonomy_where`, dove il vecchio meccanismo via API l'avrebbe trovata. Confermato dal developer, non discusso in una call di team reperibile (una call del 2026-09-21 08:27 risultava positiva al full-text su "taxonomy"/"fallback" ma è risultata illeggibile per limite tecnico dello strumento di ricerca trascrizioni — verificato con un test decisivo dall'agente `wm-transcript-research`, non un semplice "non trovato").
- **Protezione contro il riazzeramento** (opzioni valutate: A - upgrade-only sulla SQL; B - marcatura esplicita `source: osmfeatures_fallback` + condizione SQL; C - nessuna modifica alla SQL): scelta iniziale **A** applicata **globalmente** (upgrade-only sempre, su tutti i path), per semplicità — nessuno stato/marcatore aggiuntivo da mantenere. Il developer ha inizialmente frainteso A come sostitutiva della chiamata di fallback stessa (non lo è: A protegge solo da un riazzeramento successivo, la chiamata a OSMFeatures per un record nuovo è un secondo step indipendente, sempre necessario). **Superata subito dopo l'implementazione**: vedi "Revisione del comportamento upgrade-only" più sotto — il developer ha chiarito che l'upgrade-only deve valere solo per i path bulk, non per il path scoped-per-record, che deve invece azzerare se, dopo aver provato anche l'API, non trova comunque nulla.
- **Path bulk esplicitamente escluso**: il fallback vive solo nel path automatico per singolo record (`SyncModelTaxonomyWhereJob`). Il path bulk (`SyncTaxonomyWhereJob`, azione Nova, hook import) non lo riceve — per scelta esplicita del developer, non per vincolo tecnico. Conseguenza nota e accettata: i contenuti che arrivano da un import GeoHub (che passano solo dal path bulk, mai da quello per singolo record, perché `persistQuietly()` disabilita gli observer) **non** beneficiano di questo fallback. Resta valida per loro solo la mitigazione operativa già decisa il 2026-09-16 (sequencing manuale, vedi sopra).
- **Gestione errori**: nessun `try/catch` attorno alla chiamata `OsmfeaturesClient` — si lascia propagare l'eccezione e si sfruttano i retry già esistenti del job (`tries=3`, `backoff=60`) e il `failed()` già presente.
- **Formato**: il risultato del fallback viene normalizzato al formato SQL unificato (`{name: {...}, admin_level, source}`) invece di scrivere la forma grezza restituita dall'API (come fa oggi il vecchio job per gli UGC) — scelta per evitare due formati permanenti diversi nello stesso campo.
- **Test**: solo con `Http::fake()`/mock, nessuna chiamata reale in CI. Una verifica su app reale con shard incompleto (Itinera Romanica) resta a carico del developer, fuori da questo ciclo di test automatici.

## Review formale (`wm-skills:wm-review-ticket`) su Task 9/10 — 2 bug bloccanti trovati e corretti

5 finder paralleli sul diff non committato. Due convergenze indipendenti su problemi reali:

### Bug 1: `TypeError` se il modello non ha geojson valido

`SyncModelTaxonomyWhereJob` passava `$this->model->getGeojson()` (tipizzato `?array`) direttamente a
`OsmfeaturesClient::getWheresByGeojson(array $geojson)` (non nullable). Verificato che la colonna
`geometry` di `ec_pois`/`ec_tracks` è `NOT NULL` a schema (test diretto: un insert senza geometry
fallisce con violazione di constraint) — quindi lo scenario è meno probabile di quanto sembrasse dal
solo codice, ma resta possibile per altri motivi (`GeoJsonService::getModelAsGeojson()` controlla
anche `empty($model->geometry)`, non solo `is_null`). Corretto con un early-return esplicito prima
della chiamata al client. Test: sottoclasse anonima di `EcPoi` con `getGeojson()` che ritorna `null`
(un mock Mockery non funziona qui, perché `get_class($this->model)` finirebbe dentro `new $model` in
`syncTaxonomyWhere()` — serve una vera sottoclasse, con `getTable()` fissato esplicitamente perché
Eloquent deriva il nome tabella dal basename della classe, e per una classe anonima produce un nome
non valido).

### Bug 2: scrittura finale del fallback incondizionata (TOCTOU)

La scrittura finale (`DB::statement` dopo la chiamata OSMFeatures) non aveva alcuna guardia: se un
altro processo scriveva una `taxonomy_where` reale sulla stessa riga nella finestra fra la lettura
iniziale e la fine della chiamata HTTP (scenario non ipotetico: oc:8486 è "in corso" mentre questo
path gira in produzione), il job la sovrascriveva comunque col dato OSM stantio — lo stesso downgrade
silenzioso che questo lavoro doveva eliminare, riaperto per questa singola scrittura. Corretto
aggiungendo alla UPDATE finale la condizione `WHERE ... AND (properties->'taxonomy_where' IS NULL OR
properties->'taxonomy_where' = '{}'::jsonb)`. Test: mock di `OsmfeaturesClient` che, come side effect
della chiamata mockata, scrive una where "concorrente" sulla riga prima di ritornare il proprio
risultato — verificato che la where concorrente sopravvive e il dato OSM non viene scritto.

Cleanup segnalati, non applicati in questo ciclo (non bloccanti): logica SQL di scrittura duplicata
fra job e service invece di condivisa; nome tabella interpolato nel job senza la stessa validazione
regex del service; `fresh()` potrebbe tornare `null` in una finestra di cancellazione concorrente
(edge case, stesso esito finale con un messaggio meno chiaro); mapping produce un'entry con `name: []`
se una feature OSM ha `admin_level` ma nessun tag `name*`; fixture "Corsica" duplicata nei nuovi test
invece di riusare l'helper del file gemello; nessun PHPDoc su `syncTaxonomyWhere()` che documenti il
comportamento upgrade-only (superato dal punto successivo, ora c'è un PHPDoc sul parametro).

## Revisione del comportamento upgrade-only, dopo la review formale

La review aveva anche sollevato un punto non-bug ma "da decidere col developer": l'upgrade-only del
Task 9 era applicato al metodo condiviso `syncTaxonomyWhere()`, quindi valeva **anche per il path
bulk**, dove prima un resync manuale azzerava correttamente `taxonomy_where` su record la cui
copertura locale era stata rimossa/spostata — comportamento "auto-correttivo" perso silenziosamente.

Posta la domanda al developer, la risposta ha chiarito un requisito più ampio, non solo sul path
bulk: **il comportamento upgrade-only "sempre" era sbagliato per il path scoped-per-record**, non
solo per il bulk. Il comportamento voluto:

> "Se creo un nuovo ec calcola le taxonomy_where con metodo da db, se non trova nulla prova con api,
> se non trova nulla rimangono vuote le tassonomie. Se aggiorno un ec e la chain ricalcola le
> tassonomie, a prescindere se già presenti o meno quello che deve succedere è la stessa cosa. Prova
> a cercarle tramite db, se non le trova le prova tramite api ma se alla fine di tutto non trova
> nulla le tassonomie vengono rimosse. Non si preservano a prescindere."

Cioè: per il path automatico (creazione o aggiornamento che fa ripartire la chain), il risultato deve
essere sempre "quello vero adesso" — DB, poi API, poi vuoto se nessuno dei due trova nulla — **mai**
un valore vecchio preservato solo perché non si è trovato niente di nuovo. L'upgrade-only resta
valido, ma **solo per i path bulk/resync massivi** (che non hanno un fallback via API a compensare
l'assenza di copertura locale, e per cui la protezione contro il rischio CRITICO resta necessaria).

**Redesign applicato**:

- `GeometryComputationService::syncTaxonomyWhere()` guadagna `bool $preserveOnNoMatch = false`
  (nuovo parametro, non un secondo metodo): il default torna al comportamento originale (azzera se
  non trova match), `true` attiva l'upgrade-only. Scelto un parametro esplicito per-chiamata invece
  di due metodi separati per evitare di duplicare tutta la UPDATE SQL per una sola riga di
  differenza (il `COALESCE` finale).
- `SyncTaxonomyWhereJob` (bulk) e `HasTaxonomyWhereImportHelpers::finalizeWithEcSync()` (resync
  bulk su nuove where) passano esplicitamente `preserveOnNoMatch: true` — sono gli unici due punti
  che lo richiedono, entrambi senza fallback via API.
- `SyncModelTaxonomyWhereJob::handle()` non legge più `fresh()->properties['taxonomy_where']` per
  decidere se tentare il fallback: usa direttamente il conteggio ritornato da `syncTaxonomyWhere()`
  (0 o 1 per una chiamata scoped) — con `preserveOnNoMatch` di default a `false`, quel conteggio
  riflette sempre un match trovato *in questo giro*, non un valore preservato da prima. Se il
  conteggio è 0, prova l'API; se anche l'API non trova nulla, la UPDATE finale scrive comunque
  (azzerando esplicitamente un eventuale valore precedente) — nessun early-return silenzioso.
- **Trovato e corretto in corsa un bug di serializzazione**: `json_encode($mapped)` con `$mapped`
  un array PHP vuoto produce la stringa `"[]"` (array JSON), non `"{}"` (oggetto JSON) — PHP non
  distingue le due cose per un array vuoto. Scrivere `"[]"` dentro `properties.taxonomy_where`
  romperebbe ogni consumer che si aspetta un oggetto (`getOrderedTaxonomyWheres()`, la stringa
  searchable Elasticsearch, l'export). Corretto con un cast esplicito `(object) $mapped` prima di
  `json_encode()`: `json_encode((object) [])` produce correttamente `"{}"`.
- Nuovo test che riproduce esattamente lo scenario descritto dal developer: un POI con una
  `taxonomy_where` già popolata (da un giro precedente) viene ricalcolato, non trova nulla né in
  locale né via API — il campo deve azzerarsi, non restare con il valore vecchio. Verificato
  RED→GREEN.
- Aggiunti anche due test di servizio speculari (`preserveOnNoMatch: true` preserva,
  `preserveOnNoMatch: false`/default azzera) per fissare il contratto del nuovo parametro a
  livello di `GeometryComputationService`, non solo del job.

Rieseguita l'intera suite di non-regressione dopo il redesign (20 test su job/service/azioni Nova
coinvolti): tutti verdi, incluso il solo test pre-esistente e non correlato già segnalato sopra.

## Seconda review formale, dopo il redesign — nessun bloccante, cleanup risolti

Rilanciata `wm-skills:wm-review-ticket` sul diff dopo il redesign (5 finder paralleli). Esito:
**nessun finding bloccante** su nessuno dei 5 assi — call site verificati con grep (nessuno
dimenticato), guardia TOCTOU confermata atomica (non un vero check-poi-scrivi separato: `WHERE` e
`SET` sono nello stesso statement Postgres), narrazione di questo stesso file riverificata fedele
al codice reale (un finder ha rieseguito davvero i 20 test, non solo letto le affermazioni).

Cleanup segnalati e risolti in questo stesso giro (non erano bloccanti, ma il developer ha chiesto
di risolverli comunque):

- **`preserveOnNoMatch` reso strutturale invece che solo per convenzione** (suggerimento del
  finder "altitude"): il parametro è ora `?bool $preserveOnNoMatch = null` e si deriva da
  `$modelId` quando non passato esplicitamente (`$modelId === null` → bulk → `true`; altrimenti →
  scoped → `false`). Un futuro terzo chiamante bulk che dimentica il parametro resta comunque
  protetto dal rischio CRITICO. I due chiamanti bulk esistenti continuano a passarlo esplicitamente
  (nessun cambio di comportamento per loro, solo per un ipotetico futuro chiamante disattento).
  Nuovo test: chiamata bulk (`$modelId` non passato) senza `preserveOnNoMatch` esplicito preserva
  comunque un valore esistente.
- **Scrittura SQL duplicata fra job e service, risolta**: estratto
  `GeometryComputationService::writeTaxonomyWhereIfEmpty(GeometryModel $model, array $mapped): void`
  — include la stessa validazione del nome tabella (`preg_match`) già presente in
  `syncTaxonomyWhere()`, la guardia anti-TOCTOU e il cast a oggetto per la serializzazione. Il job
  ora la richiama invece di reimplementare la query. Risolve nello stesso colpo anche il cleanup
  "nome tabella non validato nel job".
- **Stringa magica `'osmfeatures'` centralizzata**: nuova costante pubblica
  `SyncModelTaxonomyWhereJob::SOURCE_OSMFEATURES`, usata nel mapping e nell'assert di test che
  verifica il `source` scritto (le altre due occorrenze della stringa nei test sono dati di
  fixture che simulano un valore scritto da un altro processo, non l'output del job — lasciate
  come letterali).
- **`handle()` scomposto**: il mapping da formato OSMFeatures a formato unificato è ora un metodo
  privato dedicato (`mapOsmfeaturesWheres()`), testabile/leggibile isolatamente invece che inline
  dentro `handle()`.
- **Edge case `name: []` corretto**: `mapOsmfeaturesWheres()` ora scarta un'entry priva di
  qualunque traduzione del nome (solo `admin_level`, nessun tag `name*`) invece di scrivere
  un'etichetta vuota. Nuovo test: una feature OSM con solo `admin_level` non produce alcuna entry.
- **Fixture "Corsica" duplicata nel file del job, consolidata**: estratto un helper privato
  `createCorsicaTaxonomyWhere()` nello stesso file (le due occorrenze diventano una chiamata),
  sullo stesso modello dell'helper già presente nel file gemello del service. La duplicazione
  *fra* i due file (stile Pest funzionale vs PHPUnit a classe) resta: unificarla richiederebbe un
  trait/helper condiviso fra due stili di test diversi, costo giudicato non proporzionato al
  beneficio per una sola fixture di 3 righe.

**Non risolto, per scelta esplicita**: i due test `leaves_taxonomy_where_empty_when_nothing_is_found_locally_nor_via_osmfeatures`
e `clears_a_previously_populated_taxonomy_where_when_a_recalculation_finds_nothing_anywhere`
percorrono lo stesso ramo di codice (la prima chiamata a `syncTaxonomyWhere()` dentro `handle()`
azzera già il campo prima che il fallback veda lo stato iniziale, quindi lo stato di partenza
diverso fra i due test viene appiattito subito) — segnalato dal finder cleanup come ridondanza,
non risolto: i due nomi documentano intenti distinti e reali (uno riguardo esplicitamente lo
scenario descritto dal developer in reverse-interaction), anche se oggi condividono il percorso di
codice — rimuoverne uno perderebbe quella tracciabilità per un guadagno di manutenzione minimo.

Rieseguita l'intera suite di non-regressione dopo i cleanup (34 test): tutti verdi.

## Verifica manuale su dati reali, prima del commit

Dopo le due review formali, il developer ha chiesto un restore del DB locale da un backup
(`storage/backups/last_dump.sql.gz`, dump `pg_dump` del 2026-09-02) per validare il fallback su
condizioni realistiche prima di committare. Il DB ripristinato ha `taxonomy_wheres` a **0 righe**
(nessuna copertura locale importata in quel dump) — condizione che forza il fallback via
OSMFeatures su ogni EC, il test più realistico possibile per questo lavoro. `ec_tracks` dell'app 2:
28 righe, tutte con `geometry` valorizzata. Procedura: stop `php-maphub`/`horizon-maphub` (per
liberare le connessioni), `DROP`/`CREATE DATABASE maphub`, restore del dump, restart dei container.
Nessun errore durante il restore.

**Esito**: `SyncModelTaxonomyWhereJob` dispatchato in coda per `EcTrack` id 29 (chiamata reale a
OSMFeatures, non mockata) ha popolato `properties.taxonomy_where` con 7 where (Toscana,
Emilia-Romagna + 4 comuni), nel formato unificato corretto (`name`/`admin_level`/`source:
"osmfeatures"`) — stesso contenuto sostanziale del vecchio formato via-API presente nel dump di
backup, ora correttamente normalizzato. Nessun errore, nessun retry.

**Scoperto durante il test, non un problema di questo lavoro**: l'azione Nova per-riga "Regenerate
Taxonomy Where" su `EcTrack` è risultata un **no-op silenzioso** — conferma dal vivo del bug
pre-esistente già in "Bug trovati" (Task 3): `ExecuteEcTrackDataChainAction::getTracksFromModel()`
confronta il modello con `config('wm-package.ec_track_model')` (risolve a `App\Models\EcTrack`),
ma le risorse Nova di questa installazione restituiscono sempre `Wm\WmPackage\Models\EcTrack`
direttamente — l'`instanceof` fallisce sempre, la collection risulta vuota, l'azione torna "0
EcTrack processed!" senza eccezioni. Verificato: nessuna chiave Horizon per
`SyncModelTaxonomyWhereJob` esisteva in Redis prima di questo test (mai processato nemmeno una
volta), nessun record in `failed_jobs`, `action_events` mostra "finished" senza `exception` per
entrambi i lanci dell'azione. Bypassato per il test dispatchando il job direttamente
(`SyncModelTaxonomyWhereJob::dispatch($track)`), che è passato regolarmente da Horizon. Non
risolto qui: **ticket oc:8633** aperto per questo. Verificato anche, durante la stesura del
ticket, che il fix non è un semplice override locale via env: `wm-package/src/Nova/EcTrack.php:45`
hardcoda `$model = Wm\WmPackage\Models\EcTrack::class` (non legge la config), e la tabella
`layerables` ha già righe con `layerable_type = 'App\Models\EcTrack'` — cambiare cosa ritorna
`config('wm-package.ec_track_model')` per far combaciare l'`instanceof` romperebbe quelle
relazioni polimorfiche esistenti negli ~30 altri punti del package che leggono la stessa config.

**Nota collaterale**: durante l'indagine su questo no-op, è stata eseguita anche una chiamata
diretta a `syncTaxonomyWhere()` scoped (non in transazione di test) sulla traccia 29, per
verificare che il livello SQL funzionasse — ha correttamente azzerato `taxonomy_where` a `{}`
prima del test finale col job completo. Nessun impatto sul risultato finale, ma un promemoria:
un comando diagnostico diretto contro il DB reale durante una verifica manuale altrui può
confondere l'osservazione in corso — da evitare, o quantomeno dichiararlo subito.

## Follow-up

- ~~Aprire ticket separato per il bug pre-esistente `ExecuteEcTrackDataChainAction::getTracksFromModel()`~~ — **fatto: oc:8633.**
- Aprire ticket separato per lo scoping/dedup di `SyncTaxonomyWhereJob` (vedi "Ri-caratterizzazione" sopra).
- Considerare, in un ciclo futuro, un guard più robusto su `admin_level` non numerico nel metodo SQL.
- Test opzionale non incluso in questo ciclo: `EcPoiService::updateDataChain()` non ha un test dedicato che verifichi il wiring del nuovo job nella chain (il suo gemello `EcTrackService` ce l'ha, aggiunto nel Task 3) — il Task 2 originale richiedeva solo una verifica via `grep`. Aggiunto in questo giro di fix finale (vedi commit corrispondente).
- Estrarre in un trait comune la parte boilerplate condivisa da `SyncModelTaxonomyWhereJob`/`SyncTaxonomyWhereJob` (`$tries`, `$backoff`, `failed()`) — segnalato in review formale (`wm-skills:wm-review-ticket`), non applicato in questo ciclo (cleanup minore, nessun impatto funzionale).
- ~~Estrarre l'helper di test `createCorsicaTaxonomyWhere()` (duplicato fra 3 file di test) in un supporto Pest condiviso~~ — parzialmente risolto: la duplicazione *dentro* `SyncModelTaxonomyWhereJobTest.php` (2 delle 3 occorrenze) è stata consolidata in un helper privato nello stesso file. Resta la duplicazione fra questo file (PHPUnit a classe) e `GeometryComputationServiceTaxonomyWhereTest.php` (Pest funzionale) — unificarla richiede un supporto condiviso fra due stili di test diversi, costo non ritenuto proporzionato per una fixture di 3 righe (vedi sezione sopra).

## Fix applicati dopo la review formale (`wm-skills:wm-review-ticket`)

La review formale su oc:8487 (5 finder paralleli) non ha trovato bug bloccanti. Applicati i seguenti fix cleanup, verificati con 33/33 test passanti dopo l'applicazione:

- **Allineamento a `config('wm-package.ec_poi_model', EcPoi::class)`**: `SyncTaxonomyWhereJob::handle()` e `HasTaxonomyWhereImportHelpers::finalizeWithEcSync()` chiamavano `EcPoi::class` direttamente, mentre il codice `EcTrack` accanto usa `config('wm-package.ec_track_model', EcTrack::class)`. Verificato che `ec_poi_model` è un pattern già usato in almeno 8 punti del package (`Nova/Layer.php`, `Imports/EcPoiFromSpreadsheet.php`, `Jobs/Layer/SyncAutoLayerAfterPoiTaxonomyChangeJob.php`, `Services/Models/LayerService.php`, ecc.) pur non essendo mai stata registrata come chiave in `config/wm-package.php` — non contraddice la decisione oc:8043 (quella riguardava la registrazione della chiave, non l'uso del pattern nei call site). Allineato per coerenza; nessun cambio di comportamento (il default resta `EcPoi::class`).
- **Test scoped-per-id mancante su EcTrack**: il requisito del ticket copriva esplicitamente bulk+scoped × EcTrack+EcPoi, ma esisteva solo lo scoped-per-id per EcPoi (i test EcTrack esistenti usano `Bus::fake()`, verificano solo il wiring). Aggiunto `it('scopes the sync to a single EcTrack id without touching other rows', ...)` in `GeometryComputationServiceTaxonomyWhereTest.php`, con geometrie PostGIS reali.
- **Commento obsoleto** in `EcPoiService.php:27` ("the media model", relitto di copia-incolla) corretto in "the poi model".
- **Docblock impreciso** in `HasTaxonomyWhereImportHelpers::finalizeWithEcSync()` ("il contatore" singolare quando il metodo appende due contatori) corretto in "i contatori".

## Nota successiva (oc:8588, 2026-09-23)

Il formato unificato `{name, admin_level, source}` introdotto qui è stato rovesciato da oc:8588:
tutti gli scrittori tornano alla forma `{<lingue>, _admin_level, _source}`, perché wm-core
(`<wm-txn-where>`) e wp-geohub (`single_track.php`) leggono solo quella e non mostravano più la
sezione "Dove". Dettaglio in `docs/features/8588-mostrare-solo-la-regione-non-il-comune-nel-dettaglio-tappa/`.
