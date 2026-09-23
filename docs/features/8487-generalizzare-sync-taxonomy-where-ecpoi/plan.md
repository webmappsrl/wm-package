> Ticket: oc:8487

# Generalizzare Sincronizza Taxonomy Where — EcTrack + EcPoi — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Generalizzare il sync SQL di `properties.taxonomy_where` (oggi solo `EcTrack`) per includere anche `EcPoi`, sostituendo il vecchio job via-API OSMFeatures in tutti i call site EC (osservatore automatico, azione inline, azione bulk, import GeoHub), lasciando UGC invariato.

**Architecture:** Un unico metodo generalizzato in `GeometryComputationService` (bulk + scoped-per-id, parametrico su `GeometryModel`) sostituisce l'implementazione `MultiLineString`-only esistente. Due job lo wrappano: uno bulk (entrambi i modelli, riusato da azione Nova manuale, meccanismo "nuove where→resync" esistente, e nuovo hook di import) e uno scoped-per-record (riusato da osservatore automatico e azione inline EcTrack). Zero modifiche lato Maphub — tutto in `wm-package`.

**Tech Stack:** Laravel 12, PHP 8.4, PostgreSQL + PostGIS, Nova 5, Pest.

**Spec:** `docs/features/8487-generalizzare-sync-taxonomy-where-ecpoi/overview.md`

## Global Constraints

- Le geometrie PostGIS passano sempre da SQL puro, mai attraverso l'ORM — il metodo generalizzato resta `DB::statement()`/`DB::selectOne()`, nessun resave Eloquent.
- Un `catch` che logga senza rilanciare fa apparire un job "completato" su Horizon senza retry visibile — nessun nuovo job introduce questo pattern; le eccezioni si propagano.
- UGC (`UgcPoi`, `UgcTrack`, `UgcController.php`, `WmSyncUgcTaxonomyWhereCommand.php`) resta **esplicitamente non toccato**: continua a usare `UpdateModelWithGeometryTaxonomyWhere` via API OSMFeatures.
- Test Pest del package: `DatabaseTransactions`, DB reale `wm_package` (PostGIS), nessun mock delle query geometriche.
- Nessun commit va eseguito da chi esegue questo piano senza conferma esplicita del developer (regola del workflow Webmapp) — i comandi `git commit` sotto sono testo per il developer, non azioni da eseguire autonomamente.
- **Rename senza alias di compatibilità**: `GeometryComputationService::syncTracksTaxonomyWhere()` viene rinominato (non lasciato come alias deprecato) — è un metodo di servizio interno, referenziato solo da classi di questo stesso package che vengono tutte aggiornate in questo piano. Rischio residuo noto e accettato: un consumer esterno (camminiditalia, osm2cai2, forestas) che lo chiamasse direttamente nel proprio codice applicativo smetterebbe di compilare — nessuna occorrenza nota, non verificabile da questo ambiente.
- **Nota operativa di deploy (non di codice)**: dopo il merge/deploy di questo ticket, riavviare il worker Horizon — i job `UpdateModelWithGeometryTaxonomyWhere` già in coda al momento del deploy sui call site EC modificati possono fallire silenziosamente se il worker non viene riavviato (regola nota del progetto, `.claude/rules/job-e-import.md`).
- **Limite noto, non risolto in questo ciclo**: un contenuto EC che risulta fuori da ogni `taxonomy_where` viene scritto come `{}` (non più `null`); se in futuro nuove where arrivano a coprirlo, il trigger automatico (`updateDataChain()`, basato su `=== null`) non lo riprende da solo — il recupero resta demandato al rilancio della bulk action.

---

### Task 1: Generalizzare `GeometryComputationService::syncTracksTaxonomyWhere()`

**Files:**
- Modify: `src/Services/GeometryComputationService.php:30-80` (metodo `syncTracksTaxonomyWhere`)
- Test: `tests/Feature/Services/GeometryComputationServiceTaxonomyWhereTest.php` (nuovo)

**Interfaces:**
- Produces: `GeometryComputationService::syncTaxonomyWhere(string|GeometryModel $model, ?int $modelId = null): int` — sostituisce `syncTracksTaxonomyWhere(string|MultiLineString $trackModel): int`. Con `$modelId` null: comportamento bulk (tutte le righe con geometria). Con `$modelId` valorizzato: scoping a quella sola riga. Ritorna il conteggio di righe con `taxonomy_where` popolata dopo l'update (bulk: tutte quelle coperte; scoped: 0 o 1).
- Consumes: nessuna dipendenza da task precedenti (primo task).

- [ ] **Step 1: Scrivi il test che fallisce (bulk EcTrack + bulk EcPoi + scoped EcTrack + scoped EcPoi)**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Services\GeometryComputationService;

uses(DatabaseTransactions::class);

/**
 * Crea una TaxonomyWhere con geometria poligonale nota (bbox Corsica), identifier
 * randomizzato per non collidere con dati QA reali del DB di sviluppo condiviso
 * (stesso pattern di SyncTaxonomyWhereJobTest.php).
 */
function createCorsicaTaxonomyWhere(): TaxonomyWhere
{
    $taxonomyWhere = new TaxonomyWhere([
        'name' => 'Corsica',
        'properties' => ['source' => 'geohub', 'admin_level' => 4],
    ]);
    $taxonomyWhere->identifier = 'corsica-'.Str::lower(Str::random(8));
    $taxonomyWhere->save();

    DB::statement(
        'UPDATE taxonomy_wheres SET geometry = ST_GeomFromGeoJSON(?) WHERE id = ?',
        ['{"type":"Polygon","coordinates":[[[8.53,41.33],[9.56,41.33],[9.56,43.03],[8.53,43.03],[8.53,41.33]]]}', $taxonomyWhere->id]
    );

    return $taxonomyWhere->fresh();
}

it('syncs taxonomy_where in bulk for EcTrack', function () {
    createCorsicaTaxonomyWhere();
    $app = App::factory()->create();

    $trackId = DB::table('ec_tracks')->insertGetId([
        'name' => json_encode(['it' => 'Track in Corsica']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"MultiLineString\",\"coordinates\":[[[9.0,42.0,0],[9.1,42.1,0]]]}')"),
        'properties' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $synced = GeometryComputationService::make()->syncTaxonomyWhere(EcTrack::class);

    expect($synced)->toBeGreaterThanOrEqual(1);
    $track = EcTrack::find($trackId);
    expect($track->properties['taxonomy_where'] ?? [])->not->toBeEmpty();
});

it('syncs taxonomy_where in bulk for EcPoi', function () {
    createCorsicaTaxonomyWhere();
    $app = App::factory()->create();

    $poiId = DB::table('ec_pois')->insertGetId([
        'name' => json_encode(['it' => 'Poi in Corsica']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[9.05,42.05]}')"),
        'properties' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $synced = GeometryComputationService::make()->syncTaxonomyWhere(EcPoi::class);

    expect($synced)->toBeGreaterThanOrEqual(1);
    $poi = EcPoi::find($poiId);
    expect($poi->properties['taxonomy_where'] ?? [])->not->toBeEmpty();
});

it('scopes the sync to a single EcPoi id without touching other rows', function () {
    createCorsicaTaxonomyWhere();
    $app = App::factory()->create();

    $inCoverageId = DB::table('ec_pois')->insertGetId([
        'name' => json_encode(['it' => 'Poi in Corsica']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[9.05,42.05]}')"),
        'properties' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $otherInCoverageId = DB::table('ec_pois')->insertGetId([
        'name' => json_encode(['it' => 'Altro Poi in Corsica']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[9.06,42.06]}')"),
        'properties' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $synced = GeometryComputationService::make()->syncTaxonomyWhere(EcPoi::class, $inCoverageId);

    expect($synced)->toBe(1);
    expect(EcPoi::find($inCoverageId)->properties['taxonomy_where'] ?? [])->not->toBeEmpty();
    expect(EcPoi::find($otherInCoverageId)->properties['taxonomy_where'] ?? [])->toBeEmpty();
});

it('writes a unified taxonomy_where shape with name/admin_level/source keys', function () {
    createCorsicaTaxonomyWhere();
    $app = App::factory()->create();

    $poiId = DB::table('ec_pois')->insertGetId([
        'name' => json_encode(['it' => 'Poi in Corsica']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[9.05,42.05]}')"),
        'properties' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    GeometryComputationService::make()->syncTaxonomyWhere(EcPoi::class, $poiId);

    $entry = collect(EcPoi::find($poiId)->properties['taxonomy_where'])->first();
    expect($entry)->toHaveKeys(['name', 'admin_level', 'source']);
    expect($entry['name'])->toBeArray();
});
```

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Run: `docker exec -it php-maphub bash -c "cd wm-package && vendor/bin/pest tests/Feature/Services/GeometryComputationServiceTaxonomyWhereTest.php"`
Expected: FAIL — `Call to undefined method Wm\WmPackage\Services\GeometryComputationService::syncTaxonomyWhere()`

- [ ] **Step 3: Generalizza il metodo**

In `src/Services/GeometryComputationService.php`, sostituisci il metodo `syncTracksTaxonomyWhere` (righe 25-80) con:

```php
    /**
     * Synchronize taxonomy_where properties for a geometry model table (EcTrack o EcPoi).
     *
     * @param  class-string<GeometryModel>|GeometryModel  $model
     * @param  int|null  $modelId  Se valorizzato, scopa l'update alla sola riga con questo id.
     */
    public function syncTaxonomyWhere(string|GeometryModel $model, ?int $modelId = null): int
    {
        $modelInstance = is_string($model) ? new $model : $model;
        if (! $modelInstance instanceof GeometryModel) {
            throw new \InvalidArgumentException('The model must extend GeometryModel.');
        }

        $tableName = $modelInstance->getTable();
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
            throw new \InvalidArgumentException('Invalid table name.');
        }

        $idCondition = $modelId !== null ? 'AND id = ?' : '';
        $bindings = $modelId !== null ? [$modelId] : [];

        DB::statement("
            UPDATE {$tableName}
            SET properties = jsonb_set(
                COALESCE(properties, '{}'),
                '{taxonomy_where}',
                COALESCE(
                    (
                        SELECT jsonb_object_agg(
                            COALESCE(tw.properties->>'osmfeatures_id', (tw.properties->>'osm2cai_id'), tw.id::text),
                            jsonb_build_object(
                                'name',
                                CASE
                                    WHEN tw.name IS NULL OR btrim(tw.name) = '' THEN '{}'::jsonb
                                    WHEN left(ltrim(tw.name), 1) = '{' THEN tw.name::jsonb
                                    ELSE jsonb_build_object('it', tw.name, 'en', tw.name)
                                END,
                                'admin_level',
                                (tw.properties->>'admin_level')::int,
                                'source',
                                tw.properties->>'source'
                            )
                        )
                        FROM taxonomy_wheres tw
                        WHERE tw.geometry IS NOT NULL
                          AND ST_Intersects({$tableName}.geometry::geometry, tw.geometry::geometry)
                    ),
                    '{}'::jsonb
                )
            )
            WHERE geometry IS NOT NULL
            {$idCondition}
        ", $bindings);

        return (int) (DB::selectOne("
            SELECT COUNT(*) as c
            FROM {$tableName}
            WHERE geometry IS NOT NULL
              AND properties->'taxonomy_where' != '{}'::jsonb
              {$idCondition}
        ", $bindings)->c ?? 0);
    }
```

Aggiungi l'import in cima al file (dopo gli altri `use Wm\WmPackage\Models\...`):

```php
use Wm\WmPackage\Models\Abstracts\GeometryModel;
```

Rimuovi l'import ora inutilizzato `use Wm\WmPackage\Models\Abstracts\MultiLineString;` se non referenziato altrove nel file (verifica con `grep -n MultiLineString src/Services/GeometryComputationService.php` — se compare solo nella firma del vecchio metodo, rimuovilo).

- [ ] **Step 4: Esegui i test e verifica che passino**

Run: `docker exec -it php-maphub bash -c "cd wm-package && vendor/bin/pest tests/Feature/Services/GeometryComputationServiceTaxonomyWhereTest.php"`
Expected: PASS (4 test)

- [ ] **Step 5: Commit**

```bash
git add src/Services/GeometryComputationService.php tests/Feature/Services/GeometryComputationServiceTaxonomyWhereTest.php
git commit -m "feat(oc:8487): generalize taxonomy_where sync to EcTrack and EcPoi with scoped variant"
```

---

### Task 2: Nuovo job scoped-per-record + wiring in `EcPoiService`

**Files:**
- Create: `src/Jobs/TaxonomyWhere/SyncModelTaxonomyWhereJob.php`
- Modify: `src/Services/Models/EcPoiService.php:1-37`
- Test: `tests/Feature/Jobs/SyncModelTaxonomyWhereJobTest.php` (nuovo)

**Interfaces:**
- Consumes: `GeometryComputationService::syncTaxonomyWhere()` (Task 1)
- Produces: `SyncModelTaxonomyWhereJob` — job in coda, costruttore `__construct(GeometryModel $model)`, usato dai task successivi (3) per il path automatico EcTrack e l'azione inline.

- [ ] **Step 1: Scrivi il test che fallisce**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncModelTaxonomyWhereJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\TaxonomyWhere;

uses(DatabaseTransactions::class);

it('populates taxonomy_where on the single EcPoi passed to the job', function () {
    $taxonomyWhere = new TaxonomyWhere([
        'name' => 'Corsica',
        'properties' => ['source' => 'geohub', 'admin_level' => 4],
    ]);
    $taxonomyWhere->identifier = 'corsica-'.Str::lower(Str::random(8));
    $taxonomyWhere->save();

    DB::statement(
        'UPDATE taxonomy_wheres SET geometry = ST_GeomFromGeoJSON(?) WHERE id = ?',
        ['{"type":"Polygon","coordinates":[[[8.53,41.33],[9.56,41.33],[9.56,43.03],[8.53,43.03],[8.53,41.33]]]}', $taxonomyWhere->id]
    );

    $app = App::factory()->create();
    $poi = EcPoi::create([
        'name' => ['it' => 'Poi in Corsica'],
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'properties' => [],
    ]);
    DB::statement(
        'UPDATE ec_pois SET geometry = ST_GeomFromGeoJSON(?) WHERE id = ?',
        ['{"type":"Point","coordinates":[9.05,42.05]}', $poi->id]
    );

    (new SyncModelTaxonomyWhereJob($poi))->handle(app(\Wm\WmPackage\Services\GeometryComputationService::class));

    expect($poi->fresh()->properties['taxonomy_where'] ?? [])->not->toBeEmpty();
});
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `docker exec -it php-maphub bash -c "cd wm-package && vendor/bin/pest tests/Feature/Jobs/SyncModelTaxonomyWhereJobTest.php"`
Expected: FAIL — `Class "Wm\WmPackage\Jobs\TaxonomyWhere\SyncModelTaxonomyWhereJob" not found`

- [ ] **Step 3: Crea il job**

```php
<?php

namespace Wm\WmPackage\Jobs\TaxonomyWhere;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Services\GeometryComputationService;

class SyncModelTaxonomyWhereJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(protected GeometryModel $model) {}

    public function handle(GeometryComputationService $service): void
    {
        $service->syncTaxonomyWhere(get_class($this->model), $this->model->id);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('SyncModelTaxonomyWhereJob failed after all retries', [
            'model' => get_class($this->model),
            'model_id' => $this->model->id,
            'error' => $e->getMessage(),
        ]);
    }
}
```

- [ ] **Step 4: Esegui il test e verifica che passi**

Run: `docker exec -it php-maphub bash -c "cd wm-package && vendor/bin/pest tests/Feature/Jobs/SyncModelTaxonomyWhereJobTest.php"`
Expected: PASS

- [ ] **Step 5: Wiring in `EcPoiService::updateDataChain()`**

In `src/Services/Models/EcPoiService.php`, sostituisci l'import:

```php
use Wm\WmPackage\Jobs\UpdateModelWithGeometryTaxonomyWhere;
```

con:

```php
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncModelTaxonomyWhereJob;
```

E nel metodo `updateDataChain()` (riga 26), sostituisci:

```php
                new UpdateModelWithGeometryTaxonomyWhere($model), // it relates where taxonomy terms to the media model based on geometry attribute
```

con:

```php
                new SyncModelTaxonomyWhereJob($model), // it relates where taxonomy terms to the media model based on geometry attribute
```

- [ ] **Step 6: Verifica manuale del wiring (nessun test automatico nuovo — la logica di trigger di `updateDataChain()` non cambia, solo il job usato)**

Run: `docker exec -it php-maphub bash -c "cd wm-package && grep -n SyncModelTaxonomyWhereJob src/Services/Models/EcPoiService.php"`
Expected: la entry nel chain array la mostra

- [ ] **Step 7: Commit**

```bash
git add src/Jobs/TaxonomyWhere/SyncModelTaxonomyWhereJob.php src/Services/Models/EcPoiService.php tests/Feature/Jobs/SyncModelTaxonomyWhereJobTest.php
git commit -m "feat(oc:8487): add scoped taxonomy_where sync job and wire it into EcPoi save chain"
```

---

### Task 3: Wiring in `EcTrackService` + migrazione azione inline `EcTrack.php`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-file-di-test-extra-non-pianificato)

**Files:**
- Modify: `src/Services/Models/EcTrackService.php:1-27,309,338` (import + `createDataChain()` + `updateDataChain()`)
- Modify: `src/Nova/EcTrack.php:1-27,94-110` (import + azione inline)
- Test: `tests/Feature/Nova/EcTrackRegenerateTaxonomyWhereActionTest.php` (nuovo)

**Interfaces:**
- Consumes: `SyncModelTaxonomyWhereJob` (Task 2)

- [ ] **Step 1: Scrivi il test che fallisce (azione inline dispatcha il nuovo job)**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Laravel\Nova\Fields\ActionFields;
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncModelTaxonomyWhereJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Nova\EcTrack as EcTrackResource;

uses(DatabaseTransactions::class);

it('dispatches SyncModelTaxonomyWhereJob from the Regenerate Taxonomy Where inline action', function () {
    Bus::fake();

    $app = App::factory()->create();
    $track = EcTrack::factory()->create(['app_id' => $app->id, 'user_id' => $app->user_id]);

    $resource = new EcTrackResource($track);
    $actions = $resource->actions(app(\Laravel\Nova\Http\Requests\NovaRequest::class));

    $regenerateAction = collect($actions)->first(
        fn ($action) => $action->name() === __('Regenerate Taxonomy Where')
    );

    expect($regenerateAction)->not->toBeNull();

    $regenerateAction->handle(new ActionFields(collect(), collect()), collect([$track]));

    Bus::assertChained([
        SyncModelTaxonomyWhereJob::class,
        \Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob::class,
    ]);
});
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `docker exec -it php-maphub bash -c "cd wm-package && vendor/bin/pest tests/Feature/Nova/EcTrackRegenerateTaxonomyWhereActionTest.php"`
Expected: FAIL — la chain contiene ancora `UpdateModelWithGeometryTaxonomyWhere`, non `SyncModelTaxonomyWhereJob`

- [ ] **Step 3: Aggiorna `EcTrackService.php`**

Sostituisci l'import (riga 23):

```php
use Wm\WmPackage\Jobs\UpdateModelWithGeometryTaxonomyWhere;
```

con:

```php
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncModelTaxonomyWhereJob;
```

In `createDataChain()` (riga ~309), sostituisci:

```php
        $chain[] = new UpdateModelWithGeometryTaxonomyWhere($track);
```

con:

```php
        $chain[] = new SyncModelTaxonomyWhereJob($track);
```

In `updateDataChain()` (riga ~338), stessa sostituzione:

```php
            $chain[] = new UpdateModelWithGeometryTaxonomyWhere($track);
```

con:

```php
            $chain[] = new SyncModelTaxonomyWhereJob($track);
```

- [ ] **Step 4: Aggiorna l'azione inline in `EcTrack.php`**

Sostituisci l'import (riga 15):

```php
use Wm\WmPackage\Jobs\UpdateModelWithGeometryTaxonomyWhere;
```

con:

```php
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncModelTaxonomyWhereJob;
```

Nel metodo `actions()` (righe 101-104), sostituisci:

```php
            new ExecuteEcTrackDataChainAction([
                fn ($ecTrack) => new UpdateModelWithGeometryTaxonomyWhere($ecTrack),
                fn ($ecTrack) => new UpdateEcTrackAwsJob($ecTrack),
            ], __('Regenerate Taxonomy Where')),
```

con:

```php
            new ExecuteEcTrackDataChainAction([
                fn ($ecTrack) => new SyncModelTaxonomyWhereJob($ecTrack),
                fn ($ecTrack) => new UpdateEcTrackAwsJob($ecTrack),
            ], __('Regenerate Taxonomy Where')),
```

- [ ] **Step 5: Esegui il test e verifica che passi**

Run: `docker exec -it php-maphub bash -c "cd wm-package && vendor/bin/pest tests/Feature/Nova/EcTrackRegenerateTaxonomyWhereActionTest.php"`
Expected: PASS

- [ ] **Step 6: Verifica che nessun altro riferimento a `UpdateModelWithGeometryTaxonomyWhere` sia rimasto sui call site EC**

Run: `docker exec -it php-maphub bash -c "cd wm-package && grep -rn UpdateModelWithGeometryTaxonomyWhere src/Services/Models/EcPoiService.php src/Services/Models/EcTrackService.php src/Nova/EcTrack.php"`
Expected: nessun output (nessuna occorrenza)

- [ ] **Step 7: Commit**

```bash
git add src/Services/Models/EcTrackService.php src/Nova/EcTrack.php tests/Feature/Nova/EcTrackRegenerateTaxonomyWhereActionTest.php
git commit -m "feat(oc:8487): switch EcTrack save chain and inline action to scoped taxonomy_where sync"
```

---

### Task 4: Generalizzare il meccanismo "nuove where → resync" a EcPoi

**Files:**
- Modify: `src/Jobs/TaxonomyWhere/SyncTaxonomyWhereTracksJob.php` → rinominato `src/Jobs/TaxonomyWhere/SyncTaxonomyWhereJob.php`
- Modify: `src/Nova/Actions/Concerns/HasTaxonomyWhereImportHelpers.php:1-16,222-259`
- Modify: `src/Nova/Actions/ImportTaxonomyWhere.php` (2 call site di `finalizeWithTracksSync`)
- Modify: `tests/Feature/Jobs/SyncTaxonomyWhereTracksJobTest.php` → rinominato `tests/Feature/Jobs/SyncTaxonomyWhereJobTest.php`, esteso a EcPoi

**Interfaces:**
- Consumes: `GeometryComputationService::syncTaxonomyWhere()` (Task 1)
- Produces: `SyncTaxonomyWhereJob` (bulk, EcTrack+EcPoi) — usato da Task 5 (nuova azione Nova) e Task 8 (hook import).

- [ ] **Step 1: Estendi il test esistente per coprire anche EcPoi**

Rinomina il file:

Run: `docker exec -it php-maphub bash -c "cd wm-package && git mv tests/Feature/Jobs/SyncTaxonomyWhereTracksJobTest.php tests/Feature/Jobs/SyncTaxonomyWhereJobTest.php"`

Sostituisci il contenuto con:

```php
<?php

namespace Wm\WmPackage\Tests\Feature\Jobs;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncTaxonomyWhereJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyWhere;

class SyncTaxonomyWhereJobTest extends TestCase
{
    use DatabaseTransactions;

    private function createCorsicaTaxonomyWhere(): TaxonomyWhere
    {
        $identifier = 'corsica-'.Str::lower(Str::random(8));

        $taxonomyWhere = new TaxonomyWhere([
            'name' => 'Corsica',
            'properties' => ['source' => 'geohub'],
        ]);
        $taxonomyWhere->identifier = $identifier;
        $taxonomyWhere->save();

        DB::statement(
            'UPDATE taxonomy_wheres SET geometry = ST_GeomFromGeoJSON(?) WHERE id = ?',
            ['{"type":"Polygon","coordinates":[[[8.53,41.33],[9.56,41.33],[9.56,43.03],[8.53,43.03],[8.53,41.33]]]}', $taxonomyWhere->id]
        );

        return $taxonomyWhere;
    }

    public function test_populates_taxonomy_where_on_intersecting_tracks(): void
    {
        $this->createCorsicaTaxonomyWhere();

        $app = App::factory()->create();

        $trackId = DB::table('ec_tracks')->insertGetId([
            'name' => json_encode(['it' => 'Track in Corsica']),
            'app_id' => $app->id,
            'user_id' => $app->user_id,
            'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"MultiLineString\",\"coordinates\":[[[9.0,42.0,0],[9.1,42.1,0]]]}')"),
            'properties' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new SyncTaxonomyWhereJob)->handle();

        $track = EcTrack::find($trackId);
        $this->assertNotEmpty($track->properties['taxonomy_where'] ?? []);
    }

    public function test_populates_taxonomy_where_on_intersecting_pois(): void
    {
        $this->createCorsicaTaxonomyWhere();

        $app = App::factory()->create();

        $poiId = DB::table('ec_pois')->insertGetId([
            'name' => json_encode(['it' => 'Poi in Corsica']),
            'app_id' => $app->id,
            'user_id' => $app->user_id,
            'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[9.05,42.05]}')"),
            'properties' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new SyncTaxonomyWhereJob)->handle();

        $poi = EcPoi::find($poiId);
        $this->assertNotEmpty($poi->properties['taxonomy_where'] ?? []);
    }
}
```

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Run: `docker exec -it php-maphub bash -c "cd wm-package && vendor/bin/pest tests/Feature/Jobs/SyncTaxonomyWhereJobTest.php"`
Expected: FAIL — `Class "Wm\WmPackage\Jobs\TaxonomyWhere\SyncTaxonomyWhereJob" not found`

- [ ] **Step 3: Rinomina e generalizza il job**

Run: `docker exec -it php-maphub bash -c "cd wm-package && git mv src/Jobs/TaxonomyWhere/SyncTaxonomyWhereTracksJob.php src/Jobs/TaxonomyWhere/SyncTaxonomyWhereJob.php"`

Sostituisci il contenuto con:

```php
<?php

namespace Wm\WmPackage\Jobs\TaxonomyWhere;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\GeometryComputationService;

class SyncTaxonomyWhereJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function handle(): void
    {
        $service = GeometryComputationService::make();

        $tracksSynced = $service->syncTaxonomyWhere(
            config('wm-package.ec_track_model', EcTrack::class)
        );
        $poisSynced = $service->syncTaxonomyWhere(EcPoi::class);

        Log::info('SyncTaxonomyWhereJob completed', [
            'tracks_synced' => $tracksSynced,
            'pois_synced' => $poisSynced,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('SyncTaxonomyWhereJob failed after all retries', [
            'error' => $e->getMessage(),
        ]);
    }
}
```

- [ ] **Step 4: Aggiorna `HasTaxonomyWhereImportHelpers.php`**

Sostituisci l'import (riga 11):

```php
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncTaxonomyWhereTracksJob;
```

con:

```php
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncTaxonomyWhereJob;
```

Nel metodo `executeGeohubImport()` (riga ~227), sostituisci:

```php
        Bus::batch($geometryJobs)
            ->then(function () {
                SyncTaxonomyWhereTracksJob::dispatch();
            })
            ->dispatch();
```

con:

```php
        Bus::batch($geometryJobs)
            ->then(function () {
                SyncTaxonomyWhereJob::dispatch();
            })
            ->dispatch();
```

Rinomina `finalizeWithTracksSync()` in `finalizeWithEcSync()` (righe 252-259) e aggiorna il corpo per usare il nuovo metodo generalizzato:

```php
    /**
     * Dispatcha il sync locale (via ST_Intersects) di track e poi esistenti sulle
     * taxonomy_where appena importate/aggiornate, e appende il contatore al
     * messaggio finale — stesso comportamento per tutte e tre le sorgenti.
     */
    protected function finalizeWithEcSync(string $message): string
    {
        $service = GeometryComputationService::make();

        $tracksSynced = $service->syncTaxonomyWhere(
            config('wm-package.ec_track_model', EcTrack::class)
        );
        $poisSynced = $service->syncTaxonomyWhere(EcPoi::class);

        return $message." Sync taxonomy_where su {$tracksSynced} tracks e {$poisSynced} poi avviata.";
    }
```

Aggiungi l'import mancante in cima al file:

```php
use Wm\WmPackage\Models\EcPoi;
```

- [ ] **Step 5: Aggiorna i 2 call site in `ImportTaxonomyWhere.php`**

Run: `docker exec -it php-maphub bash -c "cd wm-package && grep -n finalizeWithTracksSync src/Nova/Actions/ImportTaxonomyWhere.php"`

Sostituisci entrambe le occorrenze di:

```php
        $msg = $this->finalizeWithTracksSync($msg);
```

con:

```php
        $msg = $this->finalizeWithEcSync($msg);
```

- [ ] **Step 6: Esegui i test e verifica che passino**

Run: `docker exec -it php-maphub bash -c "cd wm-package && vendor/bin/pest tests/Feature/Jobs/SyncTaxonomyWhereJobTest.php"`
Expected: PASS (2 test)

- [ ] **Step 7: Verifica che nessun riferimento al vecchio nome sia rimasto**

Run: `docker exec -it php-maphub bash -c "cd wm-package && grep -rn 'SyncTaxonomyWhereTracksJob\|finalizeWithTracksSync' src/ tests/"`
Expected: nessun output

- [ ] **Step 8: Commit**

```bash
git add -A src/Jobs/TaxonomyWhere/ src/Nova/Actions/Concerns/HasTaxonomyWhereImportHelpers.php src/Nova/Actions/ImportTaxonomyWhere.php tests/Feature/Jobs/
git commit -m "feat(oc:8487): generalize the 'new where triggers resync' mechanism to EcPoi"
```

---

### Task 5: Nuova azione Nova unica "Sincronizza Taxonomy Where su EC Features"

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-5-test-comportamentale-mancante-nel-primo-giro)

**Files:**
- Create: `src/Nova/Actions/SyncEcTaxonomyWhereAction.php`
- Delete: `src/Nova/Actions/SyncTracksTaxonomyWhereAction.php`
- Modify: `src/Nova/TaxonomyWhere.php:1-76`
- Test: `tests/Feature/Nova/Actions/SyncEcTaxonomyWhereActionTest.php` (nuovo)

**Interfaces:**
- Consumes: `SyncTaxonomyWhereJob` (Task 4)

- [ ] **Step 1: Scrivi il test che fallisce**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Laravel\Nova\Fields\ActionFields;
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncTaxonomyWhereJob;
use Wm\WmPackage\Nova\Actions\SyncEcTaxonomyWhereAction;

uses(DatabaseTransactions::class);

it('dispatches SyncTaxonomyWhereJob in queue and returns an immediate message', function () {
    Bus::fake();

    $action = new SyncEcTaxonomyWhereAction;
    $result = $action->handle(new ActionFields(collect(), collect()), collect());

    Bus::assertDispatched(SyncTaxonomyWhereJob::class);
    expect($result->message)->toContain('avviata');
});
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `docker exec -it php-maphub bash -c "cd wm-package && vendor/bin/pest tests/Feature/Nova/Actions/SyncEcTaxonomyWhereActionTest.php"`
Expected: FAIL — `Class "Wm\WmPackage\Nova\Actions\SyncEcTaxonomyWhereAction" not found`

- [ ] **Step 3: Crea la nuova azione**

```php
<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncTaxonomyWhereJob;

class SyncEcTaxonomyWhereAction extends Action
{
    use InteractsWithQueue, Queueable;

    public $name = 'Sincronizza Taxonomy Where su EC Features';

    public $onlyOnIndex = false;

    public $standalone = true;

    public function handle(ActionFields $fields, Collection $models): mixed
    {
        SyncTaxonomyWhereJob::dispatch();

        return Action::message('Sincronizzazione taxonomy_where su EcTrack ed EcPoi avviata.');
    }

    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
```

- [ ] **Step 4: Rimuovi la vecchia azione ed aggiorna la registrazione**

Run: `docker exec -it php-maphub bash -c "cd wm-package && git rm src/Nova/Actions/SyncTracksTaxonomyWhereAction.php"`

In `src/Nova/TaxonomyWhere.php`, sostituisci l'import:

```php
use Wm\WmPackage\Nova\Actions\SyncTracksTaxonomyWhereAction;
```

con:

```php
use Wm\WmPackage\Nova\Actions\SyncEcTaxonomyWhereAction;
```

E nel metodo `actions()` (righe 67-75), sostituisci:

```php
            new SyncTracksTaxonomyWhereAction,
```

con:

```php
            new SyncEcTaxonomyWhereAction,
```

- [ ] **Step 5: Esegui il test e verifica che passi**

Run: `docker exec -it php-maphub bash -c "cd wm-package && vendor/bin/pest tests/Feature/Nova/Actions/SyncEcTaxonomyWhereActionTest.php"`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add -A src/Nova/Actions/SyncEcTaxonomyWhereAction.php src/Nova/Actions/SyncTracksTaxonomyWhereAction.php src/Nova/TaxonomyWhere.php tests/Feature/Nova/Actions/SyncEcTaxonomyWhereActionTest.php
git commit -m "feat(oc:8487): replace SyncTracksTaxonomyWhereAction with a single async EC action"
```

---

### Task 6: Rimozione dead code

**Files:**
- Delete: `src/Nova/Actions/RegenerateEcPoiTaxonomyWhere.php`
- Delete: `src/Nova/Actions/RegenerateTaxonomyWhere.php`

**Interfaces:**
- Consumes: nessuna (nessun call site le referenzia, verificato in Fase: reverse-interaction e challenge).

- [ ] **Step 1: Verifica finale che non siano registrate/referenziate da nessuna parte**

Run: `docker exec -it php-maphub bash -c "cd wm-package && grep -rn 'RegenerateEcPoiTaxonomyWhere\|new RegenerateTaxonomyWhere\b' src/ tests/ app/ 2>/dev/null"`
Expected: nessun output (a parte le righe interne alle 2 classi stesse, che stiamo per eliminare)

- [ ] **Step 2: Rimuovi i file**

```bash
git rm src/Nova/Actions/RegenerateEcPoiTaxonomyWhere.php src/Nova/Actions/RegenerateTaxonomyWhere.php
```

- [ ] **Step 3: Verifica che l'autoload/i test esistenti non si rompano**

Run: `docker exec -it php-maphub bash -c "cd wm-package && composer dump-autoload && vendor/bin/pest tests/Feature/Nova/"`
Expected: PASS (nessun test referenziava le 2 classi rimosse)

- [ ] **Step 4: Commit**

```bash
git add -A src/Nova/Actions/RegenerateEcPoiTaxonomyWhere.php src/Nova/Actions/RegenerateTaxonomyWhere.php
git commit -m "refactor(oc:8487): remove unreachable RegenerateEcPoiTaxonomyWhere and RegenerateTaxonomyWhere actions"
```

---

### Task 7: Migration difensiva — indice GIST su `taxonomy_wheres.geometry`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-7-migration-gist-rimossa-dopo-verifica-sui-numeri-reali-post-merge-prima-del-deploy)

**Files:**
- Create: `database/migrations/zz_2026_09_15_000001_add_gist_index_to_taxonomy_wheres_table.php.stub`

**Interfaces:**
- Nessuna interfaccia di codice — pubblicata automaticamente dai test del package via `vendor:publish --tag=wm-package-migrations` (vedi `tests/TestCase.php:defineDatabaseMigrations()`).

- [ ] **Step 1: Crea lo stub di migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('taxonomy_wheres')) {
            return;
        }

        // Difensiva, non richiesta dai numeri attuali (verificato: 4 righe in
        // taxonomy_wheres sul DB di sviluppo Maphub) — protezione a costo
        // trascurabile se la copertura geografica dovesse crescere molto in
        // futuro (oc:8487).
        DB::statement('
            CREATE INDEX IF NOT EXISTS taxonomy_wheres_geometry_gist
            ON taxonomy_wheres USING GIST (geometry)
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS taxonomy_wheres_geometry_gist');
    }
};
```

- [ ] **Step 2: Verifica che la migration venga applicata dalla suite di test del package**

Run: `docker exec -it php-maphub bash -c "cd wm-package && vendor/bin/pest tests/Feature/Services/GeometryComputationServiceTaxonomyWhereTest.php"`
Expected: PASS (la suite pubblica e applica automaticamente tutti gli stub, incluso questo nuovo, prima di ogni test — nessuna regressione)

- [ ] **Step 3: Verifica idempotenza (indice già esistente non causa errori)**

Run: `docker exec -it php-maphub bash -c "cd wm-package && php artisan migrate:fresh --database=pgsql_testing 2>&1 | tail -5 || true"`

(Se il comando non è eseguibile in questo ambiente, verifica a occhio che lo stub usi `CREATE INDEX IF NOT EXISTS` — già presente nel contenuto sopra, quindi rieseguibile senza errori.)

- [ ] **Step 4: Commit**

```bash
git add database/migrations/zz_2026_09_15_000001_add_gist_index_to_taxonomy_wheres_table.php.stub
git commit -m "feat(oc:8487): add defensive GIST index migration on taxonomy_wheres.geometry"
```

---

### Task 8: Aggancio al flusso di import GeoHub (`ImportAppJob`)

**Files:**
- Modify: `src/Jobs/Import/ImportAppJob.php:1-16,444-499` (import + `attachBatchCompletionCallback()`)
- Modify: `tests/Feature/Import/ImportAppJobConfigRefreshBatchesTest.php` (il test `ec_track` esistente ora ha 2 finally callback, non 1)
- Test: aggiunta di nuovi `it()` nello stesso file per `ec_poi` e per l'assert sul dispatch di `SyncTaxonomyWhereJob`

**Interfaces:**
- Consumes: `SyncTaxonomyWhereJob` (Task 4)

- [ ] **Step 1: Aggiorna il test esistente `ec_track` (ora 2 finally callback) e aggiungi copertura `ec_poi`**

In `tests/Feature/Import/ImportAppJobConfigRefreshBatchesTest.php`, aggiungi l'import in cima al file:

```php
use Wm\WmPackage\Jobs\Import\ImportEcPoiJob;
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncTaxonomyWhereJob;
```

Sostituisci il test `'queues a fresh UpdateAppConfigJob when the ec_track batch finishes'` (righe 145-175) con:

```php
it('queues a fresh UpdateAppConfigJob and a SyncTaxonomyWhereJob when the ec_track batch finishes', function () {
    Bus::fake();

    $app = App::factory()->createQuietly();

    $service = Mockery::mock(GeohubImportService::class);
    $service->shouldReceive('getGeohubIdsToImport')
        ->once()
        ->with('ec_track', Mockery::any(), Mockery::any())
        ->andReturn([555]);
    $service->shouldReceive('createJob')
        ->once()
        ->with('ec_track', 555, Mockery::any())
        ->andReturn(new ImportEcTrackJob(555, ['app_id' => $app->id]));

    $job = new ImportAppJob($app->id, []);

    withMockedGeohubService($job, $service);

    invokeProtected($job, 'queueEntityImport', 'ec_track', $app->user_id, 'user_id', $app->id);

    $batches = Bus::batched(fn ($batch) => true);
    expect($batches)->toHaveCount(1);

    $finallyCallbacks = $batches->first()->finallyCallbacks();
    expect($finallyCallbacks)->toHaveCount(2);

    foreach ($finallyCallbacks as $callback) {
        $callback(Mockery::mock(Batch::class));
    }

    Bus::assertDispatched(UpdateAppConfigJob::class, fn (UpdateAppConfigJob $dispatched) => $dispatched->appId === $app->id);
    Bus::assertDispatched(SyncTaxonomyWhereJob::class);
});

it('queues a SyncTaxonomyWhereJob when the ec_poi batch finishes', function () {
    Bus::fake();

    $app = App::factory()->createQuietly();

    $service = Mockery::mock(GeohubImportService::class);
    $service->shouldReceive('getGeohubIdsToImport')
        ->once()
        ->with('ec_poi', Mockery::any(), Mockery::any())
        ->andReturn([444]);
    $service->shouldReceive('createJob')
        ->once()
        ->with('ec_poi', 444, Mockery::any())
        ->andReturn(new ImportEcPoiJob(444, ['app_id' => $app->id]));

    $job = new ImportAppJob($app->id, []);

    withMockedGeohubService($job, $service);

    invokeProtected($job, 'queueEntityImport', 'ec_poi', $app->user_id, 'user_id', $app->id);

    $batches = Bus::batched(fn ($batch) => true);
    expect($batches)->toHaveCount(1);

    $finallyCallbacks = $batches->first()->finallyCallbacks();
    expect($finallyCallbacks)->toHaveCount(2);

    foreach ($finallyCallbacks as $callback) {
        $callback(Mockery::mock(Batch::class));
    }

    Bus::assertDispatched(UpdateAppConfigJob::class, fn (UpdateAppConfigJob $dispatched) => $dispatched->appId === $app->id);
    Bus::assertDispatched(SyncTaxonomyWhereJob::class);
});
```

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Run: `docker exec -it php-maphub bash -c "cd wm-package && vendor/bin/pest tests/Feature/Import/ImportAppJobConfigRefreshBatchesTest.php"`
Expected: FAIL sui 2 test sopra — `expect($finallyCallbacks)->toHaveCount(2)` riceve `1`, `SyncTaxonomyWhereJob` non dispatchato

- [ ] **Step 3: Aggiungi l'hook in `attachBatchCompletionCallback()`**

In `src/Jobs/Import/ImportAppJob.php`, aggiungi l'import in cima al file:

```php
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncTaxonomyWhereJob;
```

Nel metodo `attachBatchCompletionCallback()` (righe 444-499), dopo il blocco `if (in_array($entityModelKey, self::CONFIG_DEPENDENT_BATCHES, true)) { ... }` (che si chiude alla fine del metodo), aggiungi un secondo blocco **prima della chiusura del metodo**:

```php
        if (in_array($entityModelKey, self::CONFIG_DEPENDENT_BATCHES, true)) {
            // ... blocco esistente invariato ...
        }

        // Causa radice #1 di oc:8487: GeohubImportService::persistQuietly() disabilita gli
        // observer durante l'import, quindi EcPoiService::updateDataChain()/
        // EcTrackService::createDataChain() non scattano mai e il contenuto importato resta
        // senza taxonomy_where finché non viene ricalcolato manualmente. Aggancia il ricalcolo
        // bulk (SyncTaxonomyWhereJob copre sia EcTrack sia EcPoi) al completamento del batch
        // di import EC dedicato, non al singolo save.
        if (in_array($entityModelKey, ['ec_poi', 'ec_track'], true)) {
            $batch->allowFailures()->finally(
                static function (Batch $batch): void {
                    SyncTaxonomyWhereJob::dispatch();
                }
            );
        }
```

**Attenzione**: il closure passato a `->finally()` viene serializzato da `BatchRepository::store()` — deve restare `static` (nessuna cattura implicita di `$this`), esattamente come già documentato nel commento sopra il blocco `CONFIG_DEPENDENT_BATCHES` in questo stesso metodo (righe 451-458). Il closure sopra referenzia solo `SyncTaxonomyWhereJob::dispatch()` per nome di classe qualificato, nessuna variabile catturata dallo scope esterno — coerente con quella regola.

- [ ] **Step 4: Esegui i test e verifica che passino**

Run: `docker exec -it php-maphub bash -c "cd wm-package && vendor/bin/pest tests/Feature/Import/ImportAppJobConfigRefreshBatchesTest.php"`
Expected: PASS (tutti i test del file, inclusi i 2 nuovi/modificati)

- [ ] **Step 5: Esegui l'intera suite di test del package per verificare nessuna regressione trasversale**

Run: `docker exec -it php-maphub bash -c "cd wm-package && vendor/bin/pest tests/Feature/Import/ tests/Feature/Jobs/ tests/Feature/Nova/ tests/Feature/Services/"`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Jobs/Import/ImportAppJob.php tests/Feature/Import/ImportAppJobConfigRefreshBatchesTest.php
git commit -m "feat(oc:8487): trigger taxonomy_where sync when ec_poi/ec_track import batches finish"
```

---

### Task 9: `GeometryComputationService::syncTaxonomyWhere()` diventa upgrade-only

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-9-punto-di-test-in-collisione-con-dati-reali-del-db-condiviso) — e più sostanzialmente, dopo review formale e chiarimento del developer, il comportamento upgrade-only qui descritto come "sempre" è stato ridisegnato: cerca "Revisione del comportamento upgrade-only" in `notes.md`

**Files:**
- Modify: `src/Services/GeometryComputationService.php:69-77`
- Test: `tests/Feature/Services/GeometryComputationServiceTaxonomyWhereTest.php` (esistente, append)

**Interfaces:**
- Consumes: nessuna nuova — modifica comportamentale di `syncTaxonomyWhere()` (Task 1).
- Produces: garanzia "mai downgrade" su cui Task 10 fa affidamento (il job scoped può assumere che `syncTaxonomyWhere()` da solo non cancelli mai un valore preesistente).

- [ ] **Step 1: Scrivi il test che fallisce**

Aggiungi in fondo a `tests/Feature/Services/GeometryComputationServiceTaxonomyWhereTest.php`:

```php
it('does not reset an existing taxonomy_where when no local coverage matches (upgrade-only)', function () {
    $app = App::factory()->create();

    $poiId = DB::table('ec_pois')->insertGetId([
        'name' => json_encode(['it' => 'Poi fuori copertura']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[9.05,42.05,0]}')"),
        'properties' => json_encode([
            'taxonomy_where' => [
                'R999999' => ['name' => ['it' => 'Regione Precedente'], 'admin_level' => 4, 'source' => 'osmfeatures'],
            ],
        ]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Nessuna TaxonomyWhere locale creata in questo test: la subquery ST_Intersects non trova nulla.
    GeometryComputationService::make()->syncTaxonomyWhere(EcPoi::class, $poiId);

    $properties = EcPoi::find($poiId)->properties;
    expect($properties['taxonomy_where'])->toHaveKey('R999999');
    expect($properties['taxonomy_where']['R999999']['name']['it'])->toBe('Regione Precedente');
});
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `docker exec -it php-maphub bash -c "cd wm-package && vendor/bin/pest tests/Feature/Services/GeometryComputationServiceTaxonomyWhereTest.php"`
Expected: FAIL — `R999999` non è più presente, `taxonomy_where` è stato azzerato a `{}` dalla UPDATE incondizionata

- [ ] **Step 3: Rendi la UPDATE upgrade-only**

In `src/Services/GeometryComputationService.php`, dentro `syncTaxonomyWhere()`, sostituisci:

```php
                        FROM taxonomy_wheres tw
                        WHERE tw.geometry IS NOT NULL
                          AND ST_Intersects({$tableName}.geometry::geometry, tw.geometry::geometry)
                    ),
                    '{}'::jsonb
                )
            )
            WHERE geometry IS NOT NULL
            {$idCondition}
        ", $bindings);
```

con:

```php
                        FROM taxonomy_wheres tw
                        WHERE tw.geometry IS NOT NULL
                          AND ST_Intersects({$tableName}.geometry::geometry, tw.geometry::geometry)
                    ),
                    COALESCE(properties->'taxonomy_where', '{}'::jsonb)
                )
            )
            WHERE geometry IS NOT NULL
            {$idCondition}
        ", $bindings);
```

Il `COALESCE` più esterno ora ripiega sul valore già presente in colonna (`properties->'taxonomy_where'`) invece che su `{}` fisso: se la subquery non trova intersezioni, il valore esistente resta intatto; per un record che non ne ha mai avuto uno, `properties->'taxonomy_where'` è `NULL` e il `COALESCE` produce comunque `{}` (comportamento identico a oggi in quel caso).

- [ ] **Step 4: Esegui il test e verifica che passi**

Run: `docker exec -it php-maphub bash -c "cd wm-package && vendor/bin/pest tests/Feature/Services/GeometryComputationServiceTaxonomyWhereTest.php"`
Expected: PASS (tutti i test del file, inclusi i 5 esistenti — nessuna regressione: gli scenari già coperti hanno sempre almeno una `TaxonomyWhere` locale che interseca, quindi il ramo "trovato" non cambia)

- [ ] **Step 5: Verifica di non-regressione sul path bulk**

Run: `docker exec -it php-maphub bash -c "cd wm-package && vendor/bin/pest tests/Feature/Jobs/SyncTaxonomyWhereTracksJobTest.php"`
Expected: PASS — stesso metodo condiviso, nessuna assertion di quel file dipende dal ramo "nessun match" azzerato a `{}`

- [ ] **Step 6: Commit**

```bash
git add src/Services/GeometryComputationService.php tests/Feature/Services/GeometryComputationServiceTaxonomyWhereTest.php
git commit -m "fix(oc:8487): non azzerare taxonomy_where quando il sync locale non trova corrispondenze"
```

---

### Task 10: Fallback via OSMFeatures in `SyncModelTaxonomyWhereJob`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-10-stile-del-file-di-test-diverso-da-quello-assunto-dal-piano) — e più sostanzialmente: 2 bug bloccanti trovati in review formale e corretti, e il design del fallback ridisegnato dopo un chiarimento del developer. Cerca "Review formale" e "Revisione del comportamento upgrade-only" in `notes.md`

**Files:**
- Modify: `src/Jobs/TaxonomyWhere/SyncModelTaxonomyWhereJob.php`
- Modify: `tests/Feature/Jobs/SyncModelTaxonomyWhereJobTest.php:1-30` (la chiamata diretta a `->handle()` del test esistente del Task 2 va aggiornata: `handle()` guadagna un secondo parametro obbligatorio)
- Test: `tests/Feature/Jobs/SyncModelTaxonomyWhereJobTest.php` (append)

**Interfaces:**
- Consumes: `GeometryComputationService::syncTaxonomyWhere()` (Task 1, upgrade-only da Task 9), `OsmfeaturesClient::getWheresByGeojson(array $geojson): array` (esistente, `src/Http/Clients/OsmfeaturesClient.php:11`)
- Produces: `SyncModelTaxonomyWhereJob::handle(GeometryComputationService $service, OsmfeaturesClient $osmfeaturesClient): void` — firma aggiornata, stesso costruttore `__construct(GeometryModel $model)` di prima.

- [ ] **Step 1: Aggiorna la chiamata diretta esistente nel test del Task 2**

In `tests/Feature/Jobs/SyncModelTaxonomyWhereJobTest.php`, nel test `it('populates taxonomy_where on the single EcPoi passed to the job', ...)`, sostituisci:

```php
    (new SyncModelTaxonomyWhereJob($poi))->handle(app(\Wm\WmPackage\Services\GeometryComputationService::class));
```

con:

```php
    (new SyncModelTaxonomyWhereJob($poi))->handle(
        app(\Wm\WmPackage\Services\GeometryComputationService::class),
        app(\Wm\WmPackage\Http\Clients\OsmfeaturesClient::class)
    );
```

Questo test ha già copertura locale (la `TaxonomyWhere` Corsica creata nel test), quindi il fallback non scatterà — nessun'altra modifica necessaria a quel test.

- [ ] **Step 2: Scrivi i test che falliscono**

Aggiungi in fondo a `tests/Feature/Jobs/SyncModelTaxonomyWhereJobTest.php`:

```php
use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Http\Clients\OsmfeaturesClient;

it('falls back to OSMFeatures when the local sync finds no taxonomy_where', function () {
    Http::fake([
        '*/api/v1/features/admin-areas/geojson' => Http::response([
            'features' => [
                [
                    'properties' => [
                        'osmfeatures_id' => 'R617447',
                        'osm_tags' => [
                            'name' => 'Toscana',
                            'name:it' => 'Toscana',
                            'name:en' => 'Tuscany',
                            'admin_level' => '4',
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $app = App::factory()->create();
    $poi = EcPoi::create([
        'name' => ['it' => 'Poi senza copertura locale'],
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'properties' => [],
    ]);
    DB::statement(
        'UPDATE ec_pois SET geometry = ST_GeomFromGeoJSON(?) WHERE id = ?',
        ['{"type":"Point","coordinates":[11.25,43.77]}', $poi->id]
    );

    (new SyncModelTaxonomyWhereJob($poi))->handle(
        app(\Wm\WmPackage\Services\GeometryComputationService::class),
        app(OsmfeaturesClient::class)
    );

    $taxonomyWhere = $poi->fresh()->properties['taxonomy_where'] ?? [];
    expect($taxonomyWhere)->toHaveKey('R617447');
    expect($taxonomyWhere['R617447'])->toHaveKeys(['name', 'admin_level', 'source']);
    expect($taxonomyWhere['R617447']['source'])->toBe('osmfeatures');
    expect($taxonomyWhere['R617447']['admin_level'])->toBe(4);
});

it('does not call OSMFeatures when the local sync already found a taxonomy_where', function () {
    Http::fake();

    $taxonomyWhere = new TaxonomyWhere([
        'name' => 'Corsica',
        'properties' => ['source' => 'geohub', 'admin_level' => 4],
    ]);
    $taxonomyWhere->identifier = 'corsica-'.Str::lower(Str::random(8));
    $taxonomyWhere->save();
    DB::statement(
        'UPDATE taxonomy_wheres SET geometry = ST_GeomFromGeoJSON(?) WHERE id = ?',
        ['{"type":"Polygon","coordinates":[[[8.53,41.33],[9.56,41.33],[9.56,43.03],[8.53,43.03],[8.53,41.33]]]}', $taxonomyWhere->id]
    );

    $app = App::factory()->create();
    $poi = EcPoi::create([
        'name' => ['it' => 'Poi in Corsica'],
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'properties' => [],
    ]);
    DB::statement(
        'UPDATE ec_pois SET geometry = ST_GeomFromGeoJSON(?) WHERE id = ?',
        ['{"type":"Point","coordinates":[9.05,42.05]}', $poi->id]
    );

    (new SyncModelTaxonomyWhereJob($poi))->handle(
        app(\Wm\WmPackage\Services\GeometryComputationService::class),
        app(OsmfeaturesClient::class)
    );

    expect($poi->fresh()->properties['taxonomy_where'] ?? [])->not->toBeEmpty();
    Http::assertNothingSent();
});
```

- [ ] **Step 3: Esegui i test e verifica che falliscano**

Run: `docker exec -it php-maphub bash -c "cd wm-package && vendor/bin/pest tests/Feature/Jobs/SyncModelTaxonomyWhereJobTest.php"`
Expected: FAIL sul primo nuovo test (`R617447` non presente — il job oggi non chiama `OsmfeaturesClient`) e `ArgumentCountError` sul test esistente del Task 2 finché lo Step 1 non è applicato

- [ ] **Step 4: Implementa il fallback nel job**

Sostituisci il contenuto di `src/Jobs/TaxonomyWhere/SyncModelTaxonomyWhereJob.php`:

```php
<?php

namespace Wm\WmPackage\Jobs\TaxonomyWhere;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Http\Clients\OsmfeaturesClient;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Services\GeometryComputationService;

class SyncModelTaxonomyWhereJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(protected GeometryModel $model) {}

    public function handle(GeometryComputationService $service, OsmfeaturesClient $osmfeaturesClient): void
    {
        $service->syncTaxonomyWhere(get_class($this->model), $this->model->id);

        $current = $this->model->fresh()->properties['taxonomy_where'] ?? [];
        if (! empty($current)) {
            return;
        }

        $wheres = $osmfeaturesClient->getWheresByGeojson($this->model->getGeojson());
        if (empty($wheres)) {
            return;
        }

        $mapped = [];
        foreach ($wheres as $whereId => $where) {
            $mapped[$whereId] = [
                'name' => collect($where)->except('_admin_level')->toArray(),
                'admin_level' => $where['_admin_level'] ?? null,
                'source' => 'osmfeatures',
            ];
        }

        $tableName = $this->model->getTable();
        DB::statement("
            UPDATE {$tableName}
            SET properties = jsonb_set(COALESCE(properties, '{}'), '{taxonomy_where}', ?::jsonb)
            WHERE id = ?
        ", [json_encode($mapped), $this->model->id]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('SyncModelTaxonomyWhereJob failed after all retries', [
            'model' => get_class($this->model),
            'model_id' => $this->model->id,
            'error' => $e->getMessage(),
        ]);
    }
}
```

Nessun `try/catch` attorno a `getWheresByGeojson()`: un'eccezione (es. HTTP non `successful()`) si propaga e fa fallire il job, innescando i retry già configurati (`tries=3`, `backoff=60`) e il `failed()` esistente — per scelta esplicita del developer, non un'omissione.

La scrittura usa `DB::statement()` scoped sull'id, non `$model->properties = ...; $model->saveQuietly()`: risalvare un `GeometryModel` via Eloquent transita anche la colonna `geometry` attraverso l'ORM e la corrompe (regola del package, vedi Global Constraints).

- [ ] **Step 5: Esegui i test e verifica che passino**

Run: `docker exec -it php-maphub bash -c "cd wm-package && vendor/bin/pest tests/Feature/Jobs/SyncModelTaxonomyWhereJobTest.php"`
Expected: PASS (tutti e tre i test del file)

- [ ] **Step 6: Verifica di non-regressione sui call site che chiamano `handle()` tramite il job dispatchato (non direttamente)**

Run: `docker exec -it php-maphub bash -c "cd wm-package && vendor/bin/pest tests/Feature/Services tests/Feature/Jobs tests/Feature/Nova"`
Expected: PASS — i call site che dispatchano il job in coda (`EcPoiService`, `EcTrackService`, azione inline `EcTrack.php`, Task 2/3) non chiamano `handle()` direttamente: la risoluzione dei due parametri via container resta automatica, nessuna modifica richiesta lì

- [ ] **Step 7: Commit**

```bash
git add src/Jobs/TaxonomyWhere/SyncModelTaxonomyWhereJob.php tests/Feature/Jobs/SyncModelTaxonomyWhereJobTest.php
git commit -m "feat(oc:8487): fall back to OSMFeatures when the scoped local sync finds no taxonomy_where"
```

---

## Self-Review — ripresa 2026-09-23 (Task 9-10)

**1. Copertura spec:** entrambi i requisiti `[PIANIFICATO, non ancora implementato]` di `overview.md` hanno un task — upgrade-only (Task 9), fallback OSMFeatures scoped (Task 10). Il requisito "path bulk esplicitamente escluso" non ha un task perché è un vincolo negativo (nessuna modifica a `SyncTaxonomyWhereJob`), verificato per assenza: nessuno step di Task 9/10 tocca quel file.

**2. Placeholder scan:** nessun TBD — ogni step ha codice completo o comando eseguibile con output atteso.

**3. Coerenza tipi/nomi:** `SyncModelTaxonomyWhereJob::handle()` guadagna un parametro (`OsmfeaturesClient $osmfeaturesClient`) — la firma è coerente ovunque venga richiamata in questo piano: il test del Task 2 (aggiornato in Task 10 Step 1) e i due nuovi test di Task 10 la passano tutti e tre. Nessun altro task/file richiama `handle()` direttamente (i call site di produzione dispatchano il job in coda, risoluzione automatica via container).

**4. Review Focus (aggiuntivo per Task 9-10):**
- Record con `taxonomy_where` già popolato e sync rilanciato senza copertura locale → non deve azzerarsi (Task 9, testato).
- Record nuovo senza copertura locale, OSMFeatures ha dati → deve popolarsi via fallback (Task 10, testato).
- Record con copertura locale già trovata → nessuna chiamata HTTP sprecata (Task 10, testato con `Http::assertNothingSent()`).
- Fallimento della chiamata OSMFeatures (HTTP non `successful()`) → si propaga, non silenziato (per scelta esplicita, non testato con un `it()` dedicato in questo giro — comportamento delegato al meccanismo di retry già testato implicitamente dal framework Job di Laravel, non riscritto qui).
- Risposta OSMFeatures vuota (nessuna where trovata nemmeno da OSM) → il job ritorna senza scrivere nulla, `taxonomy_where` resta assente (comportamento implicito nello Step 4 di Task 10, non isolato in un test dedicato — rischio residuo noto, non bloccante: stesso comportamento del vecchio job `UpdateModelWithGeometryTaxonomyWhere` in questo caso).

**Nota di sequenza:** Task 9 va eseguito prima di Task 10 — il secondo assume che `syncTaxonomyWhere()` sia già upgrade-only (altrimenti un record popolato dal fallback in un giro precedente verrebbe cancellato dalla stessa chiamata a `syncTaxonomyWhere()` fatta all'inizio di `handle()` in un rilancio successivo).

---

## Self-Review (eseguito in fase di scrittura del piano)

**1. Copertura spec:** ogni requisito di `overview.md` ha un task corrispondente — generalizzazione servizio (Task 1), scoped+wiring EcPoi (Task 2), scoped+wiring EcTrack+azione inline (Task 3), meccanismo "nuove where→resync" generalizzato (Task 4), azione bulk unica async (Task 5), rimozione dead code (Task 6), indice GIST (Task 7), aggancio import (Task 8). L'unico "requisito" senza task dedicato è la non-regressione di `getOrderedTaxonomyWheres()`/`getValidName()` sul formato unificato: coperta dall'assertion `toHaveKeys(['name', 'admin_level', 'source'])` in Task 1 più il fatto che nessun task modifica quei due metodi (già tolleranti, verificato nel codice in fase di reverse-interaction).

**2. Placeholder scan:** nessun "TBD"/"implementa dopo"/codice omesso — ogni step ha codice completo o un comando eseguibile con output atteso esplicito.

**3. Coerenza tipi/nomi:** `syncTaxonomyWhere(string|GeometryModel $model, ?int $modelId = null): int` (Task 1) è il nome/firma usato identico in Task 2 (`SyncModelTaxonomyWhereJob::handle()`) e Task 4 (`SyncTaxonomyWhereJob::handle()`) — nessuna divergenza. `SyncModelTaxonomyWhereJob` (Task 2) è lo stesso nome usato in Task 3 (wiring EcTrackService + EcTrack.php). `SyncTaxonomyWhereJob` (Task 4, rinominato da `SyncTaxonomyWhereTracksJob`) è lo stesso nome usato in Task 5 (azione Nova) e Task 8 (hook import).

**Nota di sequenza:** i task sono ordinati per dipendenza stretta (1→2→3→4→5, poi 6/7 indipendenti, poi 8 che dipende da 4) — eseguibili in quest'ordine anche con `subagent-driven-development` a task singoli, dato che ogni task produce un deliverable testabile in isolamento.
