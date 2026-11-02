> Ticket: oc:8911

# Filtri POI: alcune categorie non disponibili — Piano di implementazione

> **Per chi esegue:** usare superpowers:executing-plans (o subagent-driven-development) task per
> task. **Nessun `git commit`/`git add`/`git push` durante l'esecuzione**: i commit indicati sono
> istruzioni per il dev, eseguite solo dopo il review-gate di `wm-plan`.

**Obiettivo:** il filtro "tipo di POI" della config dell'app elenca i tipi dei POI mostrati sulla
mappa (`app_id` + `global`), non quelli del proprietario dell'App, e si rigenera quando cambiano.

**Architettura:** il criterio dei POI della mappa diventa un metodo di `App` usato sia da
`getAllPoisGeojson()` sia da `AppConfigService`; gli observer che già rigenerano `pois.geojson`
mettono in coda anche `UpdateAppConfigJob`, dopo il commit.

**Stack:** Laravel, Eloquent, PostgreSQL/PostGIS, Pest.

**Spec:** [overview.md](overview.md)

## Vincoli globali

- PHP minimo `>8.1`; nessun `const` nei trait.
- Commenti e documentazione in italiano, termini tecnici in inglese.
- La struttura delle opzioni resta: `identifier` = `poi_type_<identifier>`, `name` (array delle
  traduzioni), `id`, `icon_name`; ordinamento per nome; chiavi `MAP.filters.poi_types` e
  `MAP.filters.poi_type` invariate.
- Il morph type salvato in `taxonomy_poi_typeables` per gli EcPoi è `App\Models\EcPoi` (morph map
  in `WmPackageServiceProvider.php:531`): usare `(new EcPoi)->getMorphClass()`, mai la classe PHP.
- Ogni `UpdateAppConfigJob::dispatch()` nuovo porta `->afterCommit()`.

**Comando test del package** (dalla root di camminiditalia, DB `wm_package`):

```bash
docker run --rm --network camminiditalia_default -e DB_HOST=postgres-camminiditalia \
  -v "$PWD/wm-package:/app" -w /app wm-phpfpm:8.4 vendor/bin/pest <file-o-filtro>
```

## Review Focus

1. **Tipo collegato a più POI di cui solo alcuni globali** → il tipo compare una volta sola
   (distinct). Test in Task 2.
2. **App senza nessun POI globale** → `options` è `[]` e la config si genera senza errori. Test in
   Task 2.
3. **Stesso tipo usato da POI di due App diverse** → l'App A non vede i tipi presenti solo nei
   POI dell'App B. Test in Task 2.
4. **EcPoi senza App** (`app_id` null) salvato o con tipo collegato → nessun dispatch, nessun
   errore. Test in Task 3.
5. **`filter_poi_type` spento** → nessuna chiave `poi_types` in `MAP.filters` (comportamento di
   oggi). Test in Task 2.

---

### Task 1: `App::globalEcPois()`

**File:**
- Modifica: `src/Models/App.php` (nuovo metodo vicino a `getAllPoisGeojson()`, righe ~254-265)
- Test: `tests/Feature/AppConfigServicePoiTypesFilterTest.php` (nuovo, condiviso con Task 2)

**Interfacce:**
- Produce: `public function globalEcPois(): \Illuminate\Database\Eloquent\Relations\HasMany` —
  `$this->ecPois()` con `where('global', true)` se `Schema::hasColumn('ec_pois', 'global')`.
  Docblock: è il criterio unico dei POI mostrati sulla mappa, usato anche dal filtro (oc:8911);
  se la colonna `global` non esiste restituisce tutti i POI dell'App.

- [ ] **Step 1: test** `it('globalEcPois returns only global ec pois of the app')`: App A e App B
      (`App::factory()->createQuietly()`), EcPoi `createQuietly` con `properties => []`,
      `osmid => null`: P1 (A, global), P2 (A, non global), P3 (B, global). Atteso:
      `$appA->globalEcPois()->pluck('id')->all()` è `[P1->id]`.
- [ ] **Step 2:** eseguire il test → FAIL (metodo inesistente).
- [ ] **Step 3:** implementare `globalEcPois()` e sostituire in `getAllPoisGeojson()` le righe
      `$query = $this->ecPois(); if (Schema::hasColumn(...)) {...}` con
      `$query = $this->globalEcPois();` (il `with('taxonomyPoiTypes')` resta).
- [ ] **Step 4:** eseguire il test → PASS; eseguire anche i test esistenti che coprono
      `getAllPoisGeojson` (`vendor/bin/pest --filter=PoisGeojson`) → PASS.

---

### Task 2: filtro poi type su `globalEcPois()`

**File:**
- Modifica: `src/Services/Models/App/AppConfigService.php:628-666` (blocco «Poi type Filter»)
- Test: `tests/Feature/AppConfigServicePoiTypesFilterTest.php`

**Interfacce:**
- Consuma: `App::globalEcPois()` (Task 1).

Helper nel file di test: `attachPoiType(EcPoi $poi, TaxonomyPoiType $type)` che inserisce con
`DB::table('taxonomy_poi_typeables')->insert([...])` usando `(new EcPoi)->getMorphClass()` (niente
observer); i tipi si creano con `TaxonomyPoiType::create(['identifier' => 'oc8911-'.uniqid(),
'name' => ['it' => '...']])`. App con `filter_poi_type => true` e `user_id` = utente "owner";
POI con `user_id` = un altro utente. Lettura: `(new AppConfigService($app))->config()['MAP']['filters']['poi_types']['options']`.

- [ ] **Step 1: test**
  - `it('includes poi types of global app pois owned by another user')` → identifier
    `poi_type_<id del tipo>` presente; stessa opzione in `filters.poi_type`.
  - `it('excludes poi types used only by non global pois')`.
  - `it('excludes poi types used only by pois of another app')`.
  - `it('lists a poi type once when several pois share it')` → conteggio 1.
  - `it('keeps option shape and name ordering')` → opzioni con chiavi esattamente
    `identifier, name, id, icon_name`, `name` uguale all'array delle traduzioni, ordine per nome.
  - `it('returns empty options when the app has no global pois')`.
  - `it('omits poi types filter when filter_poi_type is off')` → `MAP.filters` senza `poi_types`.
- [ ] **Step 2:** eseguire → i primi due FAIL (criterio `user_id`).
- [ ] **Step 3:** riscrivere la query: `TaxonomyPoiType::query()->toBase()` (valori grezzi, `name`
      resta la stringa JSON come oggi) con `select('id','identifier','name','icon')`,
      `whereIn('id', DB::table('taxonomy_poi_typeables')->select('taxonomy_poi_type_id')
      ->where('taxonomy_poi_typeable_type', (new EcPoi)->getMorphClass())
      ->whereIn('taxonomy_poi_typeable_id', $this->app->globalEcPois()->select('ec_pois.id')))`,
      `orderBy('name')`. Rimuovere `$app_user_id` e il commento TODO obsoleto; il `try/catch` e il
      `foreach` di costruzione delle opzioni restano.
- [ ] **Step 4:** eseguire il file di test → PASS; eseguire `vendor/bin/pest --filter=AppConfig`
      → PASS.

---

### Task 3: rigenerazione della config dagli observer

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-rigenerazione-della-config-dagli-observer)

**File:**
- Modifica: `src/Observers/TaxonomyPoiTypeablesObserver.php` (`handleTaxonomyAssignment`)
- Modifica: `src/Observers/EcPoiObserver.php` (`saved` ~riga 62-66, `deleted` ~riga 35-41)
- Test: `tests/Feature/EcPoiAppConfigRegenerationTest.php` (nuovo)

**Interfacce:**
- Consuma: `UpdateAppConfigJob(int $appId)` esistente (`src/Jobs/UpdateAppConfigJob.php`).

- [ ] **Step 1: test** con `Bus::fake([UpdateAppConfigJob::class, BuildAppPoisGeojsonJob::class])`
      (il `TestCase` del package mette già lo store `redis` su `array`):
  - `it('queues app config update after commit when a poi type is attached')` →
    `$poi->taxonomyPoiTypes()->attach($type->id)`; `Bus::assertDispatched(UpdateAppConfigJob::class,
    fn ($j) => $j->appId === $app->id && $j->afterCommit === true)`.
  - `it('... when a poi type is detached')`.
  - `it('... when an ec poi is saved')` → `$poi->save()` dopo un cambio di `global`.
  - `it('... when an ec poi is deleted')` → POI senza tracce collegate.
  - `it('does not queue app config update for an ec poi without app')` → `app_id => null`, se lo
    schema lo permette; altrimenti documentare in `notes.md` che il caso non è costruibile.
- [ ] **Step 2:** eseguire → FAIL.
- [ ] **Step 3:** aggiungere `UpdateAppConfigJob::dispatch($appId)->afterCommit();` accanto a ogni
      `BuildAppPoisGeojsonJob::dispatch(...)` dei tre punti, con un commento di una riga: il
      filtro poi type dipende dagli stessi dati di `pois.geojson`; `afterCommit` perché la coda
      Redis dei consumer ha `after_commit = false` (oc:8911).
- [ ] **Step 4:** eseguire il file → PASS; eseguire i test esistenti degli observer EcPoi
      (`vendor/bin/pest --filter=EcPoi`) → PASS.

---

### Task 4: documentazione e verifica completa

**File:**
- Modifica: `docs/knowledge/configurazione-app-e-config-json.md` — sezione sul filtro poi type:
  criterio `globalEcPois()`, rigenerazione dagli observer con `afterCommit`; debito noto:
  `AppController::getFeaturesByAppAndTerm` ramo `poi_type` ancora su `user_id` (nessun frontend lo
  chiama, i backend non verificati) e `BulkEditAction` con `saveQuietly()` che non rigenera né
  config né `pois.geojson`.

- [ ] **Step 1:** scrivere la sezione (prima/dopo mostrati al dev, come da `wm-plan`).
- [ ] **Step 2:** `composer format` sui soli file toccati (poi `git status`, scartare il resto).
- [ ] **Step 3:** PHPStan del package sui file toccati → nessun errore nuovo.
- [ ] **Step 4:** suite di camminiditalia:
      `docker exec laravel-camminiditalia php artisan test` → stessi esiti del `main` (il job ora
      gira in sincrono in più test).
- [ ] **Step 5 (istruzione per il dev, dopo approvazione):**
      `fix(oc:8911): filtro tipi POI della config sui POI mostrati sulla mappa` in `wm-package`,
      PR verso `develop`; dopo il merge, in camminiditalia bump del gitlink e riga nel `CLAUDE.md`.

---

## Dopo il deploy su Cammini d'Italia (manuale)

1. `docker exec php-camminiditalia php artisan tinker --execute="\Wm\WmPackage\Jobs\UpdateAppConfigJob::dispatch(1);"`
2. Leggere il `config.json` dell'App 1 su S3: `MAP.filters.poi_types.options` contiene 85 tipi
   (numero da ricalcolare in quel momento con la query di `globalEcPois()`), fra cui
   `poi_type_accomodation` e `poi_type_camping`.
3. Nell'app, Rotta dei Due Mari: nei Filtri compaiono "Area tende" e "Struttura ricettiva".
