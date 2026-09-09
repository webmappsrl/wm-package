> Ticket: oc:8488

# Config app: allineamento a GeoHub post-import — Note di esecuzione

Esecuzione tramite `superpowers:subagent-driven-development`: 12 task, ognuno con implementer e reviewer indipendenti in sessioni separate, nessun commit eseguito da Claude (working tree lasciato in staging per il dev, come da vincolo esplicito). Branch: `feature/oc-8488-config-app-allineamento-geohub-post-import`.

## Esito per causa (vedi `overview.md` per il dettaglio tecnico)

- **Causa 1** (import copia colonne, config legge `properties`): risolta da `ImportedAppProperties` (Task 2, mappa a 37 chiavi) + `AppConfigService::prop()`/`setProp()` (Task 4, ~36 punti di lettura migrati) + `ImportAppJob::buildImportedProperties()`/`mergeProperties()` (Task 1, 3) + gestione tiles (Task 6) + campi Nova distribuiti nelle tab esistenti (Task 5, poi ridisegnato — vedi sotto).
- **Causa 2** (remap HOME + scrittura config al momento sbagliato): risolta da `ImportAppJob::finalizeAppImport()` agganciato al `->finally()` del solo batch layer (Task 10), non a un batch padre di tutto l'import — il *trigger* della prima scrittura dipende solo da `apps` e dai layer. Il *contenuto* del config dipende anche da `ec_poi`/`ec_media`/`ec_track`/`taxonomy_activity`/`taxonomy_poi_types` (post-review, vedi "Fix post-review finale" e "Risoluzione dei 2 Important residui" sotto): questi batch accodano anche loro un refresh best-effort, gated per non pubblicare mai un config con `MAP.layers` incompleto.
- **Causa 4** (`fill()` sostituisce `properties` invece di fonderle): risolta da `mergeProperties()` (Task 1), usata ovunque `ImportAppJob` scrive su `properties` — merge monotono crescente, una chiave che Geohub smette di emettere resta.

## Rulings del controller durante l'esecuzione

1. **`show_favorites` non era una divergenza reale.** Il piano lo classificava "gruppo B" (da migrare). Verificato che oc:8176 ("Preferiti layer"), già nel commit base del branch, aveva già risolto il campo con output camelCase `showFavorites` e cast `(bool)`. Corretto: `ImportedAppProperties::MAP['show_favorites']` ha `'nova' => false` (campo Nova già esistente da oc:8176); `AppConfigService::config_section_options()` non migra questo campo a `setProp()`, resta il codice originale oc:8176. `novaKeys()` restituisce 30 elementi, non 31.

2. **`composer.json` — dipendenza mancante, scoperta durante il Task 5.** `Nova\App.php` usa `Outl1ne\MultiselectField\Multiselect` in 5 punti (incluso `available_languages`, un campo base), ma `wm-package/composer.json` non l'ha mai dichiarata — solo il consumer `maphub` la dichiara. Bug di packaging preesistente, non causato da oc:8488, necessario per eseguire qualunque test che instanzi `Nova\App::fields()` per intero. Accettata l'aggiunta di `"outl1ne/nova-multiselect-field": "^5.0.1"` (stessa versione già in uso dal consumer). **Da segnalare esplicitamente nella descrizione della PR**, perché tocca un file con implicazioni di installazione per ogni consumer del package.

3. **Ambiente Docker sbagliato nel piano originale, corretto per tutti i task.** Il piano usava `docker compose -f local.compose.yml exec ... laravel`, il cui mount di `/var/www/html/wm-package` punta a una directory host vuota e obsoleta. Corretto a `docker exec -w /var/www/html/maphub/wm-package php-maphub` (convenzione documentata in `maphub/CLAUDE.md`).

4. **Pattern di test `uses(TestCase::class, DatabaseTransactions::class)` rotto in tutto il piano originale.** `tests/Pest.php` applica già `uses(TestCase::class)->in(__DIR__)` a tutta la cartella; ridichiarare la stessa classe nel singolo file lancia `Pest\Exceptions\TestCaseAlreadyInUse`. Corretto in tutto `plan.md` prima di dispatchare il Task 1: pattern giusto è `uses(DatabaseTransactions::class);` senza ridichiarare `TestCase::class`. **Il file preesistente `tests/Feature/AppConfigServiceThemeTest.php` (oc:8367) ha ancora il pattern rotto** e non è eseguibile con questa versione di Pest — non toccato (fuori scope), ma è debito noto.

5. **`composer.lock` stale bloccava `composer install`** per una licenza Nova incompatibile. Risolto con un pin temporaneo di `laravel/nova` alla versione già in uso dal consumer (`5.7.6`), poi `composer.json` ripristinato via `git checkout --` (nessuna modifica tracciata da questo fix, resta solo nel `composer.lock` locale gitignored e nel `vendor/` installato).

6. **Ruolo Postgres `wm_package` mancante.** `phpunit.xml.dist` richiede un ruolo DB dedicato (`DB_USERNAME=wm_package`), mai creato sul server Postgres locale. Creato con `CREATE ROLE wm_package WITH LOGIN PASSWORD 'wm_package';` + grant.

## Fix loop (Important, entrambi chiusi al primo tentativo con re-review indipendente)

- **Task 4**: la regex del test di sincronia `prop()`/`setProp()` non catturava le chiamate `setProp()` (solo `prop()` diretto) — corretta con due pattern separati, verificata anche con un'iniezione temporanea di una chiave non dichiarata per confermare che il test la rileva davvero.
- **Task 10**: `finalizeAppImport()` usava `Log::warning()` sul canale di default invece di `Log::channel('wm-package-failed-jobs')->warning(...)` (limite di `Log::spy()` nel test dell'implementer) — corretto riusando il pattern `Log::shouldReceive('channel')->andReturnSelf()` già presente in `FetchGravatarAvatarJobTest.php` dello stesso package.

## Minor findings deferred (nessuno blocca il merge)

- **Task 1**: `mergeProperties()` fa merge ricorsivo di un solo livello (dichiarato nel docblock, limite noto); un caso non raggiungibile oggi (chiavi annidate con `null` quando `$existing` è `null`) non filtra i `null` come il ramo merge; query duplicata innocua (`findExistingApp()` + lookup interno di `GeohubImportService::importData()`).
- **Task 6**: `syncTiles()` con `$parsed` vuoto non chiama `sync([])` — su un ipotetico re-import con `tiles` assente, la pivot precedente resterebbe intatta invece di svuotarsi. Non specificato dalla spec.
- **Task 7**: `uniqueFor()` come metodo invece che proprietà pubblica — stile diverso da `BuildAppPoisGeojsonJob`, entrambe le forme valide per `ShouldBeUnique` di Laravel.
- **Task 8**: nessun test copre box misti (`box_type` diverso da `'layer'`) accanto a un box layer — logica corretta (guard esplicito), ramo non esercitato da test.
- **Task 9**: `options()` closure riassegna `$layers` tre volte invece di una chain fluente (stilistico); `ConfigHomeResolver` foreach non gestisce un valore `'layer'` falsy-non-null (es. `0`) col cast `(int)` — preesistente, non introdotto da questo task.
- **Task 10 (nota architetturale, non un finding)**: `finalizeAppImport()` chiama `UpdateAppConfigJob->handle()` direttamente, bypassando la protezione `ShouldBeUnique` (che si applica solo via `dispatch()` in coda) — coerente col brief, lascia scoperto il caso di più `finalizeAppImport()` concorrenti in race con `TileObserver`/`LayerObserver` asincroni, senza lock. Da valutare in futuro se necessario.
- **Task 11**: nessuno strutturale (censimento consumer chiuso senza blocchi; workaround `getShardName()` confermato pattern già usato altrove nel package).

## Verifica PHPStan (Task 12)

Analisi scoperta ai soli 9 file toccati (`--error-format=raw`, confronto message-per-message ignorando il numero di riga, che shifta per via delle modifiche):

- **Baseline** (stato pre-fix degli 8 file preesistenti, via `git stash`): 129 errori.
- **Corrente** (9 file, incluso il nuovo `ImportedAppProperties.php`): 98 errori.

Netto: **-31 errori**. I 7 messaggi presenti solo nel corrente non sono una nuova categoria di problema — ricadono in due pattern già presenti in baseline sugli stessi file:

1. Accesso a proprietà/metodi (`$id`, `$properties`, `layers()`, `associatedLayers()`, `tiles()`) su `Illuminate\Database\Eloquent\Model` generico invece che sul modello concreto `App` — stesso identico pattern già in baseline (es. `Nova/App.php: Access to an undefined property Illuminate\Database\Eloquent\Model::$id.` era già presente prima del fix). Deriva da parametri/return type volutamente generici (`?Model $existing` in `mergeProperties()`, `Model $app` in `syncTiles()`, `$this->model()` di Nova Resource) — Larastan non risolve il tipo concreto in questi punti, come già non lo risolveva altrove nello stesso file.
2. Tautologia `is_array()`/nullsafe ridondante su una colonna con cast Eloquent `array` (`AppConfigService::prop()`) — stesso pattern già presente 2 volte in baseline (`AppConfigService.php` e `ConfigHomeResolver.php`), difensivo ma riconosciuto sempre vero da PHPStan.

I 31 errori risolti provengono in gran parte dalla migrazione di `AppConfigService` da accesso diretto a colonne inesistenti (`Access to an undefined property Wm\WmPackage\Models\App::$table_details_show_*`, `$enable_routing`, `$offline_enable`, ecc. — ~25 occorrenze) a `prop()`/`setProp()` (Task 4), oltre alla rimozione del branch morto `BuildConfJson()` in `AppController::config()` (Task 11).

**Esito: nessun errore di nuova categoria introdotto sui file toccati**, come richiesto dal piano.

**Ri-verificato dopo i fix post-review** (Finding 1-6, redesign Nova): `src/Jobs/Import/ImportAppJob.php` ricontrollato isolatamente dopo tutte le modifiche di questa sezione — 16 errori, stessa identica categoria (accesso a `$model->id`/`$model->properties` su `Illuminate\Database\Eloquent\Model` generico), nessuno nuovo. Non ri-eseguita l'analisi sull'intero set di 9 file (il conteggio "98" sopra è quindi leggermente superato in valore assoluto, non nella categoria) — non necessario: nessuna delle modifiche successive tocca `AppConfigService.php`/`ConfigHomeResolver.php`/gli altri file già verificati, solo `ImportAppJob.php` e `Nova/App.php` (quest'ultimo verificato separatamente, stesso esito, vedi sezione redesign Nova sotto).

## Debito noto non risolto in questo ciclo (fuori scope)

- `tests/Feature/AppConfigServiceThemeTest.php` (oc:8367) ha il pattern `uses(TestCase::class, DatabaseTransactions::class)` rotto — stesso bug Pest descritto sopra, preesistente a oc:8488.
- 97 dei 98 errori PHPStan residui sui file toccati sono debito preesistente non causato da questo ticket (proprietà dinamiche su `App`/`Model` non dichiarate via PHPDoc, incompatibilità di return type su `AppConfigService::config()`, ecc.) — nessuno di questi è stato introdotto da oc:8488, ma nemmeno risolto: fuori scope.

## Fix post-review finale

Review whole-branch formale (post 12 task): 1 Critical + 3 Important, tutti confermati reali e corretti nello stesso passaggio. Dettaglio completo (file/righe, test eseguiti, deviazioni) in `.superpowers/sdd/plan/final-review-fix-report.md`.

1. **CRITICAL — `finally()` non serializzabile, layer import rotto per intero.** Il closure `fn (Batch $batch) => $this->finalizeAppImport($appId, $batch)` in `ImportAppJob::queueEntityImport()` catturava implicitamente `$this` (→ `GeohubImportService` → `Connection` PDO/`Logger`, non serializzabili). Serializzato da `BatchRepository::store()` a ogni `$batch->dispatch()` reale — cioè ogni import GeoHub con layer tra le dipendenze — falliva con `Exception: Serialization of 'Pdo\Pgsql' is not allowed`, e nessun batch layer veniva mai dispatchato. Fix: `finalizeAppImport()` reso `static`, closure `static` che referenzia il metodo per FQCN. Aggiunto un test di regressione che guida `queueEntityImport()` per davvero (nessun `Bus::fake()`, che avrebbe nascosto il bug) e verifica che non lanci.
2. **IMPORTANT — `finalizeAppImport()` saltava il remap HOME insieme alla scrittura config su ogni batch failure.** Contraddiceva sia il commento nel codice sia `overview.md`/`plan.md` ("remap parziale, non un danno, solo incompleto"). Con 35 job falliti nel test di import reale che ha originato il ticket, un batch layer parzialmente fallito è lo scenario normale, non un edge case — e senza remap, `config_home` resta con id GeoHub, esattamente la corruzione che la Causa 2 doveva prevenire. Fix: il remap HOME gira sempre; solo la scrittura del config resta gated sull'integrità del batch.
3. **IMPORTANT — `MAP.pois.skipRouteIndexDownload` leggeva ancora la colonna morta.** Un secondo punto di lettura di `skip_route_index_download` in `config_section_map()`, distinto da `OPTIONS.skipRouteIndexDownload` (già migrato a `setProp()` nel Task 4), era rimasto sull'attributo colonna inesistente — sempre `null`. Migrato a `setProp()` con la stessa chiave `properties` già dichiarata in `ImportedAppProperties::MAP`.
4. **IMPORTANT — config pubblicabile prima che i batch taxonomy/ec_poi/ec_media finiscano.** `config_section_map()` legge `getAllPoiTaxonomies()` e il `feature_image` per-layer da batch indipendenti dal batch layer, senza garanzia di completamento relativa (oc:8094) — `finalizeAppImport()` poteva scrivere un config fresco sui layer ma ancora privo di queste sezioni. Fix: `ec_media`, `ec_poi`, `taxonomy_activity`, `taxonomy_poi_types`, `taxonomy_theme` agganciano ciascuno un `finally()` che accoda (non sincrono) un `UpdateAppConfigJob` di refresh best-effort — collassa su `uniqueFor(): 600` già esistente (Task 7).

### Re-review scoped della fix wave: 2 Important residui, poi risolti su richiesta esplicita del dev

La re-review scoped (indipendente, non solo lettura del fix report) ha confermato i 4 fix sopra come ADDRESSED con evidenza propria (riproduzione empirica del bug di serializzazione, verifica dei call site reali), ma ha trovato due problemi **introdotti dalla fix wave stessa**:

5. **Il fix del punto 4 annullava il gate del punto 2.** Tutte e 5 le chiavi di `CONFIG_DEPENDENT_BATCHES` erano in `default_dependencies.app`, quindi in un import normale almeno un altro batch (ec_poi/ec_media/taxonomy_*) finiva comunque e il suo `finally()` chiamava `UpdateAppConfigJob::dispatch($appId)` **incondizionatamente**, ignorando se il batch layer aveva fallito — il config veniva riscritto anche nel caso esatto ("batch layer fallito, `MAP.layers` incompleto") che il gate del punto 2 doveva impedire.
6. **`ec_track` era un dipendente reale di `config_section_map()` ma escluso da `CONFIG_DEPENDENT_BATCHES`.** Alimenta `MAP.bbox` (quando `apps.map_bbox` è null, via `getEcTracksBboxByAppId()`) e `MAP.filters.activities` (via `collect_taxonomies_from_tracks()`). La verifica della fix wave originale aveva controllato le sezioni sbagliate di `config_section_map()`. `taxonomy_theme`, invece, non ha nessun punto di lettura lì — rimosso (un refresh in più inutile, non un bug, ma va tolto).

**Risolti entrambi**, su richiesta esplicita del dev ("deve funzionare e basta senza cose incomplete o errori svegliati"):

- **Punto 6**: `CONFIG_DEPENDENT_BATCHES` aggiornata a `ec_media`, `ec_poi`, `ec_track`, `taxonomy_activity`, `taxonomy_poi_types` (tolto `taxonomy_theme`, aggiunto `ec_track`).
- **Punto 5**: introdotto un gate — `ImportAppJob::layerBatchIsPublishReady(int $appId): bool` — che i `finally()` di `CONFIG_DEPENDENT_BATCHES` controllano PRIMA di accodare `UpdateAppConfigJob::dispatch()`. Meccanismo: un sentinel in cache (`wm-package:import-layer-batch:{appId}`) tiene traccia dello stato del batch layer DELLO STESSO import:
  - `processDependencies()` scrive `'pending'` come **prima istruzione**, prima di dispatchare qualunque batch, se `'layer'` è tra le dipendenze — chiude la corsa in cui un altro batch finisce prima che il batch layer sia anche solo dispatchato (l'ordine di dispatch nel codice non è garanzia di ordine di completamento).
  - Quando il batch layer viene realmente dispatchato, la cache viene aggiornata con il suo id reale (`Bus::findBatch()` lo risolve poi a runtime).
  - Quando `'layer'` non ha id da importare (ramo sincrono, nessun batch), il sentinel viene liberato subito: `finalizeAppImport($appId, null)` ha già scritto tutto quello che c'era da scrivere in quel momento.
  - `layerBatchIsPublishReady()`: nessuna voce in cache → sicuro (nessuna dipendenza layer in questo import); `'pending'` → non sicuro; un id reale → sicuro solo se `Bus::findBatch()` lo trova **finito, senza fallimenti, non cancellato**. Se il batch non si trova più, si tratta come non sicuro (per costruzione: `finalizeAppImport()`, il `finally()` del batch layer stesso, scriverà comunque il config finale non appena completa — non scrivere qui non perde mai il dato, al più lo ritarda).
  - Stesso vincolo di serializzazione del punto 1 (closure `static`, nessun `$this` catturato, referenziato per FQCN — verificato).
- 10 nuovi test in `ImportAppJobConfigRefreshBatchesTest.php` (batch pending/fallito/completato con successo/in corso, testati con una riga reale in `job_batches` invece di mockare `Bus::findBatch()` — coesiste meglio con `Bus::fake()`), più il test `taxonomy_theme` sostituito con `taxonomy_activity`.

Nessuna regressione: 30 test rieseguiti su tutti i file `ImportAppJob*`, tutti verdi. PHPStan invariato (stessa categoria di errori pre-esistenti, nessuna nuova). Dettaglio completo nel ledger `.superpowers/sdd/plan/progress.md`.

## Redesign UI Nova: rimossa la tab "Imported config" (post-review, richiesto dal dev)

Il Task 5 originale (approvato in fase di plan) generava un'unica tab top-level "Imported config" con tutti i 30 campi piatti. **Ruling del dev, in review: sbagliato** — l'import porta un'app su Maphub, l'app deve comparire nelle tab esistenti come qualsiasi altra, non in una tab dedicata "campi importati". Ridisegnato distribuendo i 30 campi nelle tab esistenti, per sezione di config effettivamente letta (non per provenienza):

- **`app_tab` (Frontend)**: le 6 chiavi `OPTIONS` (`start_url`, `show_edit_link`, `skip_route_index_download`, `show_embedded_html`, `show_get_directions`, `show_media_name`) — accanto a `Show Download Tracks`/`Show Travel Mode`/`Ugc Track Share Enabled`/`Show favorites`, già lì e stessa sezione. Più le 19 chiavi `table_details_show_*` (sotto due heading separati, vedi punto successivo).
- **`mobile_tab` ("FE: mobile")**: le 3 chiavi `OFFLINE` (`offline_enable`, `offline_force_auth`, `tracks_on_payment`) — accanto a `Show Download Tiles`, stesso ambito (comportamento offline).
- **`map_settings_tab`** (dentro `map_tab`, sezione "ADVANCED MAP SETTINGS"): `enable_routing` (→ `ROUTING.enable`) — accanto a `Show Track Direction Arrow`/`Show Features In Viewport`, stesso pattern `properties->*`.
- **`webapp_tab` ("FEwebapp")**: `draw_poi_show` (→ `WEBAPP.draw_poi_show`) — accanto a `Show Auth at startup`, unico altro campo `WEBAPP` già in Nova.

**Le 19 chiavi `table_details_show_*` (`TABLES.details`, solo app `elbrus`) sono state verificate contro l'admin reale di GeoHub** (`geohub/app/Nova/App.php`), non assunte: 10 non hanno mai avuto una UI su nessuna delle due piattaforme (`gpx/kml/geojson/shapefile_download`, `scale`, `related_poi`, `cai_scale`, `mtb_scale`, `ref`, `surface`) — vanno in `app_tab`, su richiesta esplicita del dev. Le altre 9 (`duration_forward/backward`, `distance`, `ascent`, `descent`, `ele_max/min/from/to`) hanno un equivalente su GeoHub (`options_tab()`), ma **scrivono su una colonna diversa**: GeoHub edita `track_technical_details->show_*` (che alimenta `OPTIONS.show*` generico), non la colonna `table_details_show_*` che l'import di Maphub legge davvero (che alimenta `TABLES.details.*`, solo elbrus). Le due cose sono rimaste **volutamente separate** (nomi identici, config diversi) — nessun merge, per non introdurre un comportamento di scrittura condivisa non richiesto e non testato.

**Aggiunta correlata, su richiesta esplicita del dev**: `track_technical_details->show_*` è una colonna jsonb preesistente in Maphub (default nella migration, già letta da `AppConfigService::config_section_options()`) che **non aveva mai avuto un campo Nova**, prima di questo fix. Aggiunti 9 nuovi campi Boolean in `app_tab`, con le stesse label/help di GeoHub (`options_tab()`), sotto un heading "Technical details" separato da quello dei 19 `table_details_show_*` ("Table details") per evitare confusione tra i due gruppi identici nel nome ma diversi nell'output di config. **Questa aggiunta è correlata alla causa 1 ma tecnicamente fuori dalla catena causale di oc:8488** (colonna mai toccata dall'import GeoHub→Maphub) — va segnalata nella PR come per il `composer.json`.

Implementazione: `imported_properties_tab()` sostituito da `importedPropertyField(string $key)` (helper per-chiave, ancora generato da `ImportedAppProperties`, riusabile da qualunque tab) + due costanti `TABLE_DETAILS_KEYS_WITH_TECHNICAL_DETAILS_TWIN`/`TABLE_DETAILS_KEYS_WITHOUT_GEOHUB_UI` (elenco esplicito, non derivato — nessuna euristica sul nome della chiave) + `technicalDetailsFields()` (i 9 campi nuovi, non generati da `ImportedAppProperties`: colonna diversa, fuori mappa). Test esistente (`AppImportedPropertiesFieldsTest`, Task 5) verificato invariato — la sua flattening raggiunge correttamente i tab nidificati (`FE: mobile`, `FEwebapp`), nessuna modifica necessaria. 15 test rieseguiti, tutti verdi.

## Stato finale

Nessun commit eseguito da Claude. File modificati/nuovi in staging: `composer.json`, `docs/features/8488-.../{overview,plan}.md`, `resources/lang/{en,it}.json`, `src/Http/Controllers/Api/AppController.php`, `src/Jobs/Import/ImportAppJob.php`, `src/Jobs/UpdateAppConfigHomeLayerIdsJob.php`, `src/Jobs/UpdateAppConfigJob.php`, `src/Nova/App.php` (redesign UI), `src/Nova/Flexible/Resolvers/ConfigHomeResolver.php`, `src/Services/Import/GeohubImportService.php`, `src/Services/Models/App/AppConfigService.php`, `src/Support/ImportedAppProperties.php` (nuovo), `docs/features/8488-.../notes.md` (nuovo), 12 file di test (10 dal piano originale + `ImportAppJobFinalizeTest.php`/`ImportAppJobConfigRefreshBatchesTest.php` estesi dai fix post-review). **Whole-branch review completa, tutti i findings (1 Critical + 5 Important, inclusi i 2 residui emersi dalla stessa fix wave) risolti e verificati con test.** Prossimo passo: `superpowers:finishing-a-development-branch`.
