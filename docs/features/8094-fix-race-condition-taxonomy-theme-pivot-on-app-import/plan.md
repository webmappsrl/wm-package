# Fix race condition taxonomy theme pivot on app import — Implementation Plan

> Ticket: oc:8094

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminare la race condition per cui i pivot `taxonomy_themeables`/`taxonomy_poi_typeables`/`taxonomy_activityables` risultano vuoti dopo un import GeoHub, facendo sincronizzare a `ImportEcTrackJob`/`ImportEcPoiJob` le proprie associazioni taxonomy subito dopo aver creato il modello locale, invece di dipendere da un job taxonomy separato che gira in un batch parallelo indipendente senza garanzia d'ordine.

**Architecture:** Due canali, entrambi lato "figlio" (il job che importa EcTrack/EcPoi), attivabili/disattivabili con un unico config flag: per `EcTrack`+`theme` si riusa la colonna `themes` già presente nel payload GeoHub del track (stesso schema di `activities`, già sfruttato); per tutte le coppie `EcPoi`+`{theme,poi_type,activity}` (nessuna colonna embedded su GeoHub) si introduce un nuovo metodo generico in `GeohubImportService` che interroga direttamente la pivot table GeoHub per il `geohub_id` del figlio. I job taxonomy dedicati (`ImportTaxonomyThemeJob`/`ImportTaxonomyPoiTypeJob`/`ImportTaxonomyActivityJob`) restano invariati come rete di sicurezza idempotente.

**Tech Stack:** Laravel 12, PHP 8.4, PostgreSQL (query dirette su connessione `geohub`), Pest/PHPUnit (stile class-based `Tests\TestCase`, come da convenzione esistente nel package).

**Spec:** `docs/features/8094-fix-race-condition-taxonomy-theme-pivot-on-app-import/overview.md` (in questa stessa directory, wm-package)

## Global Constraints

- Nessun file nel repo principale maphub — la feature è interamente in `wm-package` (submodule).
- Commit convention: `feat(oc:8094): ...` / `fix(oc:8094): ...` / `test(oc:8094): ...`. Nessun commit/branch automatico durante l'esecuzione del piano — i comandi `git` nei task sono istruzioni testuali per l'utente.
- I job taxonomy dedicati (`ImportTaxonomyThemeJob`, `ImportTaxonomyPoiTypeJob`, `ImportTaxonomyActivityJob`, `ImportTaxonomyJob::processDependencies()`) restano **invariati** in tutto il piano.
- Nessuna modifica a `Layer`, `EcMedia` (out of scope, vedi overview). `ImportAppJob::queueEntityImport()` invariato nei Task 1-3 — modificato solo nel Task 4 (scoping import taxonomy, aggiunto durante l'implementazione su richiesta esplicita del dev, vedi overview "Aggiunta emersa durante l'implementazione").
- Ogni nuovo blocco di sync taxonomy lato figlio è avvolto in un try/catch dedicato che logga e **non propaga** l'eccezione al job chiamante.
- Ogni nuovo blocco di sync taxonomy lato figlio rispetta il flag di config `wm-geohub-import.child_side_taxonomy_sync.enabled` (default `true`).
- Log del nuovo meccanismo sempre prefissati `[child_side_sync]` per essere distinguibili/circoscrivibili.
- Test in stile class-based `Tests\TestCase` + `DatabaseTransactions` + trait `SharesGeohubConnectionWithLocal` (`tests/Concerns/SharesGeohubConnectionWithLocal.php`), stesso pattern di `tests/Feature/GeohubImportServiceAssociateLayerPoiTest.php`.

---

## Task 1: Metodo generico `GeohubImportService::getTaxonomyRecordsForMorphable()`

**Files:**
- Modify: `wm-package/src/Services/Import/GeohubImportService.php` (aggiungere il nuovo metodo pubblico, vicino a `getTaxonomyMorphableRecords()` intorno alla riga 881; aggiungere anche il commento inline su `MODEL_IMPORT_ORDER`, righe 38-47)
- Test: `wm-package/tests/Feature/GeohubImportServiceTaxonomyRecordsForMorphableTest.php`

**Interfaces:**
- Consumes: `$this->dbConnection` (proprietà protetta, connessione Laravel `geohub`, già esistente), `$this->importMapping` (proprietà protetta, da `config('wm-geohub-import.import_mapping')`, già esistente), `$this->extractPivotData(object $relation, array $pivotColumns): array` (metodo protetto già esistente, righe 1302-1312)
- Produces: `public function getTaxonomyRecordsForMorphable(string $taxonomyModelKey, string $morphableType, int $morphableGeohubId): \Illuminate\Support\Collection` — ritorna una Collection di modelli Taxonomy locali (es. `TaxonomyTheme`, `TaxonomyPoiType`, `TaxonomyActivity`) risolti per `properties->geohub_id`; ogni elemento ha `->pivot_data` (array) impostato quando la relation config dichiara `pivot_columns` (es. per `taxonomy_activity`). Ritorna `collect()` vuota se non ci sono pivot GeoHub per quel morphable o se nessuna taxonomy locale risolve. Usata da Task 2 (`ImportEcPoiJob`).

- [ ] **Step 1: Scrivi il test che fallisce**

Crea `wm-package/tests/Feature/GeohubImportServiceTaxonomyRecordsForMorphableTest.php`:

```php
<?php

namespace Wm\WmPackage\Tests\Feature;

require_once __DIR__.'/../Concerns/SharesGeohubConnectionWithLocal.php';

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wm\WmPackage\Services\Import\GeohubImportService;
use Wm\WmPackage\Tests\Concerns\SharesGeohubConnectionWithLocal;

class GeohubImportServiceTaxonomyRecordsForMorphableTest extends TestCase
{
    use DatabaseTransactions, SharesGeohubConnectionWithLocal;

    private GeohubImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shareGeohubConnectionWithLocal();

        $this->service = app(GeohubImportService::class);
    }

    public function test_resolves_local_theme_associated_with_a_geohub_poi(): void
    {
        $geohubPoiId = 12001;

        $themeId = DB::table('taxonomy_themes')->insertGetId([
            'name' => json_encode(['it' => 'Test Theme']),
            'identifier' => 'test-theme-'.uniqid(),
            'properties' => json_encode(['geohub_id' => 5001]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('taxonomy_themeables')->insert([
            'taxonomy_theme_id' => 5001,
            'taxonomy_themeable_type' => 'App\\Models\\EcPoi',
            'taxonomy_themeable_id' => $geohubPoiId,
        ]);

        $results = $this->service->getTaxonomyRecordsForMorphable('taxonomy_theme', 'App\\Models\\EcPoi', $geohubPoiId);

        $this->assertCount(1, $results);
        $this->assertEquals($themeId, $results->first()->id);
    }

    public function test_returns_empty_collection_when_no_geohub_pivot_rows_exist(): void
    {
        $results = $this->service->getTaxonomyRecordsForMorphable('taxonomy_theme', 'App\\Models\\EcPoi', 999999);

        $this->assertCount(0, $results);
    }

    public function test_skips_pivot_row_when_local_taxonomy_not_yet_imported(): void
    {
        $geohubPoiId = 12002;

        // Nessuna taxonomy_themes locale con geohub_id 5002: simula taxonomy non ancora importata
        DB::table('taxonomy_themeables')->insert([
            'taxonomy_theme_id' => 5002,
            'taxonomy_themeable_type' => 'App\\Models\\EcPoi',
            'taxonomy_themeable_id' => $geohubPoiId,
        ]);

        $results = $this->service->getTaxonomyRecordsForMorphable('taxonomy_theme', 'App\\Models\\EcPoi', $geohubPoiId);

        $this->assertCount(0, $results);
    }

    public function test_resolves_activity_with_pivot_columns_populated(): void
    {
        $geohubTrackId = 12003;

        $activityId = DB::table('taxonomy_activities')->insertGetId([
            'name' => json_encode(['it' => 'Test Activity']),
            'identifier' => 'test-activity-'.uniqid(),
            'properties' => json_encode(['geohub_id' => 5003]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('taxonomy_activityables')->insert([
            'taxonomy_activity_id' => 5003,
            'taxonomy_activityable_type' => 'App\\Models\\EcTrack',
            'taxonomy_activityable_id' => $geohubTrackId,
            'duration_forward' => 120,
            'duration_backward' => 90,
        ]);

        $results = $this->service->getTaxonomyRecordsForMorphable('taxonomy_activity', 'App\\Models\\EcTrack', $geohubTrackId);

        $this->assertCount(1, $results);
        $this->assertEquals($activityId, $results->first()->id);
        $this->assertEquals(['duration_forward' => 120, 'duration_backward' => 90], $results->first()->pivot_data);
    }

    public function test_ignores_pivot_rows_for_a_different_morphable_type(): void
    {
        $geohubId = 12004;

        DB::table('taxonomy_themes')->insertGetId([
            'name' => json_encode(['it' => 'Other Type Theme']),
            'identifier' => 'test-theme-other-'.uniqid(),
            'properties' => json_encode(['geohub_id' => 5004]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('taxonomy_themeables')->insert([
            'taxonomy_theme_id' => 5004,
            'taxonomy_themeable_type' => 'App\\Models\\EcTrack',
            'taxonomy_themeable_id' => $geohubId,
        ]);

        // Interroghiamo per EcPoi con lo stesso geohub_id: la riga è per EcTrack, non deve matchare
        $results = $this->service->getTaxonomyRecordsForMorphable('taxonomy_theme', 'App\\Models\\EcPoi', $geohubId);

        $this->assertCount(0, $results);
    }
}
```

- [ ] **Step 2: Esegui il test per verificare che fallisca**

Run: `docker exec -it php-maphub vendor/bin/phpunit vendor/wm/wm-package/tests/Feature/GeohubImportServiceTaxonomyRecordsForMorphableTest.php`
Expected: FAIL — `Call to undefined method Wm\WmPackage\Services\Import\GeohubImportService::getTaxonomyRecordsForMorphable()`

- [ ] **Step 3: Implementa il metodo**

In `wm-package/src/Services/Import/GeohubImportService.php`, subito dopo il metodo `getTaxonomyMorphableRecords()` (dopo la riga che chiude quel metodo, prima di `associateLayersWithTaxonomy()`):

```php
    /**
     * Get the taxonomy records (resolved locally) associated on Geohub with a single morphable
     * record, given the morphable's own geohub_id. Symmetric to getTaxonomyMorphableRecords():
     * that method starts from a local taxonomy and finds its morphable children (used by the
     * dedicated taxonomy import jobs); this one starts from a single child already known to
     * exist locally and finds its taxonomies — used to sync taxonomy pivots from inside the
     * child's own import job (e.g. ImportEcPoiJob), right after the child model exists, for
     * morphables whose Geohub row has no embedded taxonomy column (e.g. ec_pois).
     *
     * @param  string  $taxonomyModelKey  The taxonomy model key (e.g. 'taxonomy_theme')
     * @param  string  $morphableType  The literal Geohub morphable type string (e.g. 'App\Models\EcPoi')
     * @param  int  $morphableGeohubId  The Geohub id of the child record
     * @return Collection The locally resolved taxonomy models, each with ->pivot_data set when
     *                     the relation config declares pivot_columns
     */
    public function getTaxonomyRecordsForMorphable(string $taxonomyModelKey, string $morphableType, int $morphableGeohubId): Collection
    {
        $relations = $this->importMapping[$taxonomyModelKey]['relations'];
        $morphableTable = $relations['morphable_table'];
        $foreignKey = $relations['foreign_key'];
        $morphableIdKey = $relations['morphable_id'];
        $morphableTypeKey = $relations['morphable_type'];
        $pivotColumns = $relations['pivot_columns'] ?? [];

        $pivotRecords = $this->dbConnection->table($morphableTable)
            ->where($morphableTypeKey, $morphableType)
            ->where($morphableIdKey, $morphableGeohubId)
            ->get();

        if ($pivotRecords->isEmpty()) {
            return collect();
        }

        $taxonomyModelClass = $this->importMapping[$taxonomyModelKey]['namespace'];
        $taxonomyGeohubIds = $pivotRecords->pluck($foreignKey)->unique()->values();

        $taxonomies = $taxonomyModelClass::whereIn('properties->geohub_id', $taxonomyGeohubIds)
            ->get()
            ->keyBy(fn ($taxonomy) => $taxonomy->properties['geohub_id'] ?? null);

        $results = collect();

        foreach ($pivotRecords as $pivotRecord) {
            $taxonomy = $taxonomies->get($pivotRecord->{$foreignKey});

            if (! $taxonomy) {
                Log::warning("[child_side_sync] Taxonomy not yet imported locally: {$taxonomyModelKey} geohub_id={$pivotRecord->{$foreignKey}} (morphable_type={$morphableType}, morphable_geohub_id={$morphableGeohubId})");

                continue;
            }

            if (! empty($pivotColumns)) {
                $taxonomy->pivot_data = $this->extractPivotData($pivotRecord, $pivotColumns);
            }

            $results->push($taxonomy);
        }

        return $results;
    }
```

Verifica che `use Illuminate\Support\Collection;` e `use Illuminate\Support\Facades\Log;` siano già importati in cima al file (già usati da `getTaxonomyMorphableRecords()` e da altri metodi della classe) — se assenti, aggiungili.

Nella stessa modifica, aggiungi il commento inline su `MODEL_IMPORT_ORDER` (righe 38-47 circa): subito sopra la dichiarazione della costante, aggiungi:

```php
    /**
     * NOTA: quest'ordine è rispettato solo da importAll() (comando CLI sequenziale). Il path di
     * produzione reale, ImportAppJob::queueEntityImport(), dispatcha un Bus::batch() indipendente
     * per ciascuna dipendenza senza alcun chaining .then() tra i batch — questa costante NON è
     * una garanzia d'ordine per gli import triggerati da ImportAppJob (vedi oc:8094).
     */
    const MODEL_IMPORT_ORDER = [
```

(mantenendo invariato il contenuto dell'array esistente dopo la riga `const MODEL_IMPORT_ORDER = [`)

- [ ] **Step 4: Esegui il test per verificare che passi**

Run: `docker exec -it php-maphub vendor/bin/phpunit vendor/wm/wm-package/tests/Feature/GeohubImportServiceTaxonomyRecordsForMorphableTest.php`
Expected: PASS — tutti e 5 i test verdi

- [ ] **Step 5: Commit**

```bash
git -C wm-package add src/Services/Import/GeohubImportService.php tests/Feature/GeohubImportServiceTaxonomyRecordsForMorphableTest.php
git -C wm-package commit -m "feat(oc:8094): add generic child-side taxonomy pivot lookup to GeohubImportService"
```

---

## Task 2: Config flag + sync taxonomy lato figlio in `ImportEcPoiJob`

**Files:**
- Modify: `wm-package/config/wm-geohub-import.php` (aggiungere il nuovo top-level key `child_side_taxonomy_sync`)
- Modify: `wm-package/src/Jobs/Import/ImportEcPoiJob.php`
- Test: `wm-package/tests/Feature/ImportEcPoiJobTaxonomySyncTest.php`

**Interfaces:**
- Consumes: `GeohubImportService::getTaxonomyRecordsForMorphable(string $taxonomyModelKey, string $morphableType, int $morphableGeohubId): Collection` (Task 1); `$this->geohubImportService` (proprietà protetta ereditata da `BaseImportJob`, già popolata in `handle()`); `$this->entityId` (proprietà protetta ereditata, geohub_id del POI); `$this->importLogger(): LoggerInterface` (metodo protetto già esistente su `BaseImportJob`); relazioni Eloquent `EcPoi::taxonomyThemes()`, `EcPoi::taxonomyPoiTypes()`, `EcPoi::taxonomyActivities()` (già esistenti via trait `TaxonomyAbleModel`, nessuna modifica al modello necessaria)
- Produces: `ImportEcPoiJob::processDependencies(array $data, Model $model): void` (override, prima ereditava lo stub vuoto di `BaseEcImportJob`) — side effect: pivot `taxonomy_themeables`/`taxonomy_poi_typeables`/`taxonomy_activityables` locali popolati per l'EcPoi importato. Config key `wm-geohub-import.child_side_taxonomy_sync.enabled` (bool, default `true`) — consumato anche da Task 3.

- [ ] **Step 1: Scrivi il test che fallisce**

Crea `wm-package/tests/Feature/ImportEcPoiJobTaxonomySyncTest.php`:

```php
<?php

namespace Wm\WmPackage\Tests\Feature;

require_once __DIR__.'/../Concerns/SharesGeohubConnectionWithLocal.php';

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use Wm\WmPackage\Jobs\Import\ImportEcPoiJob;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Tests\Concerns\SharesGeohubConnectionWithLocal;

class ImportEcPoiJobTaxonomySyncTest extends TestCase
{
    use DatabaseTransactions, SharesGeohubConnectionWithLocal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shareGeohubConnectionWithLocal();
    }

    public function test_ec_poi_theme_pivot_is_populated_even_when_taxonomy_theme_job_never_ran(): void
    {
        // Riproduce l'ordine racy: la Taxonomy locale esiste già (dispatchata prima, come nel
        // flusso reale), ma il job ImportTaxonomyThemeJob dedicato non gira affatto in questo
        // test — solo il job EcPoi. Il pivot deve popolarsi comunque.
        $geohubPoiId = 20001;

        $themeId = DB::table('taxonomy_themes')->insertGetId([
            'name' => json_encode(['it' => 'Test Theme']),
            'identifier' => 'test-theme-'.uniqid(),
            'properties' => json_encode(['geohub_id' => 6001]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('taxonomy_themeables')->insert([
            'taxonomy_theme_id' => 6001,
            'taxonomy_themeable_type' => 'App\\Models\\EcPoi',
            'taxonomy_themeable_id' => $geohubPoiId,
        ]);

        $poi = EcPoi::factory()->createQuietly([
            'properties' => ['geohub_id' => $geohubPoiId],
        ]);

        $job = new ImportEcPoiJob($geohubPoiId);
        $method = new \ReflectionMethod($job, 'processDependencies');
        $method->setAccessible(true);
        $method->invoke($job, ['id' => $geohubPoiId], $poi);

        $this->assertTrue(
            $poi->taxonomyThemes()->where('taxonomy_themes.id', $themeId)->exists(),
            'Il pivot taxonomy_themeables locale deve essere popolato dal sync lato figlio, senza che il job taxonomy dedicato sia mai girato'
        );
    }

    public function test_ec_poi_poi_type_and_activity_pivots_are_populated(): void
    {
        $geohubPoiId = 20002;

        $poiTypeId = DB::table('taxonomy_poi_types')->insertGetId([
            'name' => json_encode(['it' => 'Test Poi Type']),
            'identifier' => 'test-poi-type-'.uniqid(),
            'properties' => json_encode(['geohub_id' => 6002]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('taxonomy_poi_typeables')->insert([
            'taxonomy_poi_type_id' => 6002,
            'taxonomy_poi_typeable_type' => 'App\\Models\\EcPoi',
            'taxonomy_poi_typeable_id' => $geohubPoiId,
        ]);

        $activityId = DB::table('taxonomy_activities')->insertGetId([
            'name' => json_encode(['it' => 'Test Activity']),
            'identifier' => 'test-activity-'.uniqid(),
            'properties' => json_encode(['geohub_id' => 6003]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('taxonomy_activityables')->insert([
            'taxonomy_activity_id' => 6003,
            'taxonomy_activityable_type' => 'App\\Models\\EcPoi',
            'taxonomy_activityable_id' => $geohubPoiId,
            'duration_forward' => 0,
            'duration_backward' => 0,
        ]);

        $poi = EcPoi::factory()->createQuietly([
            'properties' => ['geohub_id' => $geohubPoiId],
        ]);

        $job = new ImportEcPoiJob($geohubPoiId);
        $method = new \ReflectionMethod($job, 'processDependencies');
        $method->setAccessible(true);
        $method->invoke($job, ['id' => $geohubPoiId], $poi);

        $this->assertTrue($poi->taxonomyPoiTypes()->where('taxonomy_poi_types.id', $poiTypeId)->exists());
        $this->assertTrue($poi->taxonomyActivities()->where('taxonomy_activities.id', $activityId)->exists());
    }

    public function test_missing_local_taxonomy_is_logged_and_skipped_without_exception(): void
    {
        Log::spy();

        $geohubPoiId = 20003;

        // Pivot GeoHub presente, ma nessuna taxonomy_themes locale con geohub_id 6004
        DB::table('taxonomy_themeables')->insert([
            'taxonomy_theme_id' => 6004,
            'taxonomy_themeable_type' => 'App\\Models\\EcPoi',
            'taxonomy_themeable_id' => $geohubPoiId,
        ]);

        $poi = EcPoi::factory()->createQuietly([
            'properties' => ['geohub_id' => $geohubPoiId],
        ]);

        $job = new ImportEcPoiJob($geohubPoiId);
        $method = new \ReflectionMethod($job, 'processDependencies');
        $method->setAccessible(true);

        $method->invoke($job, ['id' => $geohubPoiId], $poi);

        $this->assertCount(0, $poi->taxonomyThemes);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, '[child_side_sync]'))->atLeast()->once();
    }

    public function test_sync_is_skipped_entirely_when_config_flag_disabled(): void
    {
        config(['wm-geohub-import.child_side_taxonomy_sync.enabled' => false]);

        $geohubPoiId = 20004;

        DB::table('taxonomy_themes')->insertGetId([
            'name' => json_encode(['it' => 'Disabled Flag Theme']),
            'identifier' => 'test-theme-disabled-'.uniqid(),
            'properties' => json_encode(['geohub_id' => 6005]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('taxonomy_themeables')->insert([
            'taxonomy_theme_id' => 6005,
            'taxonomy_themeable_type' => 'App\\Models\\EcPoi',
            'taxonomy_themeable_id' => $geohubPoiId,
        ]);

        $poi = EcPoi::factory()->createQuietly([
            'properties' => ['geohub_id' => $geohubPoiId],
        ]);

        $job = new ImportEcPoiJob($geohubPoiId);
        $method = new \ReflectionMethod($job, 'processDependencies');
        $method->setAccessible(true);
        $method->invoke($job, ['id' => $geohubPoiId], $poi);

        $this->assertCount(0, $poi->taxonomyThemes);
    }

    public function test_re_running_process_dependencies_does_not_duplicate_pivot(): void
    {
        $geohubPoiId = 20005;

        DB::table('taxonomy_themes')->insertGetId([
            'name' => json_encode(['it' => 'Idempotent Theme']),
            'identifier' => 'test-theme-idempotent-'.uniqid(),
            'properties' => json_encode(['geohub_id' => 6006]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('taxonomy_themeables')->insert([
            'taxonomy_theme_id' => 6006,
            'taxonomy_themeable_type' => 'App\\Models\\EcPoi',
            'taxonomy_themeable_id' => $geohubPoiId,
        ]);

        $poi = EcPoi::factory()->createQuietly([
            'properties' => ['geohub_id' => $geohubPoiId],
        ]);

        $job = new ImportEcPoiJob($geohubPoiId);
        $method = new \ReflectionMethod($job, 'processDependencies');
        $method->setAccessible(true);

        $method->invoke($job, ['id' => $geohubPoiId], $poi);
        $method->invoke($job, ['id' => $geohubPoiId], $poi);

        $this->assertCount(1, $poi->fresh()->taxonomyThemes);
    }
}
```

- [ ] **Step 2: Esegui il test per verificare che fallisca**

Run: `docker exec -it php-maphub vendor/bin/phpunit vendor/wm/wm-package/tests/Feature/ImportEcPoiJobTaxonomySyncTest.php`
Expected: FAIL — i pivot restano vuoti (`processDependencies()` è ancora lo stub ereditato)

- [ ] **Step 3: Aggiungi il config flag**

In `wm-package/config/wm-geohub-import.php`, subito dopo il blocco `'default_dependencies' => [...]` (prima di `'import_mapping' => [`), aggiungi:

```php
    /*
    |--------------------------------------------------------------------------
    | Child-side Taxonomy Sync
    |--------------------------------------------------------------------------
    |
    | Quando true (default), ImportEcTrackJob e ImportEcPoiJob sincronizzano le proprie
    | associazioni taxonomy (theme, poi_type, activity) subito dopo aver creato il modello
    | locale, invece di dipendere esclusivamente dai job taxonomy dedicati (che girano in un
    | batch parallelo indipendente, senza garanzia d'ordine rispetto ai batch ec_poi/ec_track —
    | vedi oc:8094). Disattivare rapidamente questo flag (senza deploy) se il meccanismo scrive
    | pivot errati durante un import massivo in produzione.
    |
    */
    'child_side_taxonomy_sync' => [
        'enabled' => env('WM_CHILD_SIDE_TAXONOMY_SYNC_ENABLED', true),
    ],
```

- [ ] **Step 4: Implementa `ImportEcPoiJob::processDependencies()`**

Sostituisci il contenuto di `wm-package/src/Jobs/Import/ImportEcPoiJob.php`:

```php
<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Database\Eloquent\Model;

class ImportEcPoiJob extends BaseEcImportJob
{
    /**
     * Maps each taxonomy model key to the local Eloquent relation used to attach it.
     *
     * @var array<string, string>
     */
    private const TAXONOMY_RELATIONS = [
        'taxonomy_theme' => 'taxonomyThemes',
        'taxonomy_poi_types' => 'taxonomyPoiTypes',
        'taxonomy_activity' => 'taxonomyActivities',
    ];

    protected function getModelKey(): string
    {
        return parent::getModelKey().'poi';
    }

    protected function getGeometryType(): string
    {
        return 'POINT Z';
    }

    protected function processDependencies(array $data, Model $model): void
    {
        if (! config('wm-geohub-import.child_side_taxonomy_sync.enabled', true)) {
            return;
        }

        foreach (self::TAXONOMY_RELATIONS as $taxonomyModelKey => $relationName) {
            $this->syncTaxonomyFromGeohubPivot($model, $taxonomyModelKey, $relationName);
        }
    }

    /**
     * Sync one taxonomy type's pivot for this EcPoi by querying the Geohub pivot table
     * directly for this record's own geohub_id — eliminates the dispatch-order race with the
     * dedicated taxonomy import jobs (see oc:8094). Errors are logged and swallowed: a Geohub
     * network blip on this sync must not fail the whole EcPoi import job.
     */
    private function syncTaxonomyFromGeohubPivot(Model $model, string $taxonomyModelKey, string $relationName): void
    {
        $logger = $this->importLogger();

        try {
            $taxonomies = $this->geohubImportService->getTaxonomyRecordsForMorphable(
                $taxonomyModelKey,
                'App\\Models\\EcPoi',
                $this->entityId
            );

            foreach ($taxonomies as $taxonomy) {
                $model->{$relationName}()->syncWithoutDetaching([$taxonomy->id => $taxonomy->pivot_data ?? []]);
                $logger->info("[child_side_sync] Attached {$taxonomyModelKey} ID {$taxonomy->id} to EcPoi ID {$model->id}");
            }
        } catch (\Throwable $e) {
            $logger->error("[child_side_sync] Failed to sync {$taxonomyModelKey} for EcPoi ID {$model->id}: {$e->getMessage()}", [
                'exception' => $e,
            ]);
        }
    }
}
```

- [ ] **Step 5: Esegui il test per verificare che passi**

Run: `docker exec -it php-maphub vendor/bin/phpunit vendor/wm/wm-package/tests/Feature/ImportEcPoiJobTaxonomySyncTest.php`
Expected: PASS — tutti e 5 i test verdi

- [ ] **Step 6: Commit**

```bash
git -C wm-package add config/wm-geohub-import.php src/Jobs/Import/ImportEcPoiJob.php tests/Feature/ImportEcPoiJobTaxonomySyncTest.php
git -C wm-package commit -m "feat(oc:8094): sync EcPoi taxonomy pivots from geohub_id, bypassing the racy taxonomy batch"
```

---

## Task 3: Sync `taxonomy_theme` in `ImportEcTrackJob` dalla colonna `themes`

**Files:**
- Modify: `wm-package/config/wm-geohub-import.php` (aggiungere `'themes' => 'themes'` a `import_mapping.ec_track.properties.mapping`)
- Modify: `wm-package/src/Jobs/Import/ImportEcTrackJob.php`
- Test: `wm-package/tests/Feature/ImportEcTrackJobThemeSyncTest.php`

**Interfaces:**
- Consumes: config flag `wm-geohub-import.child_side_taxonomy_sync.enabled` (Task 2); relazione Eloquent `EcTrack::taxonomyThemes()` (già esistente via trait `TaxonomyAbleModel`, nessuna modifica al modello); pattern esistente `ImportEcTrackJob::findTaxonomyByActivityType()` (generalizzato in questo task)
- Produces: `ImportEcTrackJob::processDependencies()` sincronizza anche `taxonomy_theme` (oltre a `taxonomy_activity`, invariato) — side effect: pivot `taxonomy_themeables` locale popolato per il track importato

- [ ] **Step 1: Scrivi il test che fallisce**

Crea `wm-package/tests/Feature/ImportEcTrackJobThemeSyncTest.php`:

```php
<?php

namespace Wm\WmPackage\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wm\WmPackage\Jobs\Import\ImportEcTrackJob;
use Wm\WmPackage\Models\EcTrack;

class ImportEcTrackJobThemeSyncTest extends TestCase
{
    use DatabaseTransactions;

    public function test_track_theme_pivot_is_populated_from_embedded_properties(): void
    {
        $themeId = DB::table('taxonomy_themes')->insertGetId([
            'name' => json_encode(['it' => 'Embedded Theme']),
            'identifier' => 'osm2cai-sda4-'.uniqid(),
            'properties' => json_encode(['geohub_id' => 7001]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $identifier = DB::table('taxonomy_themes')->where('id', $themeId)->value('identifier');

        $track = EcTrack::factory()->createQuietly([
            'properties' => [
                'geohub_id' => 30001,
                'themes' => json_encode(['7001' => [$identifier]]),
            ],
        ]);

        $job = new ImportEcTrackJob(30001);
        $method = new \ReflectionMethod($job, 'processDependencies');
        $method->setAccessible(true);
        $method->invoke($job, ['id' => 30001], $track);

        $this->assertTrue(
            $track->taxonomyThemes()->where('taxonomy_themes.id', $themeId)->exists(),
            'Il pivot taxonomy_themeables locale deve essere popolato leggendo properties[themes], come già avviene per activities'
        );
    }

    public function test_malformed_themes_json_does_not_throw(): void
    {
        $track = EcTrack::factory()->createQuietly([
            'properties' => [
                'geohub_id' => 30002,
                'themes' => 'not-valid-json{{{',
            ],
        ]);

        $job = new ImportEcTrackJob(30002);
        $method = new \ReflectionMethod($job, 'processDependencies');
        $method->setAccessible(true);

        // Non deve lanciare eccezioni né emettere warning PHP su foreach(null)
        $method->invoke($job, ['id' => 30002], $track);

        $this->assertCount(0, $track->taxonomyThemes);
    }

    public function test_theme_sync_is_skipped_when_config_flag_disabled(): void
    {
        config(['wm-geohub-import.child_side_taxonomy_sync.enabled' => false]);

        $themeId = DB::table('taxonomy_themes')->insertGetId([
            'name' => json_encode(['it' => 'Disabled Theme']),
            'identifier' => 'test-theme-disabled-track-'.uniqid(),
            'properties' => json_encode(['geohub_id' => 7003]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $identifier = DB::table('taxonomy_themes')->where('id', $themeId)->value('identifier');

        $track = EcTrack::factory()->createQuietly([
            'properties' => [
                'geohub_id' => 30003,
                'themes' => json_encode(['7003' => [$identifier]]),
            ],
        ]);

        $job = new ImportEcTrackJob(30003);
        $method = new \ReflectionMethod($job, 'processDependencies');
        $method->setAccessible(true);
        $method->invoke($job, ['id' => 30003], $track);

        $this->assertCount(0, $track->taxonomyThemes);
    }
}
```

- [ ] **Step 2: Esegui il test per verificare che fallisca**

Run: `docker exec -it php-maphub vendor/bin/phpunit vendor/wm/wm-package/tests/Feature/ImportEcTrackJobThemeSyncTest.php`
Expected: FAIL — nessun sync di `themes` esiste ancora

- [ ] **Step 3: Aggiungi il mapping `themes` in config**

In `wm-package/config/wm-geohub-import.php`, dentro `import_mapping.ec_track.properties.mapping`, subito dopo la riga `'activities' => 'activities',`, aggiungi:

```php
                    'themes' => 'themes',
```

- [ ] **Step 4: Generalizza `ImportEcTrackJob` per sincronizzare anche `themes`**

Sostituisci il contenuto di `wm-package/src/Jobs/Import/ImportEcTrackJob.php`:

```php
<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Models\Abstracts\Taxonomy;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\TaxonomyTheme;

class ImportEcTrackJob extends BaseEcImportJob
{
    protected function getModelKey(): string
    {
        return parent::getModelKey().'track';
    }

    protected function getGeometryType(): string
    {
        return 'MULTILINESTRING Z';
    }

    protected function processDependencies(array $data, Model $model): void
    {
        $ecPoiIdsWithOrder = $this->geohubImportService->getAssociatedEcPoisIDs($this->getModelKey(), $data['id']);

        $syncData = [];
        foreach ($ecPoiIdsWithOrder as $poiId => $order) {
            $syncData[$poiId] = ['order' => $order];
        }

        $model->ecPois()->sync($syncData);

        if (! config('wm-geohub-import.child_side_taxonomy_sync.enabled', true)) {
            return;
        }

        $this->syncTaxonomyFromEmbeddedProperty($model, 'activities', 'taxonomyActivities', TaxonomyActivity::class, [
            'duration_forward' => 0,
            'duration_backward' => 0,
        ]);

        $this->syncTaxonomyFromEmbeddedProperty($model, 'themes', 'taxonomyThemes', TaxonomyTheme::class);
    }

    /**
     * Sincronizza un tipo di taxonomy leggendo il campo JSON già presente nel payload Geohub
     * del track stesso (properties[$propertyKey], formato {geohub_taxonomy_id: [identifier,...]})
     * — evita la race condition coi job taxonomy dedicati, che dipendono invece dall'ordine dei
     * batch (vedi oc:8094). Errori sono loggati e ingoiati: non devono far fallire l'import del
     * track, già creato con successo.
     */
    private function syncTaxonomyFromEmbeddedProperty(
        Model $model,
        string $propertyKey,
        string $relationName,
        string $taxonomyClass,
        array $pivotData = []
    ): void {
        $logger = $this->importLogger();

        try {
            $decoded = json_decode($model->properties[$propertyKey] ?? '{}', true);
            $entries = is_array($decoded) ? $decoded : [];

            if (empty($entries)) {
                return;
            }

            foreach ($entries as $identifiers) {
                if (! is_array($identifiers)) {
                    continue;
                }

                foreach ($identifiers as $identifier) {
                    $taxonomy = $this->findTaxonomyByIdentifier($taxonomyClass, $identifier);

                    if (! $taxonomy) {
                        $logger->warning("[child_side_sync] Taxonomy not found for identifier '{$identifier}' ({$taxonomyClass}), track ID: {$model->id}");

                        continue;
                    }

                    $model->{$relationName}()->syncWithoutDetaching([$taxonomy->id => $pivotData]);
                    $logger->info("[child_side_sync] Attached {$taxonomyClass} ID {$taxonomy->id} to EcTrack ID {$model->id}");
                }
            }
        } catch (\Throwable $e) {
            $logger->error("[child_side_sync] Failed to sync {$propertyKey} for EcTrack ID {$model->id}: {$e->getMessage()}", [
                'exception' => $e,
            ]);
        }
    }

    /**
     * Trova la taxonomy per identifier in modo dinamico (match parziale case-insensitive), con
     * fallback su properties->geohub_id se il valore non è un identifier testuale.
     */
    private function findTaxonomyByIdentifier(string $taxonomyClass, string $identifier): ?Taxonomy
    {
        $taxonomy = $taxonomyClass::where('identifier', 'like', '%'.$identifier.'%')
            ->orWhere('identifier', 'like', '%'.ucfirst($identifier).'%')
            ->orWhere('identifier', 'like', '%'.strtoupper($identifier).'%')
            ->first();

        if (! $taxonomy) {
            $taxonomy = $taxonomyClass::where('properties->geohub_id', $identifier)->first();
        }

        return $taxonomy;
    }
}
```

Nota: questo sostituisce interamente `syncTaxonomiesFromProperties()`/`findTaxonomyByActivityType()` (con i relativi log emoji di debug) con `syncTaxonomyFromEmbeddedProperty()`/`findTaxonomyByIdentifier()`, generalizzati per essere condivisi tra `activities` e `themes` — stesso comportamento osservabile per `activities` (stessa logica di matching identifier, stesso `attach` con `duration_forward`/`duration_backward`), log ora prefissati `[child_side_sync]` invece delle emoji, coerente col nuovo requisito di logging distinguibile.

- [ ] **Step 5: Esegui il test per verificare che passi**

Run: `docker exec -it php-maphub vendor/bin/phpunit vendor/wm/wm-package/tests/Feature/ImportEcTrackJobThemeSyncTest.php`
Expected: PASS — tutti e 3 i test verdi

- [ ] **Step 6: Esegui l'intera suite Feature del package per verificare nessuna regressione su `activities`**

Run: `docker exec -it php-maphub vendor/bin/phpunit vendor/wm/wm-package/tests/Feature/`
Expected: PASS — nessuna regressione sui test esistenti che coprono `ImportEcTrackJob`/`activities` (cerca in particolare eventuali test esistenti che referenzino `syncTaxonomiesFromProperties`/`findTaxonomyByActivityType` per nome — se presenti e legati all'implementazione privata rimossa, aggiornali per referenziare `syncTaxonomyFromEmbeddedProperty`/`findTaxonomyByIdentifier` o, se testano solo il comportamento pubblico osservabile (pivot popolato), lasciali invariati)

- [ ] **Step 7: Commit**

```bash
git -C wm-package add config/wm-geohub-import.php src/Jobs/Import/ImportEcTrackJob.php tests/Feature/ImportEcTrackJobThemeSyncTest.php
git -C wm-package commit -m "feat(oc:8094): sync EcTrack taxonomy_theme from embedded geohub payload, generalize activity sync"
```

---

## Task 4: Scoping import taxonomy alle sole taxonomy usate dall'app

**Files:**
- Modify: `wm-package/src/Services/Import/GeohubImportService.php` (nuovo metodo pubblico `getUsedTaxonomyGeohubIdsForApp()`, subito dopo `getTaxonomyRecordsForMorphable()` aggiunto in Task 1; estensione di `getGeohubIdsToImport()` per supportare `whereIn`)
- Modify: `wm-package/src/Jobs/Import/ImportAppJob.php` (branch taxonomy di `queueEntityImport()`)
- Test: `wm-package/tests/Feature/GeohubImportServiceUsedTaxonomyGeohubIdsForAppTest.php`

**Interfaces:**
- Consumes: `$this->dbConnection`, `$this->importMapping` (proprietà protette già esistenti); `GeohubImportService::getEcMediaIdsForApp(int $appUserId): array` (metodo privato già esistente nella stessa classe, riusato senza modificarne la visibilità — la chiamata avviene da un metodo della stessa classe)
- Produces: `public function getUsedTaxonomyGeohubIdsForApp(string $taxonomyModelKey, int $appGeohubId, ?int $appUserId): array` — ritorna l'array di ID GeoHub (interi) della taxonomy del tipo richiesto (`taxonomy_theme`/`taxonomy_poi_types`/`taxonomy_activity`) effettivamente referenziati da almeno uno tra: l'App stessa (`morphable_type = 'App\Models\App'`, `morphable_id = $appGeohubId`), gli EcTrack con `user_id = $appUserId`, gli EcPoi con `user_id = $appUserId`, gli EcMedia associati (via `getEcMediaIdsForApp($appUserId)`), i Layer con `app_id = $appGeohubId`. Ritorna `[]` se nessuno di questi referenzia alcuna taxonomy di quel tipo. `getGeohubIdsToImport(string $modelKey, ?array $wheres, ?array $data = null): array` (metodo pubblico esistente, Task 4 lo estende): quando un valore in `$wheres` è un array, usa `whereIn($column, $value)` invece di `where($column, $value)` — usata da Task 4's modifica a `ImportAppJob`, e da qualsiasi futuro chiamante che passi un filtro multi-valore.

- [ ] **Step 1: Scrivi il test che fallisce**

Crea `wm-package/tests/Feature/GeohubImportServiceUsedTaxonomyGeohubIdsForAppTest.php`:

```php
<?php

namespace Wm\WmPackage\Tests\Feature;

require_once __DIR__.'/../Concerns/SharesGeohubConnectionWithLocal.php';

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Import\GeohubImportService;
use Wm\WmPackage\Tests\Concerns\SharesGeohubConnectionWithLocal;

class GeohubImportServiceUsedTaxonomyGeohubIdsForAppTest extends TestCase
{
    use DatabaseTransactions, SharesGeohubConnectionWithLocal;

    private GeohubImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shareGeohubConnectionWithLocal();

        // FK constraints on the taxonomy pivot tables reference real local rows; these tests
        // insert pivot rows with morphable_id values pointing at Geohub-side ids that don't
        // necessarily match a locally-imported record, same pattern already used in
        // GeohubImportServiceTaxonomyRecordsForMorphableTest (Task 1).
        DB::statement('SET session_replication_role = replica');

        $this->service = app(GeohubImportService::class);
    }

    protected function tearDown(): void
    {
        DB::statement('SET session_replication_role = DEFAULT');

        parent::tearDown();
    }

    public function test_theme_used_by_an_ec_track_of_the_app_is_included(): void
    {
        $appUser = User::factory()->create();
        $app = App::factory()->createQuietly(['user_id' => $appUser->id]);

        $track = EcTrack::factory()->createQuietly(['user_id' => $appUser->id]);

        DB::table('taxonomy_themeables')->insert([
            'taxonomy_theme_id' => 9101,
            'taxonomy_themeable_type' => 'App\\Models\\EcTrack',
            'taxonomy_themeable_id' => $track->id,
        ]);

        $result = $this->service->getUsedTaxonomyGeohubIdsForApp('taxonomy_theme', $app->id, $appUser->id);

        $this->assertEqualsCanonicalizing([9101], $result);
    }

    public function test_theme_used_by_an_ec_poi_of_the_app_is_included(): void
    {
        $appUser = User::factory()->create();
        $app = App::factory()->createQuietly(['user_id' => $appUser->id]);

        $poi = EcPoi::factory()->createQuietly(['user_id' => $appUser->id]);

        DB::table('taxonomy_themeables')->insert([
            'taxonomy_theme_id' => 9102,
            'taxonomy_themeable_type' => 'App\\Models\\EcPoi',
            'taxonomy_themeable_id' => $poi->id,
        ]);

        $result = $this->service->getUsedTaxonomyGeohubIdsForApp('taxonomy_theme', $app->id, $appUser->id);

        $this->assertEqualsCanonicalizing([9102], $result);
    }

    public function test_theme_used_only_by_a_layer_of_the_app_is_included(): void
    {
        // Regression guard for the risk flagged in overview.md: Layer has no child-side sync
        // channel and depends solely on the dedicated taxonomy job — scoping must not starve it.
        $appUser = User::factory()->create();
        $app = App::factory()->createQuietly(['user_id' => $appUser->id]);

        $layer = Layer::factory()->createQuietly(['app_id' => $app->id]);

        DB::table('taxonomy_themeables')->insert([
            'taxonomy_theme_id' => 9103,
            'taxonomy_themeable_type' => 'App\\Models\\Layer',
            'taxonomy_themeable_id' => $layer->id,
        ]);

        $result = $this->service->getUsedTaxonomyGeohubIdsForApp('taxonomy_theme', $app->id, $appUser->id);

        $this->assertEqualsCanonicalizing([9103], $result);
    }

    public function test_theme_used_only_by_the_app_itself_is_included(): void
    {
        $appUser = User::factory()->create();
        $app = App::factory()->createQuietly(['user_id' => $appUser->id]);

        DB::table('taxonomy_themeables')->insert([
            'taxonomy_theme_id' => 9104,
            'taxonomy_themeable_type' => 'App\\Models\\App',
            'taxonomy_themeable_id' => $app->id,
        ]);

        $result = $this->service->getUsedTaxonomyGeohubIdsForApp('taxonomy_theme', $app->id, $appUser->id);

        $this->assertEqualsCanonicalizing([9104], $result);
    }

    public function test_theme_used_by_an_ec_media_associated_via_track_feature_image_is_included(): void
    {
        // Regression guard for the risk flagged in overview.md: EcMedia has no child-side sync
        // channel either — exercises the getEcMediaIdsForApp() feature_image path it reuses.
        $appUser = User::factory()->create();
        $app = App::factory()->createQuietly(['user_id' => $appUser->id]);

        $mediaId = DB::table('ec_media')->insertGetId([
            'name' => json_encode(['it' => 'Test Media']),
            'url' => 'https://example.test/media.jpg',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        EcTrack::factory()->createQuietly([
            'user_id' => $appUser->id,
            'feature_image' => $mediaId,
        ]);

        DB::table('taxonomy_themeables')->insert([
            'taxonomy_theme_id' => 9105,
            'taxonomy_themeable_type' => 'App\\Models\\EcMedia',
            'taxonomy_themeable_id' => $mediaId,
        ]);

        $result = $this->service->getUsedTaxonomyGeohubIdsForApp('taxonomy_theme', $app->id, $appUser->id);

        $this->assertEqualsCanonicalizing([9105], $result);
    }

    public function test_theme_not_used_by_anything_of_this_app_is_excluded(): void
    {
        $appUser = User::factory()->create();
        $app = App::factory()->createQuietly(['user_id' => $appUser->id]);

        // Belongs to a different, unrelated user/app entirely.
        $otherUser = User::factory()->create();
        $otherTrack = EcTrack::factory()->createQuietly(['user_id' => $otherUser->id]);

        DB::table('taxonomy_themeables')->insert([
            'taxonomy_theme_id' => 9106,
            'taxonomy_themeable_type' => 'App\\Models\\EcTrack',
            'taxonomy_themeable_id' => $otherTrack->id,
        ]);

        $result = $this->service->getUsedTaxonomyGeohubIdsForApp('taxonomy_theme', $app->id, $appUser->id);

        $this->assertEqualsCanonicalizing([], $result);
    }

    public function test_result_has_no_duplicates_when_same_theme_used_by_multiple_sources(): void
    {
        $appUser = User::factory()->create();
        $app = App::factory()->createQuietly(['user_id' => $appUser->id]);

        $track = EcTrack::factory()->createQuietly(['user_id' => $appUser->id]);
        $poi = EcPoi::factory()->createQuietly(['user_id' => $appUser->id]);

        DB::table('taxonomy_themeables')->insert([
            ['taxonomy_theme_id' => 9107, 'taxonomy_themeable_type' => 'App\\Models\\EcTrack', 'taxonomy_themeable_id' => $track->id],
            ['taxonomy_theme_id' => 9107, 'taxonomy_themeable_type' => 'App\\Models\\EcPoi', 'taxonomy_themeable_id' => $poi->id],
        ]);

        $result = $this->service->getUsedTaxonomyGeohubIdsForApp('taxonomy_theme', $app->id, $appUser->id);

        $this->assertEqualsCanonicalizing([9107], $result);
    }

    public function test_returns_empty_array_when_app_has_no_content_at_all(): void
    {
        $appUser = User::factory()->create();
        $app = App::factory()->createQuietly(['user_id' => $appUser->id]);

        $result = $this->service->getUsedTaxonomyGeohubIdsForApp('taxonomy_theme', $app->id, $appUser->id);

        $this->assertSame([], $result);
    }
}
```

- [ ] **Step 2: Esegui il test per verificare che fallisca**

Run: `docker exec php-maphub vendor/bin/phpunit vendor/wm/wm-package/tests/Feature/GeohubImportServiceUsedTaxonomyGeohubIdsForAppTest.php`
Expected: FAIL — `Call to undefined method Wm\WmPackage\Services\Import\GeohubImportService::getUsedTaxonomyGeohubIdsForApp()`

- [ ] **Step 3: Implementa il nuovo metodo**

In `wm-package/src/Services/Import/GeohubImportService.php`, subito dopo `getTaxonomyRecordsForMorphable()` (aggiunto in Task 1):

```php
    /**
     * Get the Geohub taxonomy ids (of the given type) actually referenced by this app — by the
     * App itself, or by any EcTrack/EcPoi/EcMedia/Layer belonging to it. Used to scope the
     * dedicated taxonomy import job (see oc:8094) instead of importing every taxonomy row on
     * Geohub regardless of whether this app uses it. Covers all 5 morphable types the taxonomy
     * pivots support — not just EcTrack/EcPoi (the only two with a child-side sync channel) —
     * so EcMedia/Layer/App (which have no such channel and rely solely on the dedicated job)
     * are not starved of taxonomy master records they need.
     *
     * @param  string  $taxonomyModelKey  The taxonomy model key (e.g. 'taxonomy_theme')
     * @param  int  $appGeohubId  The Geohub id of the app being imported
     * @param  int|null  $appUserId  The Geohub user_id owning the app (null skips the
     *                               EcTrack/EcPoi/EcMedia checks, App/Layer are still checked)
     * @return array<int> The Geohub taxonomy ids actually used by this app
     */
    public function getUsedTaxonomyGeohubIdsForApp(string $taxonomyModelKey, int $appGeohubId, ?int $appUserId): array
    {
        $relations = $this->importMapping[$taxonomyModelKey]['relations'];
        $morphableTable = $relations['morphable_table'];
        $foreignKey = $relations['foreign_key'];
        $morphableIdKey = $relations['morphable_id'];
        $morphableTypeKey = $relations['morphable_type'];

        $morphableIdsByType = [
            'App\\Models\\App' => [$appGeohubId],
            'App\\Models\\EcTrack' => $appUserId !== null
                ? $this->dbConnection->table('ec_tracks')->where('user_id', $appUserId)->pluck('id')->toArray()
                : [],
            'App\\Models\\EcPoi' => $appUserId !== null
                ? $this->dbConnection->table('ec_pois')->where('user_id', $appUserId)->pluck('id')->toArray()
                : [],
            'App\\Models\\EcMedia' => $appUserId !== null
                ? $this->getEcMediaIdsForApp($appUserId)
                : [],
            'App\\Models\\Layer' => $this->dbConnection->table('layers')->where('app_id', $appGeohubId)->pluck('id')->toArray(),
        ];

        $morphableIdsByType = array_filter($morphableIdsByType, fn ($ids) => ! empty($ids));

        if (empty($morphableIdsByType)) {
            return [];
        }

        $query = $this->dbConnection->table($morphableTable);
        $query->where(function ($outer) use ($morphableIdsByType, $morphableTypeKey, $morphableIdKey) {
            foreach ($morphableIdsByType as $type => $ids) {
                $outer->orWhere(function ($inner) use ($type, $ids, $morphableTypeKey, $morphableIdKey) {
                    $inner->where($morphableTypeKey, $type)->whereIn($morphableIdKey, $ids);
                });
            }
        });

        return $query->pluck($foreignKey)->unique()->values()->all();
    }
```

- [ ] **Step 4: Esegui il test per verificare che passi**

Run: `docker exec php-maphub vendor/bin/phpunit vendor/wm/wm-package/tests/Feature/GeohubImportServiceUsedTaxonomyGeohubIdsForAppTest.php`
Expected: PASS — tutti gli 8 test verdi

- [ ] **Step 5: Estendi `getGeohubIdsToImport()` per supportare `whereIn`**

In `wm-package/src/Services/Import/GeohubImportService.php`, nel metodo `getGeohubIdsToImport()` esistente, sostituisci il blocco:

```php
        foreach ($wheres as $column => $value) {
            $connection->where($column, $value);
        }
```

con:

```php
        foreach ($wheres as $column => $value) {
            if (is_array($value)) {
                $connection->whereIn($column, $value);
            } else {
                $connection->where($column, $value);
            }
        }
```

Nessun test aggiuntivo dedicato a questo step: è coperto indirettamente dal test di integrazione di Step 8 (`ImportAppJob`), che passa un array come valore in `$wheres` e verifica il risultato finale.

- [ ] **Step 6: Aggiorna `ImportAppJob::queueEntityImport()`**

In `wm-package/src/Jobs/Import/ImportAppJob.php`, nel metodo `queueEntityImport()`, sostituisci:

```php
                case strpos($entityModelKey, 'taxonomy') !== false: // import all taxonomy entities
                    $whereCondition = null;
                    break;
```

con:

```php
                case strpos($entityModelKey, 'taxonomy') !== false: // import only taxonomies actually used by this app (oc:8094)
                    $whereCondition = ['id' => $this->geohubImportService->getUsedTaxonomyGeohubIdsForApp($entityModelKey, $this->entityId, $userId)];
                    break;
```

Nota: `$this->entityId` è l'id Geohub dell'app (proprietà protetta ereditata da `BaseImportJob`, sempre accessibile da `ImportAppJob`); `$userId` è il parametro già passato a `queueEntityImport()` (per le chiamate taxonomy, è `$data['user_id']`, l'user_id Geohub grezzo dell'app — vedi `processDependencies()`, non il valore trasformato/locale).

- [ ] **Step 7: Scrivi il test di integrazione che fallisce**

Crea `wm-package/tests/Feature/ImportAppJobTaxonomyScopingTest.php`:

```php
<?php

namespace Wm\WmPackage\Tests\Feature;

require_once __DIR__.'/../Concerns/SharesGeohubConnectionWithLocal.php';

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wm\WmPackage\Jobs\Import\ImportAppJob;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Tests\Concerns\SharesGeohubConnectionWithLocal;

class ImportAppJobTaxonomyScopingTest extends TestCase
{
    use DatabaseTransactions, SharesGeohubConnectionWithLocal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shareGeohubConnectionWithLocal();

        DB::statement('SET session_replication_role = replica');
    }

    protected function tearDown(): void
    {
        DB::statement('SET session_replication_role = DEFAULT');

        parent::tearDown();
    }

    public function test_only_taxonomy_themes_used_by_the_app_are_queued_for_import(): void
    {
        Bus::fake();

        $appGeohubId = 70001;
        $appUserId = 70002;

        $track = EcTrack::factory()->createQuietly(['user_id' => $appUserId]);

        DB::table('taxonomy_themes')->insert([
            ['id' => 9201, 'name' => json_encode(['it' => 'Used Theme']), 'identifier' => 'used-theme', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 9202, 'name' => json_encode(['it' => 'Unused Theme']), 'identifier' => 'unused-theme', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Only 9201 is referenced by this app's track — 9202 belongs to no content of this app
        // and must NOT be queued.
        DB::table('taxonomy_themeables')->insert([
            'taxonomy_theme_id' => 9201,
            'taxonomy_themeable_type' => 'App\\Models\\EcTrack',
            'taxonomy_themeable_id' => $track->id,
        ]);

        $job = new ImportAppJob($appGeohubId);

        $refJob = new \ReflectionClass($job);
        $prop = $refJob->getParentClass()->getProperty('geohubImportService');
        $prop->setAccessible(true);
        $prop->setValue($job, app(\Wm\WmPackage\Services\Import\GeohubImportService::class));

        $method = new \ReflectionMethod($job, 'queueEntityImport');
        $method->setAccessible(true);
        $method->invoke($job, 'taxonomy_theme', $appUserId, 'user_id', 1);

        Bus::assertBatched(function ($batch) {
            $queuedEntityIds = collect($batch->jobs)->map(function ($queuedJob) {
                $entityIdProperty = new \ReflectionProperty($queuedJob, 'entityId');
                $entityIdProperty->setAccessible(true);

                return $entityIdProperty->getValue($queuedJob);
            })->all();

            return $batch->name === 'app-dependencies-taxonomy_theme-import-batch'
                && $queuedEntityIds === [9201];
        });
    }
}
```

- [ ] **Step 8: Esegui il test, correggi eventuali problemi di scaffolding, verifica che passi**

Run: `docker exec php-maphub vendor/bin/phpunit vendor/wm/wm-package/tests/Feature/ImportAppJobTaxonomyScopingTest.php`
Expected: PASS

- [ ] **Step 9: Esegui i test dei Task 1-3 insieme per verificare nessuna regressione**

Run: `docker exec php-maphub vendor/bin/phpunit vendor/wm/wm-package/tests/Feature/GeohubImportServiceTaxonomyRecordsForMorphableTest.php vendor/wm/wm-package/tests/Feature/ImportEcPoiJobTaxonomySyncTest.php vendor/wm/wm-package/tests/Feature/ImportEcTrackJobThemeSyncTest.php vendor/wm/wm-package/tests/Feature/GeohubImportServiceUsedTaxonomyGeohubIdsForAppTest.php vendor/wm/wm-package/tests/Feature/ImportAppJobTaxonomyScopingTest.php`
Expected: PASS — tutti verdi, nessuna regressione

- [ ] **Step 10: Commit**

```bash
git -C wm-package add src/Services/Import/GeohubImportService.php src/Jobs/Import/ImportAppJob.php tests/Feature/GeohubImportServiceUsedTaxonomyGeohubIdsForAppTest.php tests/Feature/ImportAppJobTaxonomyScopingTest.php
git -C wm-package commit -m "feat(oc:8094): scope taxonomy import to taxonomies actually used by the app being imported"
```

---

## Verifica end-to-end manuale (non automatizzata)

Dopo l'approvazione dei 3 task sopra, richiedere al dev di eseguire in locale (container GeoHub avviati, connessione `geohub` raggiungibile):

1. Re-import dell'app 63: `docker exec -it php-maphub php artisan wm:import-from-geohub app 63` (comando `WmImportFromGeohubCommand`; nessun `--dependencies` esplicito, così usa `default_dependencies.app`, lo stesso path di `ImportAppJob` usato in produzione)
2. Verifica pivot popolati senza il workaround citato nel ticket:
   ```sql
   SELECT count(*) FROM taxonomy_themeables;
   SELECT count(*) FROM taxonomy_poi_typeables;
   ```
3. Conferma che i conteggi siano coerenti con i volumi attesi (non zero, ordine di grandezza vicino ai volumi GeoHub reali per l'app 63), **senza** dover rilanciare l'import con `--dependencies=taxonomy_theme,taxonomy_poi_types`
