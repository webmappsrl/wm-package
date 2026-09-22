> Ticket: oc:8543

# Notes — Inversione della traccia: attivazione su Forestas

## Bug trovati durante l'implementazione

Emersi durante la review con `wm-review-ticket` (5 finder paralleli), tutti corretti prima del commit:

- **`ST_Reverse` non ha overload per `geography`**: la colonna `ec_tracks.geometry` è tipizzata
  `geography(MultiLineStringZ,4326)`, non `geometry`. La query `ST_Reverse(geometry)` falliva con
  `function st_reverse(geography) does not exist` — verificato empiricamente su Postgres.
  Corretto con il cast esplicito `ST_Reverse(geometry::geometry)`.
- **`->sole()` non è applicato dal server, solo dalla UI Nova**: verificato in
  `vendor/laravel/nova/src/Actions/DispatchAction.php::forModels()` — nessun controllo sul numero
  di modelli per un'azione `sole()`. Una richiesta con più risorse selezionate arrivava comunque a
  `handle()` con più elementi, di cui l'azione processava solo il primo mostrando comunque
  "successo" per tutti. Corretto iterando su tutti i `$models`.
- **Race condition sul dispatch del job DEM**: Nova avvolge `handle()` in una transazione DB
  (`Actions\Transaction::run()`); `config/queue.php` ha `after_commit => false` su tutte le
  connessioni configurate per staging/produzione (solo l'`.env` locale usa `sync`, che maschera il
  problema). Un dispatch "nudo" avrebbe potuto far leggere a un worker Horizon la geometria non
  ancora committata. Corretto con `UpdateEcTrackDemJob::dispatch($ecTrack)->afterCommit()`.
- **Secondo ciclo — `PendingChain` non ha un metodo `afterCommit()`.** Scrivendo il Task 2 del
  piano avevo scritto `Bus::chain($chain)->dispatch()->afterCommit()`, assumendo lo stesso pattern
  fluente di `Job::dispatch()->afterCommit()` (che funziona perché `PendingDispatch` ritorna se
  stesso e differisce il dispatch reale al `__destruct()`). `PendingChain::dispatch()` invece
  dispatcha subito e ritorna `null` — l'errore (`Call to a member function afterCommit() on null`)
  è emerso eseguendo per la prima volta i test in locale (vedi sotto). Il fix corretto: la classe
  Job (trait `Queueable`) ha un proprio metodo/proprietà `afterCommit`, e solo il primo job di una
  catena viene effettivamente accodato (gli altri partono in base al suo esito) — va quindi
  marcato lui: `$chain[0]->afterCommit(); Bus::chain($chain)->dispatch();`.
- **Secondo ciclo — i test di questa PR sono stati eseguiti per la prima volta in questa
  sessione**, superando il blocco licenza Nova: il container `php-forestas` monta il submodule
  reale (`vendor/wm/wm-package` è un symlink), ha licenza Nova valida, e il database `wm_package`
  con PostGIS esisteva già su `postgres-forestas` (creato in una sessione precedente, oc:8469).
  Verificato con `vendor/bin/pest` **di `wm-package` stesso** (non quello di `forestas` root: le
  due versioni di Pest/PHPUnit installate sono incompatibili tra loro — `forestas` ha Pest
  3.8/PHPUnit più recente, `wm-package` Pest 2.36/PHPUnit compatibile col suo `phpunit.xml.dist`).
  I 5 test di `ReverseEcTrackGeometryActionTest` passano tutti, incluso quello multi-parte. La
  correzione SQL è stata verificata anche a mano sulla traccia reale con più parti attualmente nel
  DB Forestas — **id 427** (12 parti; gli id 82/334/364/486/763 citati nella review non hanno più
  più parti oggi, il DB è stato reimportato nel frattempo) — dentro una transazione con `ROLLBACK`
  esplicito, nessuna scrittura persistita: punti di inizio/fine scambiati correttamente, 12 parti
  mantenute, quota Z preservata.

## Decisioni

- **Secondo ciclo — Task 8 del piano (traduzioni de/es/fr) saltato.** L'overview richiedeva
  traduzione in "tutti i `resources/lang/*.json` esistenti (de, en, es, fr, it)", ma verificando i
  file `de.json`/`es.json`/`fr.json` contengono solo 8 chiavi in totale (nomi delle stagioni e dei
  tipi di rete escursionistica), nessun nome di Nova Action — a differenza di `en.json`/`it.json`
  (~458 chiavi, comprese tutte le altre action di `EcTrack`). Non sono file di traduzione UI a
  piena copertura ma vocabolari minimi con uno scopo circoscritto. Aggiungere lì le 3 stringhe
  dell'azione le avrebbe rese le uniche stringhe di un'Action tradotte in quei file — incoerente
  col resto del pacchetto. Il requisito dell'overview non era stato verificato contro il codice
  reale (né in questo ciclo né nel primo).
- **Secondo ciclo — correzione sul pattern permessi.** Sia la review che l'overview citavano
  `App.php:153-154` come pattern per `canSee`/`canRun` limitato a un ruolo, ma quelle righe sono
  in realtà un controllo per whitelist email (`RolesAndPermissionsService::allows`), non per ruolo.
  Il pattern corretto per `hasRole('Administrator')` esiste altrove nello stesso file
  (`App.php:843,848`, a livello di campo). Vedi `plan.md`, Task 7.

- **Nessun test con valori DEM reali/mockati numericamente verificati** per il ricalcolo dopo
  l'inversione. Già discusso in Fase: challenge (vedi overview.md, sezione Requisiti): nel
  package non esiste un'infrastruttura di test che esegua un vero calcolo DEM — replicarla per
  questo ticket a priorità bassa avrebbe richiesto di ricostruire la stessa precondizione contorta
  già presente in `EcTrackService::updateDemData()`, per cui anche il test esistente del package
  (`UpdateDemDataTest`) usa un mock Mockery del model invece di un record DB reale. Accettato come
  limite noto: la copertura attuale verifica che il job venga dispatchato correttamente (e dopo il
  commit), non il valore numerico finale.
- **Secondo ciclo — PHPStan verificato sui 4 file toccati**, confrontando il conteggio errori
  prima/dopo (`git stash`) per isolare quelli introdotti da questo ciclo da quelli preesistenti
  (il file non ha una `phpstan-baseline.neon` per questi due file, quindi mostra tutto il debito
  storico): `ReverseEcTrackGeometryAction.php` 0→0, `EcTrack.php` 5→5,
  `EcTrackService.php` 22→22. Un errore introdotto in `GeometryComputationService.php` (47→48,
  `Access to an undefined property GeometryModel::$id` sul binding `[$model->id]` della nuova
  `reverseGeometry()` — stesso pattern già presente altrove nello stesso file) è stato corretto
  usando `$model->getKey()` al posto di `$model->id`: tornato a 47/47.
- Durante la review sono stati anche semplificati due punti di duplicazione: il nome tabella
  hardcoded è diventato `$ecTrack->getTable()`, e la logica di rilevamento degli override manuali
  ora riusa `HasDemClassification::classifyField()` invece di reimplementarla (questo ha anche
  risolto, come effetto collaterale, un bug di lettura di `manual_data` salvato come stringa JSON
  non decodificata).

## Esito `wm-review-ticket` (secondo giro, 22/09/2026) e fix applicati

Lanciata su richiesta del dev prima dei commit. 5 finder paralleli, esito iniziale: **APPROVATO
CON RISERVE**, 1 bloccante (corretto durante la review stessa) + 10 finding cleanup.

**Bloccante, corretto:**
- **Messaggi Nova en/it disallineati dal testo del codice.** Il Task 3 aveva aggiunto "This may
  take a while." ai due messaggi dell'Action, ma le chiavi in `resources/lang/en.json`/`it.json`
  erano rimaste al testo del primo ciclo — trovato indipendentemente da 3 finder su 5, verificato
  e corretto: chiavi allineate al testo esatto usato da `__()` nell'Action, traduzione italiana
  aggiornata ("Richiederà del tempo."), test rilanciati (5/5 verdi), JSON validato.

**Cleanup corretti su richiesta del dev (i "finding importanti"):**
- `GeometryComputationService::reverseGeometry()` ritipizzato da `GeometryModel` (troppo largo,
  base anche di POINT/POLYGON) a `MultiLineString` — coerente col metodo gemello
  `syncTracksTaxonomyWhere()` nello stesso file, che tipizza già così.
- Aggiunta la stessa validazione `preg_match` sul nome tabella già presente in
  `syncTracksTaxonomyWhere()`, mancante in `reverseGeometry()`.
- Query WKT di verifica duplicata 3 volte nel test → estratta in `wktOf(EcTrack $track): string`.
- **Aggiunti due test sulla restrizione Administrator** (`test_administrator_can_see_and_run_the_action`,
  `test_editor_cannot_see_or_run_the_action`), risolvendo la trappola già nota (oc:8569,
  `.claude/rules/nova.md`): l'Action va risolta tramite `EcTrack::actions($request)`, non
  istanziata a mano, altrimenti `canSee`/`canRun` non vengono mai esercitati. Per farli passare è
  emerso un problema pre-esistente più ampio: `Spatie\Permission\PermissionServiceProvider` non
  era registrato in `tests/TestCase.php::getPackageProviders()` — le tabelle `roles`/`permissions`
  esistono già dalle migration, ma `config('permission.models.role')` restava `null`
  (`PermissionRegistrar` va in `TypeError` al costruttore). Corretto registrando il provider, con
  lo stesso pattern già documentato nello stesso file per `ImageServiceProvider`/
  `MediaLibraryServiceProvider` ("Testbench non fa auto-discovery come un'app Laravel completa").
  **Probabile fix anche per altri test del package** che usano `RolesAndPermissionsService::
  seedDatabase()` (es. `ImportEcPoiFromOsmActionTest.php`) — non verificato di persona: quel file
  Pest non è eseguibile in isolamento in questo ambiente per un problema di discovery Pest
  indipendente (`Test case Wm\WmPackage\Tests\TestCase can not be used` quando lanciato da solo),
  un'altra stranezza pre-esistente non legata a questo ticket.

**Non corretti, lasciati come follow-up** (vedi sotto): tipizzazione/duplicazione di
`$administratorOnly` (5 occorrenze nel package), design del flag `forceGeometryChain`, timing di
`afterCommit()` esteso a `EcPoiObserver`/`EcPoiEcTrackObserver`, `DIRECTION_DEPENDENT_FIELDS`
duplicata, `$overriddenFieldsByTrack` scartato prima dell'uso, nessun controllo sulle righe
affette in `reverseGeometry()`.

**Secondo giro di `wm-review-ticket` (stessi 5 finder, dopo i fix sopra):** nessun bloccante
residuo o nuovo, confermato in modo indipendente da tutti e 5 i finder (uno ha rifatto il
confronto byte-per-byte delle traduzioni). 4 nuovi cleanup minori (duplicazione della regex
`preg_match` sul nome tabella — già nota —, `Auth::login()` ridondante insieme a
`setUserResolver()` nei due nuovi test sui permessi, `wktOf()` con nome tabella hardcoded invece
di `getTable()`, 4 chiamate identiche a `->handle()` nel test non estratte in un helper). Nessuno
bloccante, non corretti in questo ciclo.

Un finder ha sollevato un dubbio legittimo sulla causa dichiarata per `PermissionServiceProvider`
in `tests/TestCase.php` ("un test preesistente lo usa già senza il provider — la causa nel
commento potrebbe essere imprecisa"). **Verificato di persona**: con `git stash` sul fix,
`tests/Unit/Services/RolesAndPermissionsServiceTest.php` fallisce davvero 2 test su 12 con lo
stesso `TypeError` su `PermissionRegistrar`; con il fix, quei 2 diventano verdi (resta 1
fallimento, scollegato — vedi Follow-up). La causa nel commento è confermata corretta, il dubbio
del finder non regge.

## Follow-up

- **Secondo ciclo — Task 10 del piano (fix CI advisory Composer) rimandato.** Il dev ha scelto di
  non decidere ora come gestire i 3 advisory senza patch su Laravel 11.x (`PKSA-m5cs-t1y6-qpcs`,
  `PKSA-3r5d-mb8f-1qw9`, `PKSA-mdq4-51ck-6kdq`), dando priorità ai fix di codice del ticket.
  `composer install` in CI resta rosso su ogni PR del repo finché non si sceglie tra: ignorare
  tutti e 12 gli ID (rischio accettato sui 3 reali), o togliere `11.*` dalla matrice di
  `run-tests.yml` (ignorando solo i 9 innocui). Dettaglio verificato per ciascun ID in `plan.md`,
  Task 10.
- **Secondo ciclo — due problemi pre-esistenti nella suite di test del package, indipendenti da
  questo ticket** (nessun file coinvolto è stato toccato in questo ciclo), emersi eseguendo per la
  prima volta i test in locale:
  1. **Split di namespace nei test**: `grep -rl` mostra 51 file sotto `tests/` con
     `namespace Tests\...` (bare) contro 55 con `namespace Wm\WmPackage\Tests\...`.
     `composer.json` (`autoload-dev.psr-4`) registra solo il secondo prefisso — i primi 51 non
     sono risolvibili dall'autoload generato da `composer dump-autoload` in questo ambiente
     (verificato su `tests/Unit/Services/EcTrackService/UpdateDataChainTest.php`, che estende
     `Tests\Unit\Services\EcTrackService\AbstractEcTrackServiceTest`: `Class ... not found`).
     Se funzionavano prima era verosimilmente grazie a un classmap cacheato in una versione più
     vecchia del `vendor/` non più rigenerato. Variante dello stesso problema: alcuni file
     dichiarano correttamente `namespace Wm\WmPackage\Tests\...` ma importano `use Tests\TestCase;`
     invece di `Wm\WmPackage\Tests\TestCase` (es. `ImportTaxonomyWhereTest.php`,
     `GeohubWhereSelectionControllerTest.php`) — stesso esito, `Class "Tests\TestCase" not found`.
  2. **`tests/Unit/Nova/Actions/ExecuteEcTrackDataChainActionTest.php`** (namespace corretto)
     fallisce su `Class "App\Models\EcTrack" not found` — l'ambiente Testbench standalone del
     package non ha uno stub per quella classe, che nei consumer reali esiste sempre.
  3. **`RolesAndPermissionsService::allowsEmail()`** (`src/Services/RolesAndPermissionsService.php:39`)
     non gestisce il caso in cui `config('wm-package.super_admin_emails')` sia esplicitamente
     `null` (il default del secondo parametro di `config()` scatta solo se la chiave non esiste
     affatto, non se esiste ed è `null`): `in_array($email, null, true)` va in `TypeError`. Un
     test dedicato lo documenta già (`RolesAndPermissionsServiceTest::allows_email_uses_default_
     fallback_when_config_key_is_null`) e fallisce. Non è collegato a questo ticket.
  Non è stato affrontato in questo ciclo (fuori scope, impatto ampio su tutta la suite, non sui
  file toccati da oc:8543). Vale la pena aprire un ticket dedicato: spiega perché "i test non sono
  mai stati eseguiti" andava oltre il solo blocco licenza Nova già noto.
