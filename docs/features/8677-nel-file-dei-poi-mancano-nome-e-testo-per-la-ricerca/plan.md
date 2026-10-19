> Ticket: oc:8677

# Nome e testo di ricerca nei POI importati — piano di implementazione

> **Per chi esegue:** sotto-skill richiesta: `superpowers:subagent-driven-development` (consigliata)
> oppure `superpowers:executing-plans`. I passi usano le checkbox (`- [ ]`).
> **Nessun `git commit`, `git add` o `git push` durante l'esecuzione**: i passi «Commit» sono
> istruzioni per il dev, che committa dopo la review.

**Obiettivo:** l'import da Geohub produce POI e tracce con `properties.name`, e `pois.geojson` ha
`name` e `searchable` per ogni POI, così la ricerca dell'app trova i POI come su Geohub.

**Architettura:** la regola «`name` → `properties.name`» diventa un metodo statico pubblico di
`AbstractObserver`, usato dall'observer e da `persistQuietly`. `searchable` si calcola solo in
`App::getAllPoisGeojson()`, con `EcPoi::getSearchableString()` corretto. Il file si rigenera a fine
import dei POI e al cambio di `poi_searchables`.

**Tecnologie:** Laravel 12, PHP 8.4, Spatie Translatable, Pest, PostgreSQL/PostGIS.

**Spec:** [overview.md](overview.md)

## Vincoli globali

- Repo: solo **wm-package**, branch `feature/oc-8677-nel-file-dei-poi-mancano-nome-e-testo-per-la-ricerca`. In maphub nessun file.
- PHP minimo del package `>8.1`: niente `const` nei trait.
- Test lanciati da maphub: `docker exec php-maphub vendor/bin/pest wm-package/tests/<percorso>`.
  Usano il DB del `.env` di maphub: **sempre `DatabaseTransactions`, mai `RefreshDatabase`**, e
  ogni test crea i record che usa (nomi e `geohub_id` con `uniqid()`).
- I test estendono `Tests\TestCase` (quello del consumer), altrimenti da maphub falliscono con
  `Target class [config] does not exist`.
- App nei test: `App::factory()->createQuietly([...])`; `config(['wm-package.shard_name' => 'test_shard'])`
  e `Storage::fake('pois')`, `Storage::fake('wmfe')`, `Storage::fake('conf')` come in
  `tests/Unit/Models/AppGetAllPoisGeojsonGlobalTest.php`.
- `EcPoi::factory()` con `'properties' => []` esplicito (la factory ha `properties` come stringa
  JSON, doppia serializzazione); `EcTrack::factory()` con `'osmid' => null`.
- Formato atteso da wm-core (`utils.ts:56-57`): `properties.name` oggetto `{lang: testo}`,
  `properties.searchable` stringa.
- `composer format` riformatta tutto il repo: lanciare Pint **solo sui file toccati**
  (`vendor/bin/pint <file>...` da `wm-package/`).
- Commenti e messaggi di commit in italiano, scope `fix(oc:8677): …`.

## Punti da guardare in review

1. **POI senza descrizione né estratto né tipi** — `searchable` contiene solo il nome, senza `[]`
   né spazi doppi in testa. Test nel Task 2.
2. **`poi_searchables` salvato come oggetto JSON** (`{"name":true}`) — il POI resta nel file con
   `searchable` calcolato su tutti i campi, nessuna eccezione. Test nel Task 2; un errore qualsiasi nel calcolo lascia il POI nel file, test nel Task 3.
3. **Nome con lingue a `null` o vuote** (`{"it":"X","de":null}`) — `properties.name` e il `name`
   del file sono `{"it":"X"}`. Test nei Task 1 e 3.
4. **Descrizione con HTML** (`<p>Chiesa <b>romanica</b></p>`) — in `searchable` il testo senza tag
   («Chiesa romanica»). Test nel Task 2.
5. **Salvataggio dell'app che non tocca `poi_searchables`** — nessuna rigenerazione del file. Test
   nel Task 4.

---

### Task 1: regola `properties.name` riusabile e applicata dall'import

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1-regola-propertiesname-riusabile-e-applicata-dallimport)

**File:**
- Modifica: `src/Observers/AbstractObserver.php`
- Modifica: `src/Services/Import/GeohubImportService.php:303-308` (`persistQuietly`)
- Test: `tests/Feature/Import/GeohubImportNameInPropertiesTest.php`

**Interfacce:**
- Produce: `AbstractObserver::syncNameIntoProperties(Model $model): void` (statico, pubblico).
  Se `'name'` è in `$model->translatable`, scrive `getTranslations('name')` in
  `properties['name']`; altrimenti non fa nulla. `AbstractObserver::saving()` lo chiama al posto
  del corpo attuale.

- [x] **Passo 1: scrivere i test che falliscono**

  In `GeohubImportNameInPropertiesTest` (`DatabaseTransactions`, `Tests\TestCase`):
  - `test_sync_name_into_properties_copia_le_traduzioni_su_un_poi`: `EcPoi` non salvato con
    `name = ['it' => 'Chiesa '.uniqid(), 'en' => 'Church', 'de' => null]` e `properties = []`;
    dopo `AbstractObserver::syncNameIntoProperties($poi)`,
    `$poi->properties['name'] === ['it' => …, 'en' => 'Church']` (senza `de`).
  - `test_sync_name_into_properties_su_una_traccia`: stesso controllo su un `EcTrack`.
  - `test_import_data_scrive_properties_name_sul_poi`: con il servizio ottenuto come in
    `tests/Feature/ImportEcPoiJobTaxonomySyncTest.php` (concern `SharesGeohubConnectionWithLocal`,
    `InjectsGeohubImportService`, `DisablesForeignKeyConstraints`), chiama
    `importData(['name' => ['it' => 'Cathédrale '.uniqid()], 'geometry' => <punto PostGIS>, 'app_id' => <app creata>, 'user_id' => <utente>, 'properties' => ['geohub_id' => $id]], 'ec_poi', EcPoi::class, $id)`
    con `$id = random_int(900000000, 999999999)`; riletto da DB, `properties['name']['it']` è il
    nome passato e `properties['geohub_id'] === $id`.

- [x] **Passo 2: lanciarli e vederli fallire**

  `docker exec php-maphub vendor/bin/pest wm-package/tests/Feature/Import/GeohubImportNameInPropertiesTest.php`
  Atteso: FAIL (`syncNameIntoProperties` non esiste; `properties.name` assente dopo `importData`).

- [x] **Passo 3: implementare**

  `syncNameIntoProperties` in `AbstractObserver`, `saving()` lo chiama. In `persistQuietly`,
  `AbstractObserver::syncNameIntoProperties($model)` prima di `unsetEventDispatcher()`. Aggiornare
  il docblock di `persistQuietly`: gli observer restano spenti, la regola sul nome si applica
  comunque (oc:8677).

- [x] **Passo 4: lanciarli e vederli passare**

  Stesso comando. Atteso: PASS.

- [ ] **Passo 5: commit (istruzione per il dev)** — da fare dopo la review: in esecuzione non si committa

  ```bash
  git add src/Observers/AbstractObserver.php src/Services/Import/GeohubImportService.php tests/Feature/Import/GeohubImportNameInPropertiesTest.php
  git commit -m "fix(oc:8677): l'import da Geohub scrive properties.name come un salvataggio normale"
  ```

### Task 2: `EcPoi::getSearchableString()` corretto

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2-getsearchablestring-corretto)

**File:**
- Modifica: `src/Models/EcPoi.php:9` (import) e `:335-366` (`getSearchableString`)
- Test: `tests/Unit/Models/EcPoiSearchableStringTest.php`

**Interfacce:**
- Produce: `EcPoi::getSearchableString(App|int|null $app = null): string`. `App` è
  `Wm\WmPackage\Models\App`; un `int` viene risolto con `App::find()` (compatibilità con chi passa
  l'id); `null` o `0` = nessuna app = tutti i campi.

  Regole, identiche a Geohub salvo le correzioni:
  - lista: `json_decode($app->poi_searchables, true)` se è una stringa, l'array se è già un array;
    una lista vuota, `null`, o un valore che non è una lista di stringhe = tutti i campi;
  - campi in quest'ordine: `name` (colonna, `getTranslations`), `description` ed `excerpt`
    (da `properties`, solo lingue non vuote, `strip_tags`), `osmid`, nomi tradotti di
    `taxonomyPoiTypes`;
  - ogni campo è serializzato come oggi su Geohub (`json_encode` con virgolette tolte, per esempio
    `{it:Chiesa,en:Church}`) ma con `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`;
  - un campo vuoto non aggiunge nulla (niente `[]`); i pezzi sono uniti da uno spazio;
  - risultato passato da `html_entity_decode` e `trim`.
- L'import `use Illuminate\Support\Facades\App;` diventa `use Wm\WmPackage\Models\App;`.
  Verificare con `grep -n "App::" src/Models/EcPoi.php` che nessun altro uso del file dipendesse
  dalla facade.

- [x] **Passo 1: scrivere i test che falliscono**

  POI creati con `EcPoi::factory()->createQuietly(['properties' => [...], 'osmid' => null, ...])`,
  app con `App::factory()->createQuietly(['poi_searchables' => …])`:
  - `test_con_app_non_va_in_errore`: `getSearchableString($app)` e `getSearchableString($app->id)`
    restituiscono una stringa.
  - `test_lista_vuota_include_nome_descrizione_estratto_e_tipi`: `poi_searchables = '[]'`,
    `name = ['it' => 'Cattedrale']`, `properties.description = ['it' => '<p>Chiesa <b>romanica</b></p>']`,
    `properties.excerpt = ['it' => 'Breve']`, un `TaxonomyPoiType` creato nel test con
    `name = ['it' => 'Luoghi di fede']` e identifier `'test-'.uniqid()`; il risultato contiene
    `Cattedrale`, `Chiesa romanica`, `Breve`, `Luoghi di fede` e non contiene `<p>`.
  - `test_solo_name_esclude_la_descrizione`: `poi_searchables = '["name"]'`; contiene il nome, non
    la descrizione.
  - `test_mantiene_gli_accenti`: nome `['fr' => 'Cathédrale Notre Dame de la Nativité']`; il
    risultato contiene `Cathédrale` e non contiene `\u00e9`.
  - `test_campi_vuoti_non_aggiungono_parentesi`: POI con solo il nome; il risultato non contiene
    `[]` e non inizia né finisce con uno spazio.
  - `test_lista_non_valida_vale_come_tutti_i_campi`: `poi_searchables = '{"name":true}'`; nessuna
    eccezione, il risultato contiene nome e descrizione.

- [x] **Passo 2: lanciarli e vederli fallire**

  `docker exec php-maphub vendor/bin/pest wm-package/tests/Unit/Models/EcPoiSearchableStringTest.php`
  Atteso: FAIL, il primo con `BadMethodCallException: Method Illuminate\Foundation\Application::find does not exist`.

- [x] **Passo 3: implementare `getSearchableString` secondo le regole sopra**

- [x] **Passo 4: lanciarli e vederli passare.** Atteso: PASS.

- [ ] **Passo 5: commit (istruzione per il dev)** — da fare dopo la review: in esecuzione non si committa

  ```bash
  git add src/Models/EcPoi.php tests/Unit/Models/EcPoiSearchableStringTest.php
  git commit -m "fix(oc:8677): getSearchableString dei POI legge properties, mantiene gli accenti e non va in errore"
  ```

### Task 3: `name` e `searchable` in `pois.geojson`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-name-e-searchable-in-poisgeojson)

**File:**
- Modifica: `src/Models/App.php:245-291` (`getAllPoisGeojson`)
- Test: `tests/Unit/Models/AppPoisGeojsonNameSearchableTest.php`

**Interfacce:**
- Consuma: `EcPoi::getSearchableString(App|int|null $app = null): string` (Task 2).
- Produce: `public function searchableForPoi(EcPoi $poi): string` in `App`: restituisce
  `$poi->getSearchableString($this)`; su qualsiasi `\Throwable` restituisce `''` e scrive
  `Log::warning("oc:8677 searchable non calcolato per il POI {$poi->id}: ".$e->getMessage())`.
- Nel ciclo, dopo `taxonomyIdentifiers`:
  - `$item['properties']['name'] = $poi->getTranslations('name');`
  - `$item['properties']['searchable'] = $this->searchableForPoi($poi);`
  Il `catch (\Exception)` esterno resta com'è.

- [x] **Passo 1: scrivere i test che falliscono** (setup come `AppGetAllPoisGeojsonGlobalTest`)
  - `test_ogni_poi_ha_name_e_searchable`: app con due POI (uno con `properties = []`, cioè senza
    `properties.name`, come un POI importato); in `getAllPoisGeojson()` entrambi hanno
    `properties.name` array uguale a `getTranslations('name')` e `properties.searchable` stringa
    che contiene il nome.
  - `test_name_senza_lingue_nulle`: `name = ['it' => 'X '.uniqid(), 'de' => null]` →
    `properties.name === ['it' => 'X …']`.
  - `test_errore_nel_searchable_restituisce_stringa_vuota`: `$poi = Mockery::mock(EcPoi::class)->makePartial();`
    con `getSearchableString` che lancia `new \RuntimeException('boom')` e `id = 1`;
    `$app->searchableForPoi($poi) === ''`, nessuna eccezione. Il ciclo usa questo metodo, quindi
    un errore non arriva al `catch` esterno che scarta il POI.

- [x] **Passo 2: lanciarli e vederli fallire**

  `docker exec php-maphub vendor/bin/pest wm-package/tests/Unit/Models/AppPoisGeojsonNameSearchableTest.php`
  Atteso: FAIL (`searchable` assente; `name` assente sul POI senza `properties.name`).

- [x] **Passo 3: implementare.**

- [ ] **Passo 4: lanciarli e vederli passare**, insieme a
  `wm-package/tests/Unit/Models/AppGetAllPoisGeojsonGlobalTest.php`. Atteso: PASS.

- [ ] **Passo 5: commit (istruzione per il dev)** — da fare dopo la review: in esecuzione non si committa

  ```bash
  git add src/Models/App.php tests/Unit/Models/AppPoisGeojsonNameSearchableTest.php
  git commit -m "fix(oc:8677): pois.geojson contiene name e searchable per ogni POI"
  ```

### Task 4: rigenerare `pois.geojson` al cambio di `poi_searchables`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-4-rigenerare-poisgeojson-al-cambio-di-poi_searchables)

**File:**
- Modifica: `src/Observers/AppObserver.php` (`saved()`)
- Test: `tests/Feature/Observers/AppObserverPoiSearchablesTest.php`

**Interfacce:**
- In `saved()`: se `! $app->wasRecentlyCreated && $app->wasChanged('poi_searchables')`,
  `BuildAppPoisGeojsonJob::dispatch($app->id)`.

- [x] **Passo 1: scrivere i test che falliscono**

  `Bus::fake([BuildAppPoisGeojsonJob::class])`, `Storage::fake` come nei vincoli globali, app con
  `createQuietly(['poi_searchables' => '[]', 'native_app_deep_link_enabled' => false])`:
  - `test_cambio_di_poi_searchables_rigenera_il_file`: `$app->update(['poi_searchables' => '["name"]'])`
    → `Bus::assertDispatched(BuildAppPoisGeojsonJob::class)` una volta.
  - `test_salvataggio_senza_cambio_non_rigenera`: `$app->update(['name' => ['it' => 'Altro '.uniqid()]])`
    → `Bus::assertNotDispatched(BuildAppPoisGeojsonJob::class)`.

- [x] **Passo 2: lanciarli e vederli fallire**

  `docker exec php-maphub vendor/bin/pest wm-package/tests/Feature/Observers/AppObserverPoiSearchablesTest.php`
  Atteso: il primo FAIL, il secondo PASS.

- [x] **Passo 3: implementare.** **Passo 4:** stesso comando, atteso PASS.

- [ ] **Passo 5: commit (istruzione per il dev)** — da fare dopo la review: in esecuzione non si committa

  ```bash
  git add src/Observers/AppObserver.php tests/Feature/Observers/AppObserverPoiSearchablesTest.php
  git commit -m "fix(oc:8677): pois.geojson si rigenera quando cambia la ricerca dei POI dell'app"
  ```

### Task 5: rigenerare `pois.geojson` a fine import dei POI

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-5-rigenerare-poisgeojson-a-fine-import-dei-poi)

**File:**
- Modifica: `src/Jobs/Import/ImportAppJob.php:500-513`
- Test: `tests/Feature/Import/ImportAppJobPoisGeojsonRebuildTest.php`

**Interfacce:**
- Produce: `public static function dispatchAfterEcImportBatch(string $entityModelKey, int $appId): void`
  in `ImportAppJob`:
  - `ec_poi` → `Bus::chain([new SyncTaxonomyWhereJob, new BuildAppPoisGeojsonJob($appId)])->dispatch()`
    (il file usa le località, quindi si costruisce dopo il loro ricalcolo);
  - `ec_track` → `SyncTaxonomyWhereJob::dispatch()` come oggi;
  - altri valori → nulla.
- Il `finally()` di `attachBatchCompletionCallback` chiama
  `ImportAppJob::dispatchAfterEcImportBatch($entityModelKey, $appId)` (la closure deve catturare
  `$entityModelKey` e `$appId`). Aggiornare il commento sopra il blocco citando oc:8677.

- [x] **Passo 1: scrivere i test che falliscono** (`Bus::fake()`)
  - `test_fine_import_poi_ricalcola_localita_e_poi_rigenera_il_file`:
    `dispatchAfterEcImportBatch('ec_poi', 42)` →
    `Bus::assertChained([SyncTaxonomyWhereJob::class, fn (BuildAppPoisGeojsonJob $j) => <appId 42>])`
    (l'`appId` è `protected`: verificarlo con `uniqueId() === 'build_pois_geojson_42'`).
  - `test_fine_import_tracce_ricalcola_solo_le_localita`: `'ec_track'` →
    `Bus::assertDispatched(SyncTaxonomyWhereJob::class)` e
    `Bus::assertNotDispatched(BuildAppPoisGeojsonJob::class)`.
  - `test_altre_entita_non_lanciano_nulla`: `'layer'` → `Bus::assertNothingDispatched()`.

- [x] **Passo 2: lanciarli e vederli fallire**

  `docker exec php-maphub vendor/bin/pest wm-package/tests/Feature/Import/ImportAppJobPoisGeojsonRebuildTest.php`
  Atteso: FAIL (metodo inesistente).

- [x] **Passo 3: implementare.** **Passo 4:** stesso comando, atteso PASS.

- [ ] **Passo 5: commit (istruzione per il dev)** — da fare dopo la review: in esecuzione non si committa

  ```bash
  git add src/Jobs/Import/ImportAppJob.php tests/Feature/Import/ImportAppJobPoisGeojsonRebuildTest.php
  git commit -m "fix(oc:8677): a fine import dei POI si rigenera pois.geojson dell'app"
  ```

### Task 6: l'import porta `poi_searchables` e `track_searchables`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-6-limport-porta-poi_searchables-e-track_searchables)

**File:**
- Modifica: `config/wm-geohub-import.php:12-19` (mapping `app`, `fields`)
- Test: `tests/Unit/Import/GeohubImportAppSearchablesMappingTest.php`

**Interfacce:**
- Aggiungere `'poi_searchables' => 'poi_searchables'` e `'track_searchables' => 'track_searchables'`.
  Il valore Geohub è già la lista JSON con le stesse opzioni (Geohub `app/Nova/App.php:977-984`).
  Se il valore letto da Geohub arriva come array PHP invece che come stringa, aggiungere un
  transformer che lo codifica in JSON: la colonna in wm-package è letta con `json_decode`
  (`src/Nova/App.php:772-773`).

- [x] **Passo 1: scrivere il test che fallisce**

  `test_mapping_app_copia_le_liste_di_ricerca`: con il servizio ottenuto come nel Task 1,
  `transformFields(['name' => …, 'poi_searchables' => '["name","description"]', 'track_searchables' => '["name"]', …], 'app')`
  restituisce `poi_searchables === '["name","description"]'` e `track_searchables === '["name"]'`.

- [x] **Passo 2:** `docker exec php-maphub vendor/bin/pest wm-package/tests/Unit/Import/GeohubImportAppSearchablesMappingTest.php`, atteso FAIL.

- [x] **Passo 3: implementare.** **Passo 4:** stesso comando, atteso PASS.

- [ ] **Passo 5: commit (istruzione per il dev)** — non serve: il task è stato annullato, vedi notes.md

  ```bash
  git add config/wm-geohub-import.php tests/Unit/Import/GeohubImportAppSearchablesMappingTest.php
  git commit -m "fix(oc:8677): l'import da Geohub copia la configurazione della ricerca dell'app"
  ```

### Task 7: verifica complessiva

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-7-verifica-complessiva)

- [ ] **Passo 1:** tutti i test nuovi e quelli vicini:
  `docker exec php-maphub vendor/bin/pest wm-package/tests/Feature/Import wm-package/tests/Unit/Models wm-package/tests/Feature/Observers wm-package/tests/Unit/Import wm-package/tests/Feature/ImportEcPoiJobTaxonomySyncTest.php`
  Atteso: PASS, nessun test esistente rotto.
- [x] **Passo 2:** Pint sui soli file toccati, da `wm-package/`:
  `vendor/bin/pint <file toccati>`; poi `git status` per verificare che non abbia toccato altro.
- [x] **Passo 3:** PHPStan da maphub:
  `docker exec php-maphub vendor/bin/phpstan analyse` — nessun errore nuovo sui file toccati.
- [ ] **Passo 4 (dev, dopo il merge su dev):** restore del DB, nuovo import dell'app 28 lanciato dal
  dev, poi i controlli del requisito «Verifica sul dev» dell'overview.
