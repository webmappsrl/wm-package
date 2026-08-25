continuiamo con> Ticket: oc:8158

# Piano implementativo — Import GeoHub: estensione UGC (POI, Track, Media) e utenti autori UGC

Riferimento: `overview.md` nella stessa cartella. Ogni step è un commit separato con lo scope `oc:8158`.

## 1. Ruolo Contributor (prerequisito, nessuna dipendenza da import)

**File:**
- `database/migrations/zz_2026_08_19_000001_add_contributor_role.php.stub` (nuovo — pattern identico a `zz_2026_06_26_000001_add_editor_role.php.stub`: `DB::table('roles')->insertOrIgnore(['name' => 'Contributor', 'guard_name' => 'web', ...])`, `down()` vuoto con commento sul vincolo FK `model_has_roles`)
- `src/Services/RolesAndPermissionsService.php` — aggiungere `Role::firstOrCreate(['name' => 'Contributor'])` in `seedDatabase()`, nessun `givePermissionTo('access-nova')` (Contributor non ha accesso Nova, come Guest)

**Commit:** `feat(oc:8158): add Contributor role migration stub and seed entry`

## 2. `GeohubImportService`: autore reale UGC e assegnazione Contributor

**File:** `src/Services/Import/GeohubImportService.php`

- Nuovo metodo `checkUgcUserExistence(int $userId): User` — mirror di `checkUserExistence()` esistente (stesso lookup by email, stessa creazione se assente) ma chiama `assignContributorRole()` invece di `assignEditorRole()`
- Nuovo metodo privato/protetto `assignContributorRole(User $user): void` — mirror esatto di `assignEditorRole()`: assegna `Contributor` solo se `$user->roles->isEmpty()`, fallback `RolesAndPermissionsService::seedDatabase()` se il ruolo non esiste ancora (stessa guardia contro corse, anche se la migration del punto 1 lo pre-seeda già — la guardia lazy resta come difesa in profondità, coerente col pattern esistente)
- Non toccare `checkUserExistence()`/`assignEditorRole()` esistenti

**Commit:** `feat(oc:8158): add checkUgcUserExistence assigning Contributor role`

## 3. Config `import_mapping`: `ugc_poi`, `ugc_track`

**File:** `config/wm-geohub-import.php`

- Nuova entry `ugc_poi`: `namespace => Wm\WmPackage\Models\UgcPoi::class`, `job => ImportUgcPoiJob::class`, `geohub_table => 'ugc_pois'`, `identifier => 'properties->geohub_id'`, `fields` minime (`name`, `created_at`, `updated_at`, `geometry`), `properties.column_name => 'properties'` con mapping `description` (jsonToArray se applicabile — verificare se su GeoHub `ugc_pois.description` è già testo semplice o json, vedi Step 3bis)
- Nuova entry `ugc_track` analoga, `geohub_table => 'ugc_tracks'`, namespace `config('wm-package.ec_track_model')` **non applicabile qui** — usare direttamente `Wm\WmPackage\Models\UgcTrack::class` (UGC non ha equivalente override applicativo come `ec_track_model`)
- **Non** aggiungere `ugc_poi`/`ugc_track` a `default_dependencies['app']`

**Step 3bis (verifica preliminare, prima di scrivere il mapping):** leggere `raw_data`/`description`/`metadata` su GeoHub (`geohub/database/migrations/2021_07_22_093956_add_description_to_ugc_pois.php`, `2021_07_22_102936_add_description_to_ugc_tracks.php`, `2023_04_13_062745_add_metadata_to_ugc_tracks.php`) per capire se sono testo semplice o JSON multilingua, e mappare di conseguenza nel `properties` locale (jsonb) — non assumere lo stesso formato di `ec_track`/`ec_poi` senza verifica, GeoHub UGC è un modello diverso da EC.

**Commit:** `feat(oc:8158): add ugc_poi and ugc_track import mapping config`

## 4. Job `ImportUgcPoiJob` e `ImportUgcTrackJob`

**File:**
- `src/Jobs/Import/ImportUgcPoiJob.php` (nuovo)
- `src/Jobs/Import/ImportUgcTrackJob.php` (nuovo)

Entrambi estendono `BaseImportJob` ma **riscrivono `transformData()` da zero** (pattern `ImportAppJob`, non `BaseEcImportJob`):
- Chiamano `$this->geohubImportService->transformFields()`/`transformProperties()` come base
- Impostano esplicitamente `$transformedData['user_id'] = $this->geohubImportService->checkUgcUserExistence((int) $data['user_id'])->id` — **non chiamare `parent::transformData()`**, per evitare di ereditare la forzatura `user_id = owner` di `BaseImportJob`
- Mappano `app_id` GeoHub (numerico-come-stringa) all'app locale via cast diretto (`(int) $data['app_id']`), non lookup su `sku`
- Se `$data['user_id']` è nullo: log di warning con `getModelName()`/`entityId` e `return` anticipato senza creare/aggiornare nulla (override di `handle()` o controllo a inizio `transformData()` — valutare in fase di scrittura quale punto d'aggancio è più pulito senza duplicare `BaseImportJob::handle()`)
- `processDependencies()`: vuoto (i media si occupano di sé stessi via `ImportUgcMediaJob` separato, punto 6)

**Comportamento update-if-newer** (sostituisce la logica generica `importData()` di `GeohubImportService`, che aggiorna sempre incondizionatamente): questi due job **non** devono chiamare `GeohubImportService::importData()` così com'è — serve una variante o un controllo esplicito prima del salvataggio:
- Cercare il modello locale esistente per `properties->geohub_id`
- Se non esiste: crea (comportamento identico a `importData()`)
- Se esiste: confronta `updated_at` GeoHub (raw, da `$data`) con `properties->geohub_synced_at` locale già salvato; aggiorna (`fill()` + `saveQuietly()`) solo se il primo è più recente, altrimenti non toccare il record e uscire

Valutare in fase di scrittura se questo controllo va in un nuovo metodo condiviso su `GeohubImportService` (es. `importDataIfNewer()`) per evitare duplicazione tra `ImportUgcPoiJob` e `ImportUgcTrackJob`, oppure in una classe base comune `BaseUgcImportJob` che estende `BaseImportJob` — **preferire la seconda opzione** per coerenza con l'architettura a job esistente (ogni famiglia di job condivide già una base, es. `BaseEcImportJob`).

**Commit:** `feat(oc:8158): add ImportUgcPoiJob and ImportUgcTrackJob with update-if-newer behavior`

## 5. `ImportUgcMediaJob` — foto UGC come Spatie media, nessun modello dedicato

**File:** `src/Jobs/Import/ImportUgcMediaJob.php` (nuovo), nuova entry `ugc_media` in `config/wm-geohub-import.php`

- Segue il pattern di `ImportEcMediaJob`/`EcMediaImportService::processEcMediaImport()`: override di `handle()` che **non** chiama `GeohubImportService::importData()` (nessun modello da creare)
- Risolve il/i modelli locali (`UgcPoi`/`UgcTrack`) a cui allegare la foto tramite le pivot GeoHub `ugc_media_ugc_poi`/`ugc_media_ugc_track` (stesso idioma di `EcMediaImportService::findEcMediaRelatedModels()`, adattato: qui non c'è featured image, solo pivot semplice)
- Se il modello locale non è ancora presente (dispatch parallelo, vedi overview Rischio 3): riprova con un breve delay (`$this->release(seconds: N)` o backoff simile) un numero limitato di tentativi prima di loggare il fallimento definitivo con il `geohub_id` specifico
- Costruzione URL e download: stesso pattern di `EcMediaImportService` (`config('wm-package.clients.geohub.host').'/storage/'.$relative_url`), ma **con fix esplicito** per distinguere URL irraggiungibile (network) da content-type non-immagine — non riusare la chiamata `get_headers($url, 1)[0]` senza controllare che il ritorno non sia `false` prima di accedervi
- Allega con `addMediaFromUrl($url)->toMediaCollection('default', config('wm-media-library.disk_name'))` sul modello risolto, stessa idempotenza di `EcMediaImportService` (check su `custom_properties.geohub_id` esistente prima di riscaricare)
- **Non modificare `EcMediaImportService`/`ImportEcMediaJob` esistenti** — se serve estrarre logica comune, farlo in un nuovo metodo/trait condiviso senza toccare il comportamento EC attuale

**Commit:** `feat(oc:8158): add ImportUgcMediaJob attaching UGC photos via Spatie media`

## 6. `ImportAppJob`: dependencies `ugc_poi`/`ugc_track`/`ugc_media`

**File:** `src/Jobs/Import/ImportAppJob.php`

- In `processDependencies()`, aggiungere i tre nuovi `if (in_array(...))` seguendo il pattern esistente, con `ugc_poi`/`ugc_track` dispatchati **prima** di `ugc_media` (ordine delle chiamate, sapendo che l'esecuzione resta comunque parallela su Horizon — vedi Rischio 3 accettato in overview, mitigato dal retry del punto 5)
- In `getAllowedDependencies()`, aggiungere `ugc_poi`, `ugc_track`, `ugc_media` all'array `$allDependencies` (necessario perché siano riconosciuti quando passati esplicitamente via `--dependencies=`), **senza** aggiungerli a `config('wm-geohub-import.default_dependencies.app')`

**Commit:** `feat(oc:8158): wire ugc_poi/ugc_track/ugc_media into ImportAppJob dependencies`

## 7. Verifica `AppClassificationService`/`GeometryComputationService`

- Ricerca statica (`grep`/`Explore`) dei punti di chiamata reali di entrambi i servizi per capire se referenziano `UgcMedia` da un percorso raggiungibile in produzione (route, comando, job, observer) o solo da codice morto/test disabilitati
- Se raggiungibile: adattare l'assunzione (oggi presumibilmente `app_id` come stringa SKU) al nuovo schema, oppure — se il codice comunque non crea mai un modello `UgcMedia` reale — verificare che non vada in errore silenzioso
- Se dead code: nessuna modifica al codice, solo una riga in `notes.md` che lo documenta esplicitamente

**Commit:** `docs(oc:8158): document AppClassificationService/GeometryComputationService UgcMedia reachability` (se nessuna modifica al codice) oppure `fix(oc:8158): ...` (se serve un adattamento)

## 8. Test Pest

**File:** `tests/Feature/Import/ImportUgcPoiJobTest.php`, `ImportUgcTrackJobTest.php`, `ImportUgcMediaJobTest.php` (nuovi)

Casi da coprire (uno o più `it()` per file):
- `user_id` dell'UGC importato è l'autore reale GeoHub, **diverso** dall'owner dell'app (asserzione che fallisce se un refactor futuro reintroduce l'eredità di `BaseImportJob::transformData()`)
- Update-if-newer: un record esistente con `updated_at` GeoHub **non** più recente di `geohub_synced_at` locale non viene toccato; con `updated_at` più recente viene aggiornato
- Un UGC con `user_id` nullo su GeoHub viene scartato con warning, senza eccezione, senza bloccare gli altri record del batch
- Foto UGC allegata correttamente come media Spatie (collection `default`) sul `UgcPoi`/`UgcTrack` locale risolto tramite le pivot GeoHub
- `ImportUgcMediaJob` riprova quando il modello locale non è ancora presente, e logga il fallimento con `geohub_id` se il modello non compare entro i tentativi previsti
- Distinzione esplicita tra errore di rete (URL irraggiungibile) e content-type non immagine nel log prodotto da `ImportUgcMediaJob`
- Nuovo utente autore UGC senza ruoli pregressi riceve `Contributor`; un utente con un ruolo già assegnato manualmente non viene sovrascritto

**Commit:** `test(oc:8158): add Pest coverage for UGC import (author mapping, update-if-newer, media attach)`

## Note per l'esecuzione

- Verifica end-to-end manuale (`php artisan wm:import-from-geohub app 49 --dependencies=...,ugc_poi,ugc_track,ugc_media`) è a carico dello sviluppatore dopo il merge, non è un task automatizzabile in questo piano — richiede l'ambiente Docker attivo e le credenziali `GEOHUB_DB_*` configurate.
- Rischi esplicitamente accettati in overview.md (mismatch timezone Europe/Rome vs UTC, owner→Contributor mai promosso a Editor cross-app, nessun indice su `properties->geohub_id`, nessuna gestione media non fotografici) — **nessun task in questo piano li affronta**, per decisione esplicita del dev.
- `EcMediaImportService`/`ImportEcMediaJob` esistenti restano invariati in tutto il piano — ogni pattern riusato va reimplementato/estratto per gli UGC, non modificato in-place.
