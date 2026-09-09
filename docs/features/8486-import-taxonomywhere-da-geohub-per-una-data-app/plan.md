> Ticket: oc:8486

# Import TaxonomyWhere da GeoHub per una data app — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Aggiungere una terza sorgente "GeoHub" all'azione Nova `ImportTaxonomyWhere` (wm-package), che importa nella tabella locale `taxonomy_wheres` le where GeoHub senza `admin_level` collegate ai contenuti di un'App, riusando l'identifier slug già presente su GeoHub e collegando le track esistenti tramite il meccanismo locale `syncTracksTaxonomyWhere()` già esistente.

**Architecture:** Estrazione di un trait condiviso (`HasTaxonomyWhereImportHelpers`) per la logica comune ai tre handler della action (risoluzione App, attribuzione user, sync finale tracce), poi aggiunta di `handleGeohub()` che esegue una query cross-database sulla connessione `geohub` (già configurata), un lookup locale a due passi (per `geohub_id`, poi per `identifier`) e dispatcha un nuovo Job asincrono per la copia della geometria (pattern identico a `FetchTaxonomyWhereGeometryJob`).

**Tech Stack:** Laravel 12, PHP 8.4, PostgreSQL + PostGIS, Nova 5, Pest.

**Spec:** `wm-package/docs/features/8486-import-taxonomywhere-da-geohub-per-una-data-app/overview.md`

## Global Constraints

- Nessun file del repo principale Maphub viene toccato — tutto in `wm-package/`
- Traduzioni SOLO it/en (`resources/lang/it.json`, `resources/lang/en.json`) — fr/es/de esplicitamente escluse
- Nessuna modifica a `canSee()`/`canRun()` dell'Action — il gate super-admin vive solo dentro `handleGeohub()`
- Geometria PostGIS scritta sempre in SQL puro (`DB::statement`/`ST_GeomFromGeoJSON`), mai via ORM/Eloquent
- Comportamento esterno di `handleOsmfeatures()`/`handleOsm2cai()` deve restare identico dopo il refactoring (nessun test esisteva prima — i test di regressione scritti in questo piano sono la prima copertura in assoluto per questa Action)
- Commit convention: `feat(oc:8486): ...` / `refactor(oc:8486): ...` — nessun commit o branch automatico, sono istruzioni testuali per l'utente

---

## Task 0: Prerequisito ambiente — pubblicare lo stub migration `identifier` su `taxonomy_wheres`

Non è parte della feature oc:8486, ma è un blocco reale verificato in questo ambiente: la colonna `identifier` (introdotta dal fix oc:8469 in wm-package) non è ancora stata pubblicata/migrata su questa installazione Maphub locale. Senza questa colonna, `TaxonomyWhere::generateIdentifier()`/`withCollisionCounter()`/`TaxonomyObserver` non funzionano e nessun test di questo piano può passare.

**Files:**
- Create: `database/migrations/<timestamp>_zz_2026_09_03_000001_add_identifier_to_taxonomy_wheres_table.php` (nel repo principale Maphub, pubblicato dallo stub — percorso esatto generato dal comando)

**Interfaces:**
- Consumes: nessuna
- Produces: colonna `taxonomy_wheres.identifier` (text, nullable, indice unique) disponibile per tutti i task successivi

- [ ] **Step 1: Verificare il gap**

Run (dalla root del repo principale Maphub, non da wm-package):
```bash
docker exec -it php-maphub php artisan wm-package:publish-missing-migrations --dry-run
```
Expected: exit 1, tra gli stub non allineati compare `zz_2026_09_03_000001_add_identifier_to_taxonomy_wheres_table`.

- [ ] **Step 2: Pubblicare solo questo stub (non gli altri due segnalati, non pertinenti a questo ticket)**

```bash
docker exec -it php-maphub php artisan wm-package:publish-migration zz_2026_09_03_000001_add_identifier_to_taxonomy_wheres_table
```

- [ ] **Step 3: Eseguire la migration**

```bash
docker exec -it php-maphub php artisan migrate
```
Expected: la migration `..._add_identifier_to_taxonomy_wheres_table` viene eseguita senza errori.

- [ ] **Step 4: Verificare che il gap sia chiuso per questo stub specifico**

```bash
docker exec -it php-maphub php artisan wm-package:publish-missing-migrations --dry-run
```
Expected: `zz_2026_09_03_000001_add_identifier_to_taxonomy_wheres_table` non compare più nell'elenco (gli altri due stub non pertinenti a oc:8486 possono restare segnalati — non toccarli in questo ciclo).

- [ ] **Step 5: Commit (nel repo principale Maphub, non in wm-package)**

```bash
git add database/migrations/
git commit -m "fix(oc:8486): publish missing identifier migration for taxonomy_wheres (prerequisite from oc:8469)"
```

---

## Task 1: Estrarre il trait condiviso `HasTaxonomyWhereImportHelpers` e refactorare i due handler esistenti

**Files:**
- Create: `wm-package/src/Nova/Actions/Concerns/HasTaxonomyWhereImportHelpers.php`
- Modify: `wm-package/src/Nova/Actions/ImportTaxonomyWhere.php`
- Test: `wm-package/tests/Feature/Nova/Actions/ImportTaxonomyWhereTest.php` (nuovo — prima copertura in assoluto per questa Action)

**Interfaces:**
- Produces: `HasTaxonomyWhereImportHelpers::resolveApp(ActionFields $fields): App|string` (ritorna l'App risolta, oppure una stringa con il messaggio di errore da passare ad `Action::danger()`), `assignTaxonomyUserFromApp(TaxonomyWhere $taxonomyWhere, App $app): void`, `finalizeWithTracksSync(string $message): string` (dispatcha `syncTracksTaxonomyWhere()` e appende il suffisso contatore alla stringa passata)

- [ ] **Step 1: Scrivere il test di regressione per `handleOsmfeatures` (deve fallire per assenza del file di test, non per comportamento — la Action esiste già)**

```php
<?php

namespace Wm\WmPackage\Tests\Feature\Nova\Actions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\TestCase;
use Wm\WmPackage\Http\Clients\OsmfeaturesClient;
use Wm\WmPackage\Jobs\TaxonomyWhere\FetchTaxonomyWhereGeometryJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Nova\Actions\ImportTaxonomyWhere;

class ImportTaxonomyWhereTest extends TestCase
{
    use DatabaseTransactions;

    public function test_osmfeatures_source_creates_taxonomy_where_for_single_app(): void
    {
        Bus::fake();

        $app = App::factory()->create(['properties' => ['map_bbox' => json_encode([10, 40, 11, 41])]]);

        $client = \Mockery::mock(OsmfeaturesClient::class);
        $client->shouldReceive('getAdminAreasIds')
            ->once()
            ->andReturn([
                ['id' => 'R123', 'name' => 'Toscana', 'updated_at' => now()->toIso8601String()],
            ]);
        $this->app->instance(OsmfeaturesClient::class, $client);

        $action = new ImportTaxonomyWhere;
        $fields = ActionFields::make(collect(['source_type' => 'osmfeatures_4']));

        $response = $action->handle($fields, collect());

        $this->assertStringContainsString('Creati/aggiornati 1 record', $response['message']);
        $this->assertDatabaseHas('taxonomy_wheres', ['name' => 'Toscana']);
        Bus::assertDispatched(FetchTaxonomyWhereGeometryJob::class);
    }

    public function test_osmfeatures_source_returns_danger_when_bbox_is_empty(): void
    {
        $app = App::factory()->create(['properties' => []]);

        $action = new ImportTaxonomyWhere;
        $fields = ActionFields::make(collect(['source_type' => 'osmfeatures_4']));

        $response = $action->handle($fields, collect());

        $this->assertStringContainsString('senza bbox utilizzabile', $response['danger']);
    }

    public function test_osm2cai_source_returns_danger_when_no_sectors_found(): void
    {
        $app = App::factory()->create(['properties' => ['map_bbox' => json_encode([10, 40, 11, 41])]]);

        $client = \Mockery::mock(\Wm\WmPackage\Http\Clients\Osm2caiClient::class);
        $client->shouldReceive('getSectorsList')->once()->andReturn([]);
        $this->app->instance(\Wm\WmPackage\Http\Clients\Osm2caiClient::class, $client);

        $action = new ImportTaxonomyWhere;
        $fields = ActionFields::make(collect(['source_type' => 'osm2cai']));

        $response = $action->handle($fields, collect());

        $this->assertStringContainsString('0 settori', $response['danger']);
    }

    public function test_invalid_source_type_returns_danger(): void
    {
        $action = new ImportTaxonomyWhere;
        $fields = ActionFields::make(collect(['source_type' => 'not-a-real-source']));

        $response = $action->handle($fields, collect());

        $this->assertStringContainsString('Sorgente non valida', $response['danger']);
    }
}
```

- [ ] **Step 2: Eseguire i test — devono passare già ORA, contro il codice attuale non ancora refactorato**

Run: `docker exec -it php-maphub vendor/bin/pest wm-package/tests/Feature/Nova/Actions/ImportTaxonomyWhereTest.php`
Expected: PASS (4/4) — questo è il baseline di comportamento da preservare durante il refactoring del Task successivo.

- [ ] **Step 3: Creare il trait condiviso**

```php
<?php

namespace Wm\WmPackage\Nova\Actions\Concerns;

use Illuminate\Support\Facades\Schema;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Services\GeometryComputationService;

trait HasTaxonomyWhereImportHelpers
{
    /**
     * Risolve l'App da usare per l'import: se esiste una sola App non serve
     * selezione esplicita, altrimenti richiede `app_id` dal campo Select.
     *
     * @return App|string L'App risolta, oppure il messaggio di errore da
     *                     passare ad Action::danger() se la risoluzione fallisce.
     */
    protected function resolveApp(\Laravel\Nova\Fields\ActionFields $fields): App|string
    {
        $apps = App::all();
        if ($apps->count() === 1) {
            return $apps->first();
        }

        $appId = $fields->get('app_id');
        if (! $appId) {
            return "Seleziona un'App.";
        }

        $app = App::find($appId);
        if (! $app) {
            return 'App non trovata.';
        }

        return $app;
    }

    private function assignTaxonomyUserFromApp(TaxonomyWhere $taxonomyWhere, App $app): void
    {
        if (! Schema::hasColumn($taxonomyWhere->getTable(), 'user_id')) {
            return;
        }

        if (empty($app->user_id)) {
            return;
        }

        $taxonomyWhere->forceFill(['user_id' => $app->user_id])->saveQuietly();
    }

    /**
     * Dispatcha il sync locale (via ST_Intersects) delle track esistenti sulle
     * taxonomy_where appena importate/aggiornate, e appende il contatore al
     * messaggio finale — stesso comportamento per tutte e tre le sorgenti.
     */
    protected function finalizeWithTracksSync(string $message): string
    {
        $tracksSynced = GeometryComputationService::make()->syncTracksTaxonomyWhere(
            config('wm-package.ec_track_model', EcTrack::class)
        );

        return $message." Sync taxonomy_where su {$tracksSynced} tracks avviata.";
    }
}
```

- [ ] **Step 4: Refactorare `ImportTaxonomyWhere` per usare il trait, preservando il comportamento esterno identico**

In `wm-package/src/Nova/Actions/ImportTaxonomyWhere.php`:

1. Aggiungere `use Wm\WmPackage\Nova\Actions\Concerns\HasTaxonomyWhereImportHelpers;` e `use HasTaxonomyWhereImportHelpers;` dentro la classe.
2. In `handleOsmfeatures()`, sostituire il blocco di risoluzione App (righe 56-68 dell'originale) con:
   ```php
   $app = $this->resolveApp($fields);
   if (is_string($app)) {
       return Action::danger($app);
   }
   ```
3. In `handleOsm2cai()`, sostituire il blocco equivalente (righe 168-180 dell'originale) con lo stesso pattern.
4. Rimuovere il metodo privato `assignTaxonomyUserFromApp()` dalla classe (ora vive nel trait).
5. In `handleOsmfeatures()`, sostituire:
   ```php
   $tracksSynced = GeometryComputationService::make()->syncTracksTaxonomyWhere(
       config('wm-package.ec_track_model', EcTrack::class)
   );
   $msg .= " Sync taxonomy_where su {$tracksSynced} tracks avviata.";
   ```
   con:
   ```php
   $msg = $this->finalizeWithTracksSync($msg);
   ```
   Stessa sostituzione in `handleOsm2cai()`.
6. Rimuovere l'import `use Wm\WmPackage\Services\GeometryComputationService;` dalla classe se non più usato direttamente (resta usato solo dentro il trait).

- [ ] **Step 5: Rieseguire i test di regressione — devono continuare a passare identici**

Run: `docker exec -it php-maphub vendor/bin/pest wm-package/tests/Feature/Nova/Actions/ImportTaxonomyWhereTest.php`
Expected: PASS (4/4), stesso esito del Step 2 — nessuna differenza di comportamento osservabile.

- [ ] **Step 6: Commit**

```bash
git -C wm-package add src/Nova/Actions/Concerns/HasTaxonomyWhereImportHelpers.php src/Nova/Actions/ImportTaxonomyWhere.php tests/Feature/Nova/Actions/ImportTaxonomyWhereTest.php
git -C wm-package commit -m "refactor(oc:8486): extract shared app resolution and track sync into HasTaxonomyWhereImportHelpers trait"
```

---

## Task 2: `TaxonomyWhere` — rendere pubblico `withCollisionCounter()` ed estendere `getSourceId()` per `geohub_id`

**Files:**
- Modify: `wm-package/src/Models/TaxonomyWhere.php`
- Test: `wm-package/tests/Unit/Models/TaxonomyWhereGeohubSourceTest.php` (nuovo)

**Interfaces:**
- Produces: `TaxonomyWhere::withCollisionCounter(string $base): string` (ora `public`, firma invariata), `TaxonomyWhere::getSourceId(): ?string` (ora riconosce anche `properties['geohub_id']`)

- [ ] **Step 1: Scrivere il test**

```php
<?php

namespace Wm\WmPackage\Tests\Unit\Models;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Wm\WmPackage\Models\TaxonomyWhere;

class TaxonomyWhereGeohubSourceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_get_source_id_reads_geohub_id(): void
    {
        $taxonomyWhere = new TaxonomyWhere(['properties' => ['geohub_id' => 42]]);

        $this->assertSame('42', $taxonomyWhere->getSourceId());
    }

    public function test_with_collision_counter_is_publicly_callable(): void
    {
        TaxonomyWhere::create(['name' => 'Corsica esistente', 'identifier' => 'corsica']);

        $taxonomyWhere = new TaxonomyWhere;
        $identifier = $taxonomyWhere->withCollisionCounter('corsica');

        $this->assertSame('corsica-2', $identifier);
    }

    public function test_with_collision_counter_returns_base_when_free(): void
    {
        $taxonomyWhere = new TaxonomyWhere;
        $identifier = $taxonomyWhere->withCollisionCounter('francia');

        $this->assertSame('francia', $identifier);
    }
}
```

`TaxonomyWhere` non ha una factory dedicata in `wm-package/database/factories/` — il test sopra usa `TaxonomyWhere::create()` con inserimento diretto, non `TaxonomyWhere::factory()`.

- [ ] **Step 2: Eseguire — deve fallire (metodo protected, `getSourceId()` non riconosce ancora `geohub_id`)**

Run: `docker exec -it php-maphub vendor/bin/pest wm-package/tests/Unit/Models/TaxonomyWhereGeohubSourceTest.php`
Expected: FAIL — `test_with_collision_counter_is_publicly_callable` fallisce con `Error: Call to protected method`.

- [ ] **Step 3: Applicare le modifiche minime**

In `wm-package/src/Models/TaxonomyWhere.php`:

1. Cambiare la visibilità del metodo esistente:
   ```php
   protected function withCollisionCounter(string $base): string
   ```
   in:
   ```php
   public function withCollisionCounter(string $base): string
   ```
2. Estendere `getSourceId()`:
   ```php
   public function getSourceId(): ?string
   {
       $sourceId = $this->properties['osmfeatures_id']
           ?? $this->properties['osm2cai_id']
           ?? $this->properties['geohub_id']
           ?? null;

       return $sourceId !== null ? (string) $sourceId : null;
   }
   ```

- [ ] **Step 4: Rieseguire — deve passare**

Run: `docker exec -it php-maphub vendor/bin/pest wm-package/tests/Unit/Models/TaxonomyWhereGeohubSourceTest.php`
Expected: PASS (3/3)

- [ ] **Step 5: Commit**

```bash
git -C wm-package add src/Models/TaxonomyWhere.php tests/Unit/Models/TaxonomyWhereGeohubSourceTest.php
git -C wm-package commit -m "feat(oc:8486): make TaxonomyWhere::withCollisionCounter public and recognize geohub_id as source id"
```

---

## Task 3: Job `CopyTaxonomyWhereGeometryFromGeohubJob`

**Files:**
- Create: `wm-package/src/Jobs/TaxonomyWhere/CopyTaxonomyWhereGeometryFromGeohubJob.php`
- Test: `wm-package/tests/Feature/Jobs/CopyTaxonomyWhereGeometryFromGeohubJobTest.php` (nuovo)

**Interfaces:**
- Consumes: connessione `geohub` (già configurata), tabella `taxonomy_wheres` sulla connessione `geohub` con colonna `geometry` PostGIS
- Produces: `CopyTaxonomyWhereGeometryFromGeohubJob::dispatch(int $taxonomyWhereId, int $geohubTaxonomyWhereId)` — job in coda che scrive `taxonomy_wheres.geometry` (connessione locale) leggendo da `taxonomy_wheres.geometry` (connessione `geohub`)

- [ ] **Step 1: Scrivere il test**

```php
<?php

namespace Wm\WmPackage\Tests\Feature\Jobs;

require_once __DIR__.'/../../Concerns/SharesGeohubConnectionWithLocal.php';

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use Wm\WmPackage\Jobs\TaxonomyWhere\CopyTaxonomyWhereGeometryFromGeohubJob;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Tests\Concerns\SharesGeohubConnectionWithLocal;

class CopyTaxonomyWhereGeometryFromGeohubJobTest extends TestCase
{
    use DatabaseTransactions, SharesGeohubConnectionWithLocal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shareGeohubConnectionWithLocal();
    }

    public function test_copies_geometry_from_geohub_to_local_taxonomy_where(): void
    {
        $geohubRowId = DB::connection('geohub')->table('taxonomy_wheres')->insertGetId([
            'name' => json_encode(['it' => 'Corsica', 'en' => 'Corsica']),
            'identifier' => 'corsica',
            'properties' => json_encode(['source' => 'geohub']),
            'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Polygon\",\"coordinates\":[[[8.53,41.33],[9.56,41.33],[9.56,43.03],[8.53,43.03],[8.53,41.33]]]}')"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $taxonomyWhere = TaxonomyWhere::create([
            'name' => 'Corsica',
            'properties' => ['source' => 'geohub', 'geohub_id' => $geohubRowId],
        ]);

        (new CopyTaxonomyWhereGeometryFromGeohubJob($taxonomyWhere->id, $geohubRowId))->handle();

        $geometry = DB::selectOne(
            'SELECT ST_AsGeoJSON(geometry) as geojson FROM taxonomy_wheres WHERE id = ?',
            [$taxonomyWhere->id]
        );

        $this->assertNotNull($geometry->geojson);
        $decoded = json_decode($geometry->geojson, true);
        $this->assertSame('Polygon', $decoded['type']);
    }

    public function test_logs_warning_and_does_not_throw_when_geohub_geometry_is_missing(): void
    {
        Log::spy();

        $geohubRowId = DB::connection('geohub')->table('taxonomy_wheres')->insertGetId([
            'name' => json_encode(['it' => 'Europe']),
            'identifier' => 'europe',
            'properties' => json_encode(['source' => 'geohub']),
            'geometry' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $taxonomyWhere = TaxonomyWhere::create([
            'name' => 'Europe',
            'properties' => ['source' => 'geohub', 'geohub_id' => $geohubRowId],
        ]);

        (new CopyTaxonomyWhereGeometryFromGeohubJob($taxonomyWhere->id, $geohubRowId))->handle();

        Log::shouldHaveReceived('warning')->once();
        $this->assertNull($taxonomyWhere->fresh()->geometry);
    }
}
```

- [ ] **Step 2: Eseguire — deve fallire (classe non esiste)**

Run: `docker exec -it php-maphub vendor/bin/pest wm-package/tests/Feature/Jobs/CopyTaxonomyWhereGeometryFromGeohubJobTest.php`
Expected: FAIL — `Class "Wm\WmPackage\Jobs\TaxonomyWhere\CopyTaxonomyWhereGeometryFromGeohubJob" not found`.

- [ ] **Step 3: Implementare il job**

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
use Wm\WmPackage\Models\TaxonomyWhere;

class CopyTaxonomyWhereGeometryFromGeohubJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public int $taxonomyWhereId,
        public int $geohubTaxonomyWhereId,
    ) {}

    public function handle(): void
    {
        $taxonomyWhere = TaxonomyWhere::findOrFail($this->taxonomyWhereId);

        $row = DB::connection('geohub')->selectOne(
            'SELECT ST_AsGeoJSON(geometry) as geojson FROM taxonomy_wheres WHERE id = ?',
            [$this->geohubTaxonomyWhereId]
        );

        $geojson = $row->geojson ?? null;

        if (empty($geojson)) {
            Log::warning('TaxonomyWhere geometry not available from GeoHub', [
                'taxonomy_where_id' => $this->taxonomyWhereId,
                'geohub_taxonomy_where_id' => $this->geohubTaxonomyWhereId,
            ]);

            return;
        }

        DB::statement(
            'UPDATE taxonomy_wheres SET geometry = ST_GeomFromGeoJSON(?) WHERE id = ?',
            [$geojson, $taxonomyWhere->id]
        );
    }

    public function failed(\Throwable $e): void
    {
        Log::error('CopyTaxonomyWhereGeometryFromGeohubJob failed after all retries', [
            'taxonomy_where_id' => $this->taxonomyWhereId,
            'geohub_taxonomy_where_id' => $this->geohubTaxonomyWhereId,
            'error' => $e->getMessage(),
        ]);
    }
}
```

- [ ] **Step 4: Rieseguire — deve passare**

Run: `docker exec -it php-maphub vendor/bin/pest wm-package/tests/Feature/Jobs/CopyTaxonomyWhereGeometryFromGeohubJobTest.php`
Expected: PASS (2/2)

- [ ] **Step 5: Commit**

```bash
git -C wm-package add src/Jobs/TaxonomyWhere/CopyTaxonomyWhereGeometryFromGeohubJob.php tests/Feature/Jobs/CopyTaxonomyWhereGeometryFromGeohubJobTest.php
git -C wm-package commit -m "feat(oc:8486): add CopyTaxonomyWhereGeometryFromGeohubJob to copy geometry from geohub connection"
```

---

## Task 4: Select "geohub" + validazioni preliminari di `handleGeohub()` (gate, app, geohub_id, user GeoHub)

**Files:**
- Modify: `wm-package/src/Nova/Actions/ImportTaxonomyWhere.php`
- Test: `wm-package/tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSourceTest.php` (nuovo)

**Interfaces:**
- Consumes: `resolveApp()`, `RolesAndPermissionsService::allows()` (già esistente in `wm-package/src/Services/RolesAndPermissionsService.php`)
- Produces: opzione `'geohub'` nel Select "source_type"; `handleGeohub(ActionFields $fields)` con i 4 percorsi di uscita anticipata (gate, app non trovata, geohub_id assente, user GeoHub non risolvibile)

- [ ] **Step 1: Scrivere i test per i percorsi di validazione**

```php
<?php

namespace Wm\WmPackage\Tests\Feature\Nova\Actions;

require_once __DIR__.'/../../../Concerns/SharesGeohubConnectionWithLocal.php';

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Fields\ActionFields;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Nova\Actions\ImportTaxonomyWhere;
use Wm\WmPackage\Tests\Concerns\SharesGeohubConnectionWithLocal;

class ImportTaxonomyWhereGeohubSourceTest extends TestCase
{
    use DatabaseTransactions, SharesGeohubConnectionWithLocal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shareGeohubConnectionWithLocal();

        config(['wm-package.super_admin_emails' => ['super@webmapp.it']]);
    }

    private function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create(['email' => 'super@webmapp.it']);
        $this->actingAs($user);

        return $user;
    }

    public function test_geohub_source_returns_danger_for_non_super_admin(): void
    {
        $user = User::factory()->create(['email' => 'nobody@example.com']);
        $this->actingAs($user);

        App::factory()->create(['properties' => ['geohub_id' => 999]]);

        $action = new ImportTaxonomyWhere;
        $fields = ActionFields::make(collect(['source_type' => 'geohub']));

        $response = $action->handle($fields, collect());

        $this->assertStringContainsString('super-admin', $response['danger']);
    }

    public function test_geohub_source_returns_danger_when_app_has_no_geohub_id(): void
    {
        $this->actingAsSuperAdmin();

        App::factory()->create(['properties' => []]);

        $action = new ImportTaxonomyWhere;
        $fields = ActionFields::make(collect(['source_type' => 'geohub']));

        $response = $action->handle($fields, collect());

        $this->assertStringContainsString('geohub_id assente', $response['danger']);
    }

    public function test_geohub_source_returns_danger_when_geohub_app_row_is_missing(): void
    {
        $this->actingAsSuperAdmin();

        // 999999 non corrisponde a nessuna riga "apps" esistente (stessa tabella, connessione condivisa nei test)
        App::factory()->create(['properties' => ['geohub_id' => 999999]]);

        $action = new ImportTaxonomyWhere;
        $fields = ActionFields::make(collect(['source_type' => 'geohub']));

        $response = $action->handle($fields, collect());

        $this->assertStringContainsString('user GeoHub', $response['danger']);
    }
}
```

- [ ] **Step 2: Eseguire — deve fallire (source_type 'geohub' non gestito, ritorna "Sorgente non valida")**

Run: `docker exec -it php-maphub vendor/bin/pest wm-package/tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSourceTest.php`
Expected: FAIL su tutti e 3 (messaggio contiene "Sorgente non valida" invece del messaggio atteso).

- [ ] **Step 3: Aggiungere l'opzione Select e lo scheletro di `handleGeohub()`**

In `wm-package/src/Nova/Actions/ImportTaxonomyWhere.php`:

1. In `handle()`, aggiungere il nuovo branch prima del `return Action::danger('Sorgente non valida.');`:
   ```php
   if ($sourceType === 'geohub') {
       return $this->handleGeohub($fields);
   }
   ```
2. In `fields()`, aggiungere l'opzione nel `Select::make('Sorgente', 'source_type')->options([...])`:
   ```php
   'geohub' => __('GeoHub — Where senza admin_level'),
   ```
3. Aggiungere gli import necessari in testa al file:
   ```php
   use Illuminate\Support\Facades\DB;
   use Wm\WmPackage\Services\RolesAndPermissionsService;
   use Wm\WmPackage\Jobs\TaxonomyWhere\CopyTaxonomyWhereGeometryFromGeohubJob;
   ```
4. Aggiungere il nuovo metodo privato (solo i 4 percorsi di validazione per ora, senza ancora la query/loop — quello arriva nel Task 5):
   ```php
   private function handleGeohub(ActionFields $fields): mixed
   {
       if (! RolesAndPermissionsService::allows(request())) {
           return Action::danger('Sorgente GeoHub riservata ai super-admin.');
       }

       $app = $this->resolveApp($fields);
       if (is_string($app)) {
           return Action::danger($app);
       }

       if (empty($app->geohub_id)) {
           return Action::danger("L'App selezionata non ha un geohub_id assente: nessun collegamento GeoHub noto.");
       }

       $geohubApp = DB::connection('geohub')->table('apps')->where('id', $app->geohub_id)->first();
       if (! $geohubApp || empty($geohubApp->user_id)) {
           return Action::danger('Impossibile risolvere lo user GeoHub per questa App.');
       }

       // Continua nel Task 5.
       return Action::message('Validazione completata.');
   }
   ```

   Nota: il messaggio di danger per `geohub_id` assente contiene volutamente la sotto-stringa esatta `geohub_id assente` per matchare il test — riformulare la frase in modo naturale mantenendo quella sotto-stringa, es. `"L'App selezionata non ha alcun geohub_id assente"` è scorretto grammaticalmente: usare `"App non collegata a GeoHub (geohub_id assente)."` che contiene comunque `geohub_id assente`.

- [ ] **Step 4: Rieseguire — devono passare**

Run: `docker exec -it php-maphub vendor/bin/pest wm-package/tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSourceTest.php`
Expected: PASS (3/3)

- [ ] **Step 5: Commit**

```bash
git -C wm-package add src/Nova/Actions/ImportTaxonomyWhere.php tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSourceTest.php
git -C wm-package commit -m "feat(oc:8486): add geohub source option with super-admin gate and app/geohub_id/user validation"
```

---

## Task 5: Query GeoHub, lookup a due passi, create/update, dispatch job

**Files:**
- Modify: `wm-package/src/Nova/Actions/ImportTaxonomyWhere.php`
- Modify: `wm-package/tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSourceTest.php` (aggiungere nuovi test allo stesso file del Task 4)

**Interfaces:**
- Consumes: `TaxonomyWhere::withCollisionCounter()` (public, Task 2), `CopyTaxonomyWhereGeometryFromGeohubJob::dispatch()` (Task 3), `finalizeWithTracksSync()` (Task 1)
- Produces: comportamento completo di `handleGeohub()` — create/update/idempotenza/fallback identifier

- [ ] **Step 1: Scrivere i test per il flusso completo — aggiungere al file esistente `ImportTaxonomyWhereGeohubSourceTest.php`**

```php
use Illuminate\Support\Facades\Bus;
use Wm\WmPackage\Jobs\TaxonomyWhere\CopyTaxonomyWhereGeometryFromGeohubJob;
use Wm\WmPackage\Models\TaxonomyWhere;

// ... dentro la classe ImportTaxonomyWhereGeohubSourceTest, dopo i test del Task 4:

private function insertGeohubWhere(string $identifier, ?int $adminLevel = null): int
{
    return DB::connection('geohub')->table('taxonomy_wheres')->insertGetId([
        'name' => json_encode(['it' => ucfirst($identifier), 'en' => ucfirst($identifier)]),
        'identifier' => $identifier,
        'admin_level' => $adminLevel,
        'source' => 'osm',
        'properties' => json_encode([]),
        'geometry' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

public function test_geohub_source_imports_wheres_without_admin_level_for_app_content(): void
{
    Bus::fake();
    $this->actingAsSuperAdmin();

    $geohubAppUser = DB::connection('geohub')->table('apps')->insertGetId([
        'user_id' => null, 'name' => 'placeholder',
    ]) ?? null;
    // La riga "geohub apps" e' la stessa tabella locale App (connessione condivisa) —
    // usiamo un'App locale come simulazione della riga GeoHub, il cui user_id
    // rappresenta lo user GeoHub owner dei contenuti.
    $geohubOwner = User::factory()->create();
    $geohubAppRow = App::factory()->create(['user_id' => $geohubOwner->id]);

    $app = App::factory()->create(['properties' => ['geohub_id' => $geohubAppRow->id]]);

    $corsicaId = $this->insertGeohubWhere('corsica');
    $liguriaId = $this->insertGeohubWhere('liguria', 4); // ha admin_level, deve essere escluso

    DB::connection('geohub')->table('ec_tracks')->insert([
        'user_id' => $geohubOwner->id, 'name' => 'Track test',
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"LineString\",\"coordinates\":[[9.0,42.0],[9.1,42.1]]}')"),
        'properties' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $geohubTrackId = DB::connection('geohub')->table('ec_tracks')->where('user_id', $geohubOwner->id)->value('id');

    DB::connection('geohub')->table('taxonomy_whereables')->insert([
        'taxonomy_where_id' => $corsicaId,
        'taxonomy_whereable_id' => $geohubTrackId,
        'taxonomy_whereable_type' => 'App\\Models\\EcTrack',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::connection('geohub')->table('taxonomy_whereables')->insert([
        'taxonomy_where_id' => $liguriaId,
        'taxonomy_whereable_id' => $geohubTrackId,
        'taxonomy_whereable_type' => 'App\\Models\\EcTrack',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $action = new ImportTaxonomyWhere;
    $fields = ActionFields::make(collect(['source_type' => 'geohub', 'app_id' => $app->id]));

    $response = $action->handle($fields, collect());

    $this->assertStringContainsString('Creati 1', $response['message']);
    $this->assertDatabaseHas('taxonomy_wheres', [
        'identifier' => 'corsica',
    ]);
    $this->assertDatabaseMissing('taxonomy_wheres', ['identifier' => 'liguria']);
    Bus::assertDispatched(CopyTaxonomyWhereGeometryFromGeohubJob::class);
}

public function test_geohub_source_reuses_geohub_identifier_slug_directly(): void
{
    Bus::fake();
    $this->actingAsSuperAdmin();

    $geohubOwner = User::factory()->create();
    $geohubAppRow = App::factory()->create(['user_id' => $geohubOwner->id]);
    $app = App::factory()->create(['properties' => ['geohub_id' => $geohubAppRow->id]]);

    $francId = $this->insertGeohubWhere('francia');
    DB::connection('geohub')->table('layers')->insert([
        'app_id' => $geohubAppRow->id, 'name' => 'Layer test',
        'properties' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $geohubLayerId = DB::connection('geohub')->table('layers')->where('app_id', $geohubAppRow->id)->value('id');
    DB::connection('geohub')->table('taxonomy_whereables')->insert([
        'taxonomy_where_id' => $francId,
        'taxonomy_whereable_id' => $geohubLayerId,
        'taxonomy_whereable_type' => 'App\\Models\\Layer',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $action = new ImportTaxonomyWhere;
    $fields = ActionFields::make(collect(['source_type' => 'geohub', 'app_id' => $app->id]));

    $action->handle($fields, collect());

    $imported = TaxonomyWhere::whereRaw("properties->>'geohub_id' = ?", [(string) $francId])->first();
    $this->assertNotNull($imported);
    $this->assertSame('francia', $imported->identifier);
    $this->assertSame('geohub', $imported->properties['source']);
}

public function test_geohub_source_is_idempotent_on_reimport(): void
{
    Bus::fake();
    $this->actingAsSuperAdmin();

    $geohubOwner = User::factory()->create();
    $geohubAppRow = App::factory()->create(['user_id' => $geohubOwner->id]);
    $app = App::factory()->create(['properties' => ['geohub_id' => $geohubAppRow->id]]);

    $corsicaId = $this->insertGeohubWhere('corsica');
    DB::connection('geohub')->table('layers')->insert([
        'app_id' => $geohubAppRow->id, 'name' => 'Layer test',
        'properties' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $geohubLayerId = DB::connection('geohub')->table('layers')->where('app_id', $geohubAppRow->id)->value('id');
    DB::connection('geohub')->table('taxonomy_whereables')->insert([
        'taxonomy_where_id' => $corsicaId,
        'taxonomy_whereable_id' => $geohubLayerId,
        'taxonomy_whereable_type' => 'App\\Models\\Layer',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $action = new ImportTaxonomyWhere;
    $fields = ActionFields::make(collect(['source_type' => 'geohub', 'app_id' => $app->id]));

    $action->handle($fields, collect());
    $response = $action->handle($fields, collect());

    $this->assertStringContainsString('aggiornati 1', $response['message']);
    $this->assertSame(1, TaxonomyWhere::whereRaw("properties->>'geohub_id' = ?", [(string) $corsicaId])->count());
}

public function test_geohub_source_falls_back_to_identifier_lookup_for_manually_created_record(): void
{
    Bus::fake();
    $this->actingAsSuperAdmin();

    // Record preesistente creato manualmente in Nova, senza geohub_id.
    TaxonomyWhere::create(['name' => 'Corsica manuale', 'identifier' => 'corsica']);

    $geohubOwner = User::factory()->create();
    $geohubAppRow = App::factory()->create(['user_id' => $geohubOwner->id]);
    $app = App::factory()->create(['properties' => ['geohub_id' => $geohubAppRow->id]]);

    $corsicaId = $this->insertGeohubWhere('corsica');
    DB::connection('geohub')->table('layers')->insert([
        'app_id' => $geohubAppRow->id, 'name' => 'Layer test',
        'properties' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $geohubLayerId = DB::connection('geohub')->table('layers')->where('app_id', $geohubAppRow->id)->value('id');
    DB::connection('geohub')->table('taxonomy_whereables')->insert([
        'taxonomy_where_id' => $corsicaId,
        'taxonomy_whereable_id' => $geohubLayerId,
        'taxonomy_whereable_type' => 'App\\Models\\Layer',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $action = new ImportTaxonomyWhere;
    $fields = ActionFields::make(collect(['source_type' => 'geohub', 'app_id' => $app->id]));

    $action->handle($fields, collect());

    $this->assertSame(1, TaxonomyWhere::where('identifier', 'corsica')->count());
    $updated = TaxonomyWhere::where('identifier', 'corsica')->first();
    $this->assertSame($corsicaId, $updated->properties['geohub_id']);
}
```

- [ ] **Step 2: Eseguire — devono fallire (handleGeohub si ferma al messaggio "Validazione completata.")**

Run: `docker exec -it php-maphub vendor/bin/pest wm-package/tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSourceTest.php`
Expected: FAIL sui 4 nuovi test.

- [ ] **Step 3: Implementare il corpo completo di `handleGeohub()`**

Sostituire la riga `// Continua nel Task 5.` e il `return Action::message('Validazione completata.');` con:

```php
       $rows = DB::connection('geohub')->select(<<<'SQL'
           select distinct tw.id, tw.name, tw.identifier
           from taxonomy_wheres tw
           join taxonomy_whereables twa on tw.id = twa.taxonomy_where_id
           where tw.admin_level is null
             and (
               (twa.taxonomy_whereable_type like '%EcPoi%' and twa.taxonomy_whereable_id in (select id from ec_pois where user_id = ?))
               or (twa.taxonomy_whereable_type like '%EcTrack%' and twa.taxonomy_whereable_id in (select id from ec_tracks where user_id = ?))
               or (twa.taxonomy_whereable_type like '%Layer%' and twa.taxonomy_whereable_id in (select id from layers where app_id = ?))
             )
           SQL, [$geohubApp->user_id, $geohubApp->user_id, $app->geohub_id]);

       if (count($rows) === 0) {
           return Action::danger('GeoHub non ha restituito where senza admin_level per questa App.');
       }

       $created = 0;
       $updated = 0;

       foreach ($rows as $row) {
           $existing = TaxonomyWhere::whereRaw("properties->>'geohub_id' = ?", [(string) $row->id])->first();

           if (! $existing && $row->identifier) {
               $existing = TaxonomyWhere::where('identifier', $row->identifier)->first();
           }

           $properties = [
               'geohub_id' => $row->id,
               'source' => 'geohub',
               'admin_level' => null,
           ];

           if ($existing) {
               $existing->update([
                   'name' => $row->name,
                   'properties' => array_merge($existing->properties ?? [], $properties),
               ]);
               $this->assignTaxonomyUserFromApp($existing, $app);
               CopyTaxonomyWhereGeometryFromGeohubJob::dispatch($existing->id, $row->id);
               $updated++;

               continue;
           }

           $taxonomyWhere = new TaxonomyWhere([
               'name' => $row->name,
               'properties' => $properties,
           ]);
           $taxonomyWhere->identifier = $row->identifier
               ? $taxonomyWhere->withCollisionCounter($row->identifier)
               : null;
           $taxonomyWhere->save();

           $this->assignTaxonomyUserFromApp($taxonomyWhere, $app);
           CopyTaxonomyWhereGeometryFromGeohubJob::dispatch($taxonomyWhere->id, $row->id);
           $created++;
       }

       $msg = "Creati {$created} record, aggiornati {$updated} record TaxonomyWhere da GeoHub. Geometrie in copia in background.";
       $msg = $this->finalizeWithTracksSync($msg);

       return Action::message($msg);
```

Il campo `name` restituito da GeoHub è già una stringa JSON tradotta (colonna `name` json su GeoHub) — `TaxonomyWhere::$name` usa `Spatie\Translatable` (verificare `HasTranslations`/cast su `Taxonomy` abstract model) e accetta sia stringa JSON che array in scrittura; se il test `test_geohub_source_reuses_geohub_identifier_slug_directly` fallisce sull'assegnazione di `name`, decodificare esplicitamente con `json_decode($row->name, true)` prima di passarlo a `update()`/al costruttore.

- [ ] **Step 4: Rieseguire tutti i test della action (Task 1, 4 e 5 insieme, per la regressione completa)**

Run: `docker exec -it php-maphub vendor/bin/pest wm-package/tests/Feature/Nova/Actions/ImportTaxonomyWhereTest.php wm-package/tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSourceTest.php`
Expected: PASS su tutti (4 + 3 + 4 = 11 test)

- [ ] **Step 5: Commit**

```bash
git -C wm-package add src/Nova/Actions/ImportTaxonomyWhere.php tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSourceTest.php
git -C wm-package commit -m "feat(oc:8486): implement geohub where import with two-step idempotency lookup and geometry job dispatch"
```

---

## Task 6: Traduzioni it/en

**Files:**
- Modify: `wm-package/resources/lang/it.json`
- Modify: `wm-package/resources/lang/en.json`

**Interfaces:**
- Consumes: nessuna
- Produces: chiavi di traduzione per la nuova label Select e i messaggi di `Action::danger()`/`Action::message()` introdotti in `handleGeohub()`

- [ ] **Step 1: Verificare le chiavi mancanti**

Le stringhe letterali usate in `handleGeohub()` sono già passate a `__()` solo per la label Select (`'geohub' => __('GeoHub — Where senza admin_level')`); i messaggi di `Action::danger()`/`Action::message()` negli altri due handler esistenti (`handleOsmfeatures`/`handleOsm2cai`) NON sono avvolti in `__()` — per coerenza con lo stile esistente della stessa classe, lasciare invariati anche i nuovi messaggi di `handleGeohub()` (nessuna nuova chiave di traduzione per i messaggi, solo per la label Select).

- [ ] **Step 2: Aggiungere la chiave in `wm-package/resources/lang/it.json`**

```json
"GeoHub — Where senza admin_level": "GeoHub — Where senza admin_level"
```

- [ ] **Step 3: Aggiungere la chiave in `wm-package/resources/lang/en.json`**

```json
"GeoHub — Where senza admin_level": "GeoHub — Territories without admin_level"
```

- [ ] **Step 4: Verificare che il file JSON resti valido**

Run: `docker exec -it php-maphub php -r "json_decode(file_get_contents('wm-package/resources/lang/it.json'), true, 512, JSON_THROW_ON_ERROR); json_decode(file_get_contents('wm-package/resources/lang/en.json'), true, 512, JSON_THROW_ON_ERROR); echo 'valid';"`
Expected: output `valid`, nessuna eccezione.

- [ ] **Step 5: Commit**

```bash
git -C wm-package add resources/lang/it.json resources/lang/en.json
git -C wm-package commit -m "feat(oc:8486): add it/en translation for geohub source select label"
```

---

## Self-Review (compilata durante la stesura di questo piano)

**Spec coverage:** ogni requisito di `overview.md` è coperto — gate super-admin (Task 4), lookup a due passi (Task 5), reuso identifier GeoHub (Task 5), idempotenza via `geohub_id` (Task 5), job asincrono per la geometria (Task 3), `syncTracksTaxonomyWhere()` finale (Task 1 trait + Task 5), traduzioni it/en (Task 6), refactoring condiviso con test di regressione (Task 1). Il prerequisito ambientale (colonna `identifier` mancante) non era nell'overview — aggiunto come Task 0 dopo averlo verificato concretamente in questo ambiente.

**Placeholder scan:** nessun placeholder — ogni step ha codice PHP completo o comando eseguibile.

**Type consistency:** `resolveApp(): App|string` usato in modo identico in Task 1 (refactor) e Task 4 (nuovo handler); `withCollisionCounter(string $base): string` stessa firma tra Task 2 (modifica visibilità) e Task 5 (uso); `CopyTaxonomyWhereGeometryFromGeohubJob::__construct(int $taxonomyWhereId, int $geohubTaxonomyWhereId)` stessa firma tra Task 3 (creazione) e Task 5 (dispatch).
