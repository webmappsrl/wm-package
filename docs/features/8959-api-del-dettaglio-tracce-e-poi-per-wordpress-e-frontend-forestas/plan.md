> Ticket: oc:8959

# `properties.taxonomies` delle tracce — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** aggiungere al dettaglio di ogni EcTrack la chiave `properties.taxonomies`, l'unico punto da cui i frontend leggono le tassonomie, senza cambiare le chiavi vecchie; le etichette di tassonomie e tab stanno una volta sola nel `config.json` dell'app.

**Architecture:** `EcTrack::geojsonTaxonomies()` raccoglie quattro fonti (tassonomie storiche, zone filtrate, `taxonomy_terms` accese per app e modello) nella forma «identifier tassonomia → array di termini con `tab`». `GeoJsonService::getModelAsGeojson()` la aggiunge solo se il modello ha il metodo, come già fa con `geojsonFlatProperties()`: così arriva al file su AWS, a `/api/ec/track/{id}` e a `multiple`, e non tocca POI e layer. `TaxonomyTermableObserver` rigenera il file su AWS quando cambia un legame di una traccia.

**Tech Stack:** Laravel 12, PHP >8.1, PostgreSQL/PostGIS, Pest, spatie/laravel-translatable.

**Spec:** `docs/features/8959-api-del-dettaglio-tracce-e-poi-per-wordpress-e-frontend-forestas/overview.md`

## Global Constraints

- Tutto il codice è in `wm-package/`; forestas non cambia in questo pezzo.
- Identificatori in inglese; commenti, docblock e documentazione in italiano.
- PHP `>8.1`: niente `const` dentro un trait.
- Tabella opzionale: `Schema::hasTable('taxonomy_terms')`, mai un `catch` su `QueryException`.
- `properties.taxonomy`, `properties.taxonomy_where`, `properties.taxonomyWheres` restano byte per byte uguali.
- Chiavi fisse delle storiche: `activity`, `theme`, `poi_type`, `where`. `when` e `target` non entrano.
- Tab: `properties->tab` della tassonomia per `taxonomy_terms`, `'tab'` di `config('wm-package.legacy_taxonomies')` per `activity`, altrimenti `General` (`theme`, `poi_type`, `where`, tassonomie senza tab).
- Identifier riservati per una tassonomia (`parent_id` null): `activity`, `theme`, `where`.
- **Nessun `git commit`, `git add`, `git push` o branch eseguito da chi implementa**: i passi «Commit» sono istruzioni per il dev.
- Test: si lanciano dal container dello shard, dalla root di forestas, con `docker exec php-forestas vendor/bin/pest wm-package/tests/<percorso>`. Isolamento verificato il 2026-11-10: `phpunit.xml` → `DB_DATABASE=forestas_testing`, `.env.testing` → `DB_DATABASE=forestas_testing`, il database esiste. Riverificare prima del primo lancio; se non è verificabile, non lanciare.
- Nei test: tassonomie create nel test con identifier `prefisso-`.`uniqid()` e `DatabaseTransactions`; tracce con `'osmid' => null`; un `Bus::fake([...])` locale ripete `UpdateUgcTrackDemStatsJob` (regole in `wm-package/.claude/rules/test.md`).

## Review Focus

1. **Traccia senza nessuna tassonomia** → `taxonomies` deve uscire come oggetto JSON `{}`, non come array `[]`: un `array()` PHP vuoto con `json_encode` diventa `[]` e il frontend che fa `Object.keys`/accesso per chiave si confonde. Test in Task 2.
2. **Zone con chiavi tecniche** (`_source`, `_admin_level`, e qualunque futura chiave con `_`) → non devono comparire in `name`: si tengono solo le chiavi che non iniziano con `_` e hanno valore stringa. Test in Task 2.
3. **Tassonomia accesa nell'app ma solo per `EcPoi`** con un termine attaccato a una traccia → non esce. Test in Task 2.
4. **Import che silenzia Scout** (`withoutSyncingToSearch`) e scrive molte relazioni → non deve accodare un `UpdateEcTrackAwsJob` per legame. Test in Task 3.
5. **Termine storico con `identifier` null** → escluso, senza errori. Test in Task 2.

---

### Task 1: identifier riservati per le tassonomie

**Files:**
- Modify: `wm-package/src/Models/TaxonomyTerm.php` (costante + controllo nel `saving`, accanto a quello di `TAXONOMY_IDENTIFIER_FORMAT`, righe ~58-70)
- Test: `wm-package/tests/Feature/Models/TaxonomyTermReservedIdentifierTest.php`

**Interfaces:**
- Produces: `TaxonomyTerm::RESERVED_TAXONOMY_IDENTIFIERS = ['activity', 'theme', 'where']` (public const), usata in Task 2 come elenco delle chiavi delle storiche.

- [ ] **Step 1: test che falliscono**

```php
it('rifiuta una tassonomia con identifier riservato', function (string $identifier) {
    expect(fn () => TaxonomyTerm::create(['identifier' => $identifier, 'name' => ['it' => 'x']]))
        ->toThrow(InvalidArgumentException::class);
})->with(['activity', 'theme', 'where']);

it('accetta un termine figlio con identifier uguale a un nome riservato', function () {
    $taxonomy = TaxonomyTerm::create(['identifier' => 'tx-'.uniqid(), 'name' => ['it' => 'x']]);
    $term = TaxonomyTerm::create(['identifier' => 'activity', 'parent_id' => $taxonomy->id, 'name' => ['it' => 'x']]);
    expect($term->exists)->toBeTrue();
});

it('rifiuta lo spostamento di un termine a tassonomia con identifier riservato', function () {
    // termine 'where' senza legami, parent_id portato a null → eccezione
});
```

- [ ] **Step 2:** `docker exec php-forestas vendor/bin/pest wm-package/tests/Feature/Models/TaxonomyTermReservedIdentifierTest.php` → FAIL (nessuna eccezione).
- [ ] **Step 3:** nel `saving`, nella stessa condizione che oggi controlla il formato (tassonomia, identifier dirty/nuovo/`parent_id` cambiato), `throw new InvalidArgumentException("L'identifier \"{$id}\" è riservato alle tassonomie storiche nel dettaglio delle tracce (oc:8959).")` se `in_array($identifier, self::RESERVED_TAXONOMY_IDENTIFIERS, true)`. Docblock della costante: perché (`properties.taxonomies` usa le stesse chiavi) e perché `poi_type` non c'è (il formato non ammette `_`).
- [ ] **Step 4:** stesso comando → PASS; rilanciare anche `wm-package/tests/Feature/Models/TaxonomyTermDeleteGuardTest.php` → PASS.
- [ ] **Step 5: Commit** — `feat(oc:8959): identifier riservati per le tassonomie del dettaglio`

### Task 2: `EcTrack::geojsonTaxonomies()` e aggancio in `GeoJsonService`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2--dove-si-costruisce-taxonomies)


**Files:**
- Modify: `wm-package/src/Models/EcTrack.php` (metodo nuovo accanto a `termsForSearch()`, riga ~793)
- Modify: `wm-package/src/Services/GeoJsonService.php` (righe 16-21 e blocco `$taxonomy`, righe 46-56)
- Modify: `wm-package/src/Models/Abstracts/GeometryModel.php` (solo docblock `@deprecated` su `applyTaxonomyWhereDisplay`, riga ~224)
- Test: `wm-package/tests/Feature/EcTrackGeojsonTaxonomiesTest.php`

**Interfaces:**
- Consumes: `TaxonomyTerm::RESERVED_TAXONOMY_IDENTIFIERS` (Task 1).
- Produces: `EcTrack::geojsonTaxonomies(): array<string, list<array{identifier: string, name: array<string,string>, tab: string}>>`; `GeoJsonService` scrive `properties['taxonomies']` (stdClass se vuoto).

- [ ] **Step 1: test che falliscono** (fixture: `App::factory()`, traccia con `insertEcTrackWithGeometry($app, ['taxonomy_where' => [...]])` di `InsertsGeometryFixtures`, `osmid` null; `uses(TestCase::class, DatabaseTransactions::class, InsertsGeometryFixtures::class)` come `TaxonomyWhere/TaxonomyWhereDisplayOutputTest.php`)

```php
it('raggruppa i termini di taxonomy_terms per identifier della tassonomia, con il tab della tassonomia')
// tassonomia 'tx-…' con properties ['tab' => 'Activities'], accesa per l'app con models ['EcTrack'],
// termine attaccato → $props['taxonomies']['tx-…'] === [['identifier' => …, 'name' => ['it' => …], 'tab' => 'Activities']]

it('usa General quando la tassonomia non dichiara un tab')

it('esclude le tassonomie spente nell’app o accese solo per EcPoi')   // Review Focus 3

it('mette activity con il tab di legacy_taxonomies')
// attività attaccata → taxonomies['activity'][0]['tab'] === config('wm-package.legacy_taxonomies')[0]['tab'] ('Activities')

it('mette theme e poi_type con tab General e scarta i termini storici senza identifier')   // Review Focus 5

it('costruisce where dalle zone filtrate da Località mostrate, senza chiavi tecniche')     // Review Focus 2
// app con properties ['taxonomy_where_display' => ['4']], taxonomy_where = wheresFixture-like con
// '_admin_level' 4 e 8 → taxonomies['where'] === [['identifier' => 'R40784', 'name' => ['it' => 'Lazio', 'en' => 'Lazio'], 'tab' => 'General']]

it('non scrive when né target e non scrive tassonomie assenti')

it('scrive taxonomies come oggetto JSON vuoto quando la traccia non ha tassonomie')        // Review Focus 1
// json_encode($track->getGeojson()['properties']) contiene '"taxonomies":{}'

it('lascia invariate taxonomy, taxonomy_where e taxonomyWheres')
// stesso output di prima: confronto con le chiavi costruite a mano come in GeoJsonService oggi

it('non aggiunge taxonomies ai POI')
// EcPoi::getGeojson()['properties'] non ha la chiave 'taxonomies'

it('arriva in EcTrackResource e in multiple')
// (new EcTrackResource($track))->toArray(request())['properties'] ha 'taxonomies';
// GET route('default.api.ec.track.multiple', ['ids' => $track->id]) → features.0.properties.taxonomies presente
```

- [ ] **Step 2:** `docker exec php-forestas vendor/bin/pest wm-package/tests/Feature/EcTrackGeojsonTaxonomiesTest.php` → FAIL.
- [ ] **Step 3:** implementare `public function geojsonTaxonomies(): array` in `EcTrack`:
  - storiche, in quest'ordine: `activity` ← `taxonomyActivities` (tab: `'tab'` della voce di `legacy_taxonomies` con `'relation' => 'taxonomyActivities'`, default `General`), `theme` ← `taxonomyThemes`, `poi_type` ← `taxonomyPoiTypes` (tab `General`); `name` = `getTranslations('name')`; termini con `identifier` null scartati;
  - `where` ← `TaxonomyWhereDisplayService::filter($this->properties['taxonomy_where'] ?? [], selectedCategoriesForApp($this->app_id))`; per ogni chiave: `identifier` = chiave (string), `name` = le voci con chiave che non inizia per `_` e valore stringa non vuoto, `tab` `General`;
  - `taxonomy_terms`, solo se `Schema::hasTable('taxonomy_terms')`: tassonomie abilitate = `TaxonomyTerm::query()->taxonomies()->forAppAndModel($this->app_id, 'EcTrack')->pluck('id')`, poi come `termsForSearch()` (padre vivo, salto dei termini senza padre); `tab` = `data_get($term->parent->properties, 'tab')` se stringa non vuota, altrimenti `General`;
  - una chiave senza termini non si scrive.
  Il default `'General'` va in una costante della classe (`EcTrack::DEFAULT_TAXONOMY_TAB`), con docblock che rimanda al default di `BuildsTaxonomyFields`.
- [ ] **Step 4:** in `GeoJsonService::getModelAsGeojson()`, subito dopo il blocco `$taxonomy`: se `method_exists($model, 'geojsonTaxonomies')`, `$properties['taxonomies'] = $t === [] ? new \stdClass : $t`. Commento: perché qui e non in `EcTrackResource` (tre uscite), perché un metodo sul modello (POI e layer invariati). Aggiungere `@deprecated` (commento sopra il blocco `$taxonomy` e sopra `taxonomyWheres`, docblock di `applyTaxonomyWhereDisplay` per la parte che scrive `taxonomy_where`/`taxonomyWheres`): «deprecate per le tracce: leggere `properties.taxonomies` (oc:8959); restano per retrocompatibilità».
- [ ] **Step 5:** stesso comando → PASS; poi `docker exec php-forestas vendor/bin/pest wm-package/tests/Feature/TaxonomyWhere` → PASS.
- [ ] **Step 6: Commit** — `feat(oc:8959): properties.taxonomies nel dettaglio delle tracce`

### Task 3: l'observer rigenera il file su AWS della traccia

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3--rigenerazione-dallobserver)


**Files:**
- Modify: `wm-package/src/Observers/TaxonomyTermableObserver.php` (metodo `reindex`, righe 29-74)
- Test: `wm-package/tests/Feature/Models/TaxonomyTermableObserverTest.php` (aggiungere casi)

**Interfaces:**
- Consumes: `UpdateEcTrackAwsJob(EcTrack $ecTrack)`.

- [ ] **Step 1: test che falliscono** (`Bus::fake([UpdateEcTrackAwsJob::class, UpdateUgcTrackDemStatsJob::class])`, helper `seedTaxonomyTermAndTrack()` di `tests/Pest.php`, `fakeScoutEngine()` già nel file)

```php
it('accoda UpdateEcTrackAwsJob per la traccia quando nasce o sparisce un legame')
// attach → Bus::assertDispatched(UpdateEcTrackAwsJob::class, fn ($job) => job riferito a $track); detach → idem

it('non accoda UpdateEcTrackAwsJob quando il sync a Scout è disattivato')   // Review Focus 4
// EcTrack::withoutSyncingToSearch(fn () => $track->taxonomyTerms()->attach(...)) → assertNotDispatched

it('non accoda UpdateEcTrackAwsJob per un POI')
```

  Per leggere la traccia del job nel test: la proprietà è `protected $ecTrack`; usare una closure `fn ($job) => (fn () => $this->ecTrack)->call($job)->is($track)`.
- [ ] **Step 2:** `docker exec php-forestas vendor/bin/pest wm-package/tests/Feature/Models/TaxonomyTermableObserverTest.php` → FAIL sui casi nuovi.
- [ ] **Step 3:** in `reindex()`, dopo il controllo `ModelObserver::syncingDisabledFor($class)` e il `find`: se `$model instanceof EcTrack`, `UpdateEcTrackAwsJob::dispatch($model)`, fuori dal `try` del `searchable()` (un Elasticsearch giù non deve impedire la rigenerazione). Commento: perché (il dettaglio su AWS porta `taxonomies`, oc:8959) e perché dopo `syncingDisabledFor` (un import non accoda un job per legame).
- [ ] **Step 4:** stesso comando → PASS (anche i casi esistenti).
- [ ] **Step 5: Commit** — `feat(oc:8959): rigenera il dettaglio su AWS quando cambiano le tassonomie della traccia`

### Task 5: etichette di tassonomie e tab nel `config.json`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-5--etichette-nel-configjson)


> Aggiunto dopo l'approvazione del piano, su richiesta del dev (le etichette servono, ma una volta
> sola per app invece che su ogni termine).

**Files:**
- Modify: `wm-package/src/Models/EcTrack.php` (estrarre la mappa delle storiche in una costante condivisa)
- Modify: `wm-package/src/Services/Models/App/AppConfigService.php` (nuovo `config_section_taxonomies()`, aggiunto in `config()`)
- Modify: `wm-package/src/Models/TaxonomyTerm.php` (`saved`/`deleted` di una tassonomia → `UpdateAppConfigJob` per le app accese, `afterCommit()`)
- Test: `wm-package/tests/Feature/AppConfigTaxonomiesTest.php`

**Interfaces:**
- Produces: `EcTrack::LEGACY_TAXONOMIES` = `['activity' => ['relation' => 'taxonomyActivities', 'label' => null], 'theme' => ['relation' => 'taxonomyThemes', 'label' => 'Themes'], 'poi_type' => ['relation' => 'taxonomyPoiTypes', 'label' => 'Poi Types']]` (label `null` = quella di `legacy_taxonomies`); `EcTrack::taxonomyTabFor(string $legacyKey): string`; `config.json` → `TAXONOMY_LABELS: array<string, array<string,string>>`, `TAXONOMY_TAB_LABELS: array<string, array<string,string>>`.

- [ ] **Step 1: test che falliscono**
  - `config()['TAXONOMY_LABELS']` ha `activity`, `theme`, `poi_type`, `where` e la tassonomia accesa per l'app con `models` `['EcTrack']`, con le traduzioni del nome; non ha quella accesa solo per `EcPoi` né quella spenta.
  - Le storiche vengono prima, poi le tassonomie per `sort_order`.
  - nomi delle storiche: `activity` → traduzioni di `Activities`, `where` → di `Zones` (`it` = «Zone»); i tab elencati in `TAXONOMY_TAB_LABELS` sono quelli delle tassonomie presenti.
  - `config()['TAXONOMY_TAB_LABELS']` ha `General` come prima chiave, e `General.it` = «Generale», `Activities.it` = «Attività».
  - Salvare il `name` di una tassonomia accesa accoda `UpdateAppConfigJob` per la sua app (`Bus::fake`), dopo il commit; salvare un **termine** (con `parent_id`) non lo accoda.
- [ ] **Step 2:** `docker exec -w /var/www/html/forestas/wm-package php-forestas vendor/bin/pest tests/Feature/AppConfigTaxonomiesTest.php` → FAIL.
- [ ] **Step 3:** implementare. Traduzioni di una chiave: per ogni locale di `config('wm-tab-translatable.locales')`, `Lang::hasForLocale($key, $locale)` → `__($key, [], $locale)`; se manca `en`, `en` = la chiave. `geojsonTaxonomies()` usa la stessa costante e lo stesso `taxonomyTabFor()`, così tab e chiavi coincidono fra dettaglio e config.
- [ ] **Step 4:** stesso comando → PASS; poi i test di Task 2 e i test esistenti di `AppConfigService` (`tests/Feature/AppConfig*`) → PASS.
- [ ] **Step 5: Commit** — `feat(oc:8959): etichette di tassonomie e tab nel config.json dell'app`

### Task 6: `taxonomies` nei POI di `pois.geojson`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-6--poi)


> Aggiunto su richiesta del dev, a pezzo delle tracce finito.

**Files:**
- Create: `wm-package/src/Services/DetailTaxonomiesService.php` (spostati da `EcTrack`: `LEGACY_TAXONOMIES`, `WHERE_TAXONOMY`, `DEFAULT_TAXONOMY_TAB`, `legacyTaxonomyTab()`, `legacyTaxonomyLabel()`, `hasTaxonomyTermsTables()`, costruzione dei termini)
- Modify: `wm-package/src/Models/EcTrack.php` (delega al servizio), `src/Models/EcPoi.php` (`geojsonTaxonomies(?int $appId)`), `src/Models/App.php` (`getAllPoisGeojson()`), `src/Services/Models/App/AppConfigService.php` (costanti dal servizio), `src/Jobs/App/ReindexAppContentsJob.php` (`BuildAppPoisGeojsonJob`)
- Test: `wm-package/tests/Feature/PoisGeojsonTaxonomiesTest.php`

**Interfaces:**
- Produces: `DetailTaxonomiesService::build(Model $model, string $modelScope, ?int $appId): array`; il servizio memorizza le tassonomie accese per `(appId, modelScope)` per la durata dell'istanza.

- [ ] **Step 1: test che falliscono** — POI con attività, tipo POI, zona e termine di una tassonomia accesa per `EcPoi`: `properties.taxonomies` del file ha le quattro chiavi; una tassonomia accesa solo per `EcTrack` non esce; POI senza tassonomie → `{}`; `taxonomyIdentifiers` uguale a prima; `ReindexAppContentsJob` accoda `BuildAppPoisGeojsonJob` al cambio.
- [ ] **Step 2:** `docker exec -w /var/www/html/forestas/wm-package php-forestas vendor/bin/pest tests/Feature/PoisGeojsonTaxonomiesTest.php` → FAIL.
- [ ] **Step 3:** implementare; i test delle tracce (`EcTrackGeojsonTaxonomiesTest`, `AppConfigTaxonomyLabelsTest`) restano verdi senza cambiare le aspettative.
- [ ] **Step 4:** regressione e PHPStan sulle righe nuove.
- [ ] **Step 5: Commit** — `feat(oc:8959): taxonomies nei POI di pois.geojson`

### Task 4: documentazione del formato

**Files:**
- Create: `wm-package/docs/knowledge/tassonomie-nel-dettaglio-di-tracce-e-poi.md`
- Modify: `wm-package/CLAUDE.md` (una riga nella tabella `## Conoscenza`)

- [ ] **Step 1:** scrivere la pagina con la struttura `## Come funziona oggi` / `## Perché così` / `## Come ci siamo arrivati`: forma di `taxonomies` con un esempio reale di dev, fonti di ogni chiave e del `tab`, ordine delle chiavi, `{}` quando vuota, uscite che la portano (file AWS, `/api/ec/track/{id}`, `multiple`), rigenerazione dall'observer e caso scoperto (rinomina di un termine o cambio di `tab` → rigenerare a mano), **tabella delle chiavi deprecate** (`taxonomy.activity`, `taxonomy.when`, `taxonomy.poi_type`, `taxonomy.poi_types`, `taxonomy_where`, `taxonomyWheres`) con la chiave di `taxonomies` da leggere al loro posto, identifier riservati. In «Perché così»: chiave nuova e non estensione di `taxonomy` (tipi diversi, altri shard), forma ad array con `tab` per termine (select nel WP, lista piatta nel frontend), `tab` dalle fonti di Nova, niente `taxonomy_name`, `target`/`when` esclusi — ognuna con (oc:8959).
- [ ] **Step 2:** riga nel `CLAUDE.md` del package: `| Tassonomie nel dettaglio delle tracce | `properties.taxonomies`, fonti e tab come Nova, chiavi deprecate, rigenerazione su AWS | [docs/knowledge/tassonomie-nel-dettaglio-di-tracce-e-poi.md](docs/knowledge/tassonomie-nel-dettaglio-di-tracce-e-poi.md) |`
- [ ] **Step 3:** `vendor/bin/phpstan analyse` dal container (`docker exec php-forestas vendor/bin/phpstan analyse`) → nessun errore nuovo sui file toccati.
- [ ] **Step 4: Commit** — `docs(oc:8959): formato di properties.taxonomies e chiavi deprecate`

Dopo il merge nel package: bump del submodule in forestas e rigenerazione dei file su AWS delle tracce di dev (a cura del team).
