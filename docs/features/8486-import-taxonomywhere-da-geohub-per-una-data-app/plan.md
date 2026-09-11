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


---

> Ticket: oc:8486 (follow-up)

# Follow-up: pannello di selezione where — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prima di importare le where GeoHub senza `admin_level` per un'App, mostrare all'admin una lista di checkbox (una per where candidata) — tutte selezionate di default — e importare solo quelle rimaste selezionate al submit.

**Architecture:** Nuovo campo Nova `BooleanGroup` con `dependsOn(['source_type', 'app_id'], ...)` che popola dinamicamente le opzioni interrogando GeoHub (stessa query già usata da `handleGeohub()`, estratta in un metodo condiviso). `handleGeohub()` filtra le righe candidate sulla selezione ricevuta. Gate super-admin estratto in un metodo condiviso, richiamato sia dal callback `dependsOn` sia da `handleGeohub()`.

**Tech Stack:** Laravel 12, PHP 8.4, Nova 5.7.6 (verificato: supporta `dependsOn` con recalculation asincrona delle opzioni), Pest.

**Spec:** `wm-package/docs/features/8486-import-taxonomywhere-da-geohub-per-una-data-app/overview.md`, sezione "## Follow-up post-rilascio: pannello di selezione where (ticket riaperto)"

## Global Constraints

- Scope limitato alla sola sorgente `geohub` — `osmfeatures`/`osm2cai` non toccate
- Nessuna estensione a componenti Vue custom — solo campi Nova nativi (`BooleanGroup`)
- Nessuna euristica di esclusione automatica per voci a bassa informatività (`europe`/`africa`/`area-*`)
- Nessuna persistenza della selezione tra un'esecuzione e l'altra
- Commit convention: `feat(oc:8486): ...` — nessun commit o branch automatico, sono istruzioni testuali per l'utente
- **Branch**: creare `feature/oc-8486-geohub-where-selection-panel` da `develop` (non da `feature/oc-8486-import-taxonomywhere-da-geohub-per-una-data-app`, ormai mergiato) prima di scrivere qualsiasi file

---

## Task 1: Estrarre gate e query condivisi nel trait

**Files:**
- Modify: `src/Nova/Actions/Concerns/HasTaxonomyWhereImportHelpers.php`
- Modify: `src/Nova/Actions/ImportTaxonomyWhere.php:264-292` (sostituire il check gate inline e la risoluzione `$geohubApp`/query con le chiamate al trait)
- Test: `tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSourceTest.php` (nessuna asserzione nuova qui — verifica solo che il comportamento esistente non regredisca)

**Interfaces:**
- Produces:
  - `HasTaxonomyWhereImportHelpers::isGeohubSourceAllowed(?\Illuminate\Contracts\Auth\Authenticatable $user): bool`
  - `HasTaxonomyWhereImportHelpers::resolveGeohubApp(App $app): object|string` (oggetto riga `apps` di GeoHub, oppure stringa di errore da passare ad `Action::danger()`)
  - `HasTaxonomyWhereImportHelpers::fetchGeohubCandidateWheres(App $app, object $geohubApp): array` (righe con `id`, `name`, `identifier`)
  - `HasTaxonomyWhereImportHelpers::resolveAppFromFormData(\Laravel\Nova\Fields\FormData $formData): ?App` (variante di `resolveApp()` per il contesto `dependsOn`, dove il form non è ancora stato sottomesso — nessun messaggio di errore, solo `null` se non risolvibile)

- [ ] **Step 1: Scrivere il test di regressione che verifica il comportamento attuale (baseline, deve già passare)**

Nel file esistente `tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSourceTest.php`, aggiungi in fondo alla classe:

```php
public function test_geohub_source_still_returns_danger_for_non_super_admin_after_refactor(): void
{
    $user = User::factory()->create(['email' => 'nobody@example.com']);
    $this->actingAs($user);

    App::factory()->create(['properties' => ['geohub_id' => 999]]);

    $action = new ImportTaxonomyWhere;
    $fields = new ActionFields(collect(['source_type' => 'geohub']), collect());

    $response = $action->handle($fields, collect());

    $this->assertStringContainsString('super-admin', $response['danger']);
}
```

- [ ] **Step 2: Eseguire — deve già passare (comportamento attuale, nessun refactor ancora fatto)**

Run: `docker exec php-maphub vendor/bin/pest wm-package/tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSourceTest.php --filter=still_returns_danger_for_non_super_admin_after_refactor`
Expected: PASS — baseline confermata prima di toccare il codice.

- [ ] **Step 3: Aggiungere i 4 metodi condivisi al trait**

In `src/Nova/Actions/Concerns/HasTaxonomyWhereImportHelpers.php`, aggiungi gli import in testa al file:

```php
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Fields\FormData;
use Wm\WmPackage\Services\RolesAndPermissionsService;
```

Poi aggiungi questi 4 metodi dentro il trait, dopo `resolveApp()`:

```php
    /**
     * Gate condiviso per la sorgente geohub — richiamato sia dal callback
     * dependsOn del campo di selezione (contesto: gestione campi, request
     * separata dall'esecuzione dell'azione) sia da handleGeohub() (contesto:
     * esecuzione dell'azione), per evitare due controlli indipendenti che
     * potrebbero disallinearsi in futuro.
     */
    protected function isGeohubSourceAllowed(?Authenticatable $user): bool
    {
        return RolesAndPermissionsService::allowsUser($user);
    }

    /**
     * Variante di resolveApp() per il contesto dependsOn: il form non è
     * ancora stato sottomesso, quindi non esiste un messaggio di errore da
     * mostrare — solo "risolta" o "non risolta" (il campo va semplicemente
     * nascosto/svuotato in quel caso, non un Action::danger()).
     */
    protected function resolveAppFromFormData(FormData $formData): ?App
    {
        $apps = App::all();
        if ($apps->count() === 1) {
            return $apps->first();
        }

        $appId = $formData->get('app_id');
        if (! $appId) {
            return null;
        }

        return App::find($appId);
    }

    /**
     * Risolve la riga "apps" su GeoHub per l'App locale selezionata.
     *
     * @return object|string La riga GeoHub, oppure il messaggio di errore da
     *                       passare ad Action::danger() se la risoluzione fallisce.
     */
    protected function resolveGeohubApp(App $app): object|string
    {
        if (empty($app->geohub_id)) {
            return 'App non collegata a GeoHub (geohub_id assente).';
        }

        $geohubApp = DB::connection('geohub')->table('apps')->where('id', $app->geohub_id)->first();
        if (! $geohubApp || empty($geohubApp->user_id)) {
            return 'Impossibile risolvere lo user GeoHub per questa App.';
        }

        return $geohubApp;
    }

    /**
     * Query condivisa: taxonomy_where GeoHub senza admin_level collegate ai
     * contenuti (EcPoi/EcTrack/Layer) dell'App — richiamata sia per popolare
     * il pannello di selezione sia dentro handleGeohub() per i dati completi.
     *
     * @return array<int, object{id: int, name: mixed, identifier: ?string}>
     */
    protected function fetchGeohubCandidateWheres(App $app, object $geohubApp): array
    {
        return DB::connection('geohub')->select(<<<'SQL'
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
    }
```

- [ ] **Step 4: Riscrivere `handleGeohub()` per usare i metodi condivisi**

In `src/Nova/Actions/ImportTaxonomyWhere.php`, sostituisci le righe 262-296 (dall'inizio del metodo fino al check `count($rows) === 0`) con:

```php
    private function handleGeohub(ActionFields $fields): mixed
    {
        if (! $this->isGeohubSourceAllowed(auth()->user())) {
            return Action::danger('Sorgente GeoHub riservata ai super-admin.');
        }

        $app = $this->resolveApp($fields);
        if (is_string($app)) {
            return Action::danger($app);
        }

        $geohubApp = $this->resolveGeohubApp($app);
        if (is_string($geohubApp)) {
            return Action::danger($geohubApp);
        }

        $rows = $this->fetchGeohubCandidateWheres($app, $geohubApp);

        if (count($rows) === 0) {
            return Action::danger('GeoHub non ha restituito where senza admin_level per questa App.');
        }
```

Il resto del metodo (dal `$created = 0;` in poi) resta invariato in questo task — la logica di filtro sulla selezione arriva nel Task 3.

- [ ] **Step 5: Rieseguire tutta la suite esistente della action — deve restare verde**

Run: `docker exec php-maphub vendor/bin/pest wm-package/tests/Feature/Nova/Actions/ImportTaxonomyWhereTest.php wm-package/tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSourceTest.php`
Expected: PASS su tutti i test esistenti (11 + il nuovo del Step 1 = 12), nessuna regressione — il comportamento esterno di `handleGeohub()` non è cambiato, solo la sua implementazione interna.

- [ ] **Step 6: Commit**

```bash
git add src/Nova/Actions/Concerns/HasTaxonomyWhereImportHelpers.php src/Nova/Actions/ImportTaxonomyWhere.php tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSourceTest.php
git commit -m "refactor(oc:8486): extract geohub gate/query into shared trait methods"
```

---

## Task 2: Campo `BooleanGroup` con opzioni dinamiche

**Files:**
- Modify: `src/Nova/Actions/ImportTaxonomyWhere.php` (metodo `fields()`)
- Test: `tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSelectionPanelTest.php` (nuovo)

**Interfaces:**
- Consumes: `isGeohubSourceAllowed()`, `resolveAppFromFormData()`, `resolveGeohubApp()`, `fetchGeohubCandidateWheres()` (Task 1)
- Produces: campo Nova `geohub_where_ids` (BooleanGroup) nel form dell'azione — chiave = id GeoHub come stringa, valore = bool (selezionato/deselezionato), letto in `handleGeohub()` nel Task 3

- [ ] **Step 1: Scrivere il test — verifica che le opzioni vengano popolate correttamente per un'App con candidati noti**

`dependsOn` non è chiamabile direttamente in un test Pest come una funzione isolata: va invocato attraverso l'infrastruttura reale di Nova. Il modo più diretto e già usato in questo package per testare un field `dependsOn` è chiamare l'endpoint HTTP di Nova che risolve i field dependenti (`POST /nova-api/{resource}/field/{field}` o, per le action, l'endpoint dei campi dell'azione) — MA questo package non ha ancora un precedente di test HTTP per un `dependsOn` di un'Action. Verifica prima se esiste un precedente per `AbstractUserResource::PermissionBooleanGroup` (il suo `dependsOn`) in `tests/`, per riusare lo stesso approccio:

```bash
grep -rn "dependsOn\|field/roles\|update-fields" tests/Feature/Nova/ 2>/dev/null
```

Se non esiste alcun test HTTP per un `dependsOn`, testa la LOGICA del callback direttamente estraendola in un metodo pubblico/protetto testabile in isolamento, invece di passare dall'endpoint Nova (più robusto e meno fragile di un test HTTP end-to-end su un meccanismo interno di Nova). Rifattorizza quindi il callback per delegare a un metodo nominato:

```php
public function buildGeohubWhereOptions(?\Illuminate\Contracts\Auth\Authenticatable $user, ?string $sourceType, ?int $appId): array
{
    if ($sourceType !== 'geohub') {
        return [];
    }

    if (! $this->isGeohubSourceAllowed($user)) {
        return [];
    }

    $apps = App::all();
    $app = $apps->count() === 1 ? $apps->first() : ($appId ? App::find($appId) : null);
    if (! $app) {
        return [];
    }

    $geohubApp = $this->resolveGeohubApp($app);
    if (is_string($geohubApp)) {
        return [];
    }

    $rows = $this->fetchGeohubCandidateWheres($app, $geohubApp);
    if (count($rows) === 0) {
        return [];
    }

    $geohubIds = array_map(fn ($row) => (string) $row->id, $rows);
    $importedGeohubIds = TaxonomyWhere::whereNotNull('properties->geohub_id')
        ->pluck('properties->geohub_id')
        ->map(fn ($v) => (string) $v)
        ->all();

    $options = [];
    foreach ($rows as $row) {
        $name = is_string($row->name) ? (json_decode($row->name, true) ?? $row->name) : $row->name;
        $label = is_array($name) ? ($name['it'] ?? $name['en'] ?? (reset($name) ?: $row->identifier)) : $name;
        $label = $label.' — '.$row->identifier;

        if (in_array((string) $row->id, $importedGeohubIds, true)) {
            $label .= ' ('.__('già importata').')';
        }

        $options[(string) $row->id] = $label;
    }

    return $options;
}
```

Questo metodo è **pubblico e testabile direttamente**, senza passare dall'infrastruttura HTTP di Nova — il callback `dependsOn` (Step 3) lo richiamerà passando i valori letti da `$formData`/`$request->user()`.

Scrivi il test in `tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSelectionPanelTest.php`:

```php
<?php

namespace Wm\WmPackage\Tests\Feature\Nova\Actions;

require_once __DIR__.'/../../../Concerns/SharesGeohubConnectionWithLocal.php';

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Nova\Actions\ImportTaxonomyWhere;
use Wm\WmPackage\Tests\Concerns\SharesGeohubConnectionWithLocal;

class ImportTaxonomyWhereGeohubSelectionPanelTest extends TestCase
{
    use DatabaseTransactions, SharesGeohubConnectionWithLocal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shareGeohubConnectionWithLocal();

        config(['wm-package.super_admin_emails' => ['super@webmapp.it']]);
    }

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

    public function test_options_are_empty_when_source_is_not_geohub(): void
    {
        $user = User::factory()->create(['email' => 'super@webmapp.it']);

        $action = new ImportTaxonomyWhere;
        $options = $action->buildGeohubWhereOptions($user, 'osm2cai', null);

        $this->assertSame([], $options);
    }

    public function test_options_are_empty_for_non_super_admin(): void
    {
        $user = User::factory()->create(['email' => 'nobody@example.com']);

        $geohubOwner = User::factory()->create();
        $geohubAppRow = App::factory()->create(['user_id' => $geohubOwner->id]);
        $app = App::factory()->create(['properties' => ['geohub_id' => $geohubAppRow->id]]);

        $action = new ImportTaxonomyWhere;
        $options = $action->buildGeohubWhereOptions($user, 'geohub', $app->id);

        $this->assertSame([], $options);
    }

    public function test_options_are_populated_for_super_admin_with_known_candidates(): void
    {
        $user = User::factory()->create(['email' => 'super@webmapp.it']);

        $geohubOwner = User::factory()->create();
        $geohubAppRow = App::factory()->create(['user_id' => $geohubOwner->id]);
        $app = App::factory()->create(['properties' => ['geohub_id' => $geohubAppRow->id]]);

        $corsicaId = $this->insertGeohubWhere('corsica');
        $liguriaId = $this->insertGeohubWhere('liguria', 4);

        DB::connection('geohub')->table('layers')->insert([
            'app_id' => $geohubAppRow->id, 'name' => 'Layer test',
            'properties' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $geohubLayerId = DB::connection('geohub')->table('layers')->where('app_id', $geohubAppRow->id)->value('id');

        DB::connection('geohub')->table('taxonomy_whereables')->insert([
            ['taxonomy_where_id' => $corsicaId, 'taxonomy_whereable_id' => $geohubLayerId, 'taxonomy_whereable_type' => 'App\\Models\\Layer', 'created_at' => now(), 'updated_at' => now()],
            ['taxonomy_where_id' => $liguriaId, 'taxonomy_whereable_id' => $geohubLayerId, 'taxonomy_whereable_type' => 'App\\Models\\Layer', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $action = new ImportTaxonomyWhere;
        $options = $action->buildGeohubWhereOptions($user, 'geohub', $app->id);

        $this->assertArrayHasKey((string) $corsicaId, $options);
        $this->assertArrayNotHasKey((string) $liguriaId, $options, 'liguria ha admin_level, non deve comparire');
        $this->assertStringContainsString('Corsica', $options[(string) $corsicaId]);
        $this->assertStringContainsString('corsica', $options[(string) $corsicaId]);
    }

    public function test_already_imported_where_is_labeled(): void
    {
        $user = User::factory()->create(['email' => 'super@webmapp.it']);

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
            'taxonomy_where_id' => $corsicaId, 'taxonomy_whereable_id' => $geohubLayerId,
            'taxonomy_whereable_type' => 'App\\Models\\Layer', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Simula una where "già importata" con lo stesso geohub_id.
        $imported = new TaxonomyWhere(['name' => 'Corsica']);
        $imported->identifier = 'corsica';
        $imported->properties = ['geohub_id' => $corsicaId, 'source' => 'geohub'];
        $imported->save();

        $action = new ImportTaxonomyWhere;
        $options = $action->buildGeohubWhereOptions($user, 'geohub', $app->id);

        $this->assertStringContainsString('già importata', $options[(string) $corsicaId]);
    }
}
```

- [ ] **Step 2: Eseguire — deve fallire (metodo non esiste ancora)**

Run: `docker exec php-maphub vendor/bin/pest wm-package/tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSelectionPanelTest.php`
Expected: FAIL — `Call to undefined method Wm\WmPackage\Nova\Actions\ImportTaxonomyWhere::buildGeohubWhereOptions()`.

- [ ] **Step 3: Aggiungere il metodo `buildGeohubWhereOptions()` e il campo `BooleanGroup` con `dependsOn`**

In `src/Nova/Actions/ImportTaxonomyWhere.php`, aggiungi il metodo `buildGeohubWhereOptions()` scritto nello Step 1 (deve essere `public`, subito dopo `handleGeohub()`).

Aggiungi l'import in testa al file: `use Laravel\Nova\Fields\BooleanGroup;` e `use Laravel\Nova\Fields\FormData;`.

Modifica `fields()`, aggiungendo il nuovo campo subito dopo il campo `App` esistente (o subito dopo il campo `Sorgente` se il campo App non è renderizzato perché c'è una sola App):

```php
        $fields[] = BooleanGroup::make(__('Territori da importare'), 'geohub_where_ids')
            ->options([])
            ->dependsOn(
                ['source_type', 'app_id'],
                function (BooleanGroup $field, NovaRequest $request, FormData $formData) {
                    $sourceType = $formData->get('source_type');
                    $appId = $formData->get('app_id');
                    $appId = $appId ? (int) $appId : null;

                    $options = $this->buildGeohubWhereOptions($request->user(), $sourceType, $appId);

                    if (empty($options)) {
                        $field->hide();

                        return;
                    }

                    $field->options($options);
                    $field->default(array_fill_keys(array_keys($options), true));
                }
            );
```

**Nota per l'implementer**: `$field->default(...)` dentro un callback `dependsOn` per un campo di Action non ha un precedente verificato in questo package (l'unico precedente, `PermissionBooleanGroup` in `AbstractUserResource.php:127-145`, usa `dependsOn` solo per ricalcolare `options()`, non per preselezionare valori). Verifica dal vivo in Nova (vedi Step 4) che le checkbox risultino effettivamente pre-selezionate quando il pannello si popola. **Se non lo sono**, il fallback pragmatico è impostare direttamente `$field->value = array_fill_keys(array_keys($options), true);` subito dopo `$field->options($options);` (la proprietà `value` esiste sulla classe base `Field` di Nova, usata internamente da `resolveForAction()` per lo stesso scopo) — documenta nel report quale dei due meccanismi ha funzionato.

- [ ] **Step 4: Verifica dal vivo in Nova — OBBLIGATORIA per questo task (modifica UI)**

Avvia l'ambiente (`composer dev` o equivalente già in esecuzione), accedi a Nova come utente super-admin, vai su `/nova/resources/taxonomy-wheres`, apri l'azione "Import TaxonomyWhere", seleziona Sorgente = GeoHub e un'App con `geohub_id` valorizzato (serve un'App reale importata da GeoHub — se non disponibile in locale, usa lo stesso procedimento di test manuale già documentato in `notes.md` del ciclo precedente: `wm:import-from-geohub app <id>`).

Verifica visivamente:
- Il pannello "Territori da importare" compare SOLO quando la sorgente è GeoHub
- Le checkbox sono TUTTE selezionate all'apertura
- Le where già importate (se presenti) mostrano l'etichetta "(già importata)"
- Cambiando l'App, la lista si ricalcola

Se le checkbox NON risultano pre-selezionate, applica il fallback descritto nello Step 3 e riverifica.

- [ ] **Step 5: Rieseguire il test — deve passare**

Run: `docker exec php-maphub vendor/bin/pest wm-package/tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSelectionPanelTest.php`
Expected: PASS (4/4)

- [ ] **Step 6: Commit**

```bash
git add src/Nova/Actions/ImportTaxonomyWhere.php tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSelectionPanelTest.php
git commit -m "feat(oc:8486): add BooleanGroup selection panel for geohub where import"
```

---

## Task 3: Filtro sulla selezione in `handleGeohub()`

**Files:**
- Modify: `src/Nova/Actions/ImportTaxonomyWhere.php` (metodo `handleGeohub()`)
- Modify: `tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSourceTest.php` (nuovi test)

**Interfaces:**
- Consumes: `$fields->get('geohub_where_ids')` — `null` se il campo non è nel payload (chiamata diretta/test/tinker, retrocompatibile: nessun filtro), array associativo `['<id>' => bool, ...]` se presente (form Nova reale, submission di un `BooleanGroup`)

- [ ] **Step 1: Scrivere i test per i 3 scenari (campo assente, selezione parziale, tutto deselezionato)**

Aggiungi in fondo a `tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSourceTest.php`:

```php
public function test_geohub_source_imports_everything_when_selection_field_is_absent(): void
{
    Bus::fake();
    $this->actingAsSuperAdmin();

    $geohubOwner = User::factory()->create();
    $geohubAppRow = App::factory()->create(['user_id' => $geohubOwner->id]);
    $app = App::factory()->create(['properties' => ['geohub_id' => $geohubAppRow->id]]);

    $corsicaId = $this->insertGeohubWhereWithoutIdentifier('Corsica');
    DB::connection('geohub')->table('layers')->insert([
        'app_id' => $geohubAppRow->id, 'name' => 'Layer test',
        'properties' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $geohubLayerId = DB::connection('geohub')->table('layers')->where('app_id', $geohubAppRow->id)->value('id');
    DB::connection('geohub')->table('taxonomy_whereables')->insert([
        'taxonomy_where_id' => $corsicaId, 'taxonomy_whereable_id' => $geohubLayerId,
        'taxonomy_whereable_type' => 'App\\Models\\Layer', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $action = new ImportTaxonomyWhere;
    // Nessuna chiave 'geohub_where_ids' nel payload — simula una chiamata diretta/test/tinker.
    $fields = new ActionFields(collect(['source_type' => 'geohub', 'app_id' => $app->id]), collect());

    $response = $action->handle($fields, collect());

    $this->assertStringContainsString('Creati 1', $response['message']);
}

public function test_geohub_source_imports_only_selected_wheres(): void
{
    Bus::fake();
    $this->actingAsSuperAdmin();

    $geohubOwner = User::factory()->create();
    $geohubAppRow = App::factory()->create(['user_id' => $geohubOwner->id]);
    $app = App::factory()->create(['properties' => ['geohub_id' => $geohubAppRow->id]]);

    $corsicaId = $this->insertGeohubWhereWithoutIdentifier('Corsica');
    $franciaId = $this->insertGeohubWhereWithoutIdentifier('Francia');

    DB::connection('geohub')->table('layers')->insert([
        'app_id' => $geohubAppRow->id, 'name' => 'Layer test',
        'properties' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $geohubLayerId = DB::connection('geohub')->table('layers')->where('app_id', $geohubAppRow->id)->value('id');
    DB::connection('geohub')->table('taxonomy_whereables')->insert([
        ['taxonomy_where_id' => $corsicaId, 'taxonomy_whereable_id' => $geohubLayerId, 'taxonomy_whereable_type' => 'App\\Models\\Layer', 'created_at' => now(), 'updated_at' => now()],
        ['taxonomy_where_id' => $franciaId, 'taxonomy_whereable_id' => $geohubLayerId, 'taxonomy_whereable_type' => 'App\\Models\\Layer', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $action = new ImportTaxonomyWhere;
    // Solo 'corsica' selezionata (true), 'francia' deselezionata (false) — stesso
    // formato JSON che il componente BooleanGroup invia realmente (verificato in
    // BooleanGroup::fillAttributeFromRequest, vendor Nova).
    $fields = new ActionFields(collect([
        'source_type' => 'geohub',
        'app_id' => $app->id,
        'geohub_where_ids' => [(string) $corsicaId => true, (string) $franciaId => false],
    ]), collect());

    $response = $action->handle($fields, collect());

    $this->assertStringContainsString('Creati 1', $response['message']);
    $this->assertDatabaseHas('taxonomy_wheres', ['name->it' => 'Corsica']);
    $imported = TaxonomyWhere::whereRaw("properties->>'geohub_id' = ?", [(string) $corsicaId])->first();
    $this->assertNotNull($imported);
    $notImported = TaxonomyWhere::whereRaw("properties->>'geohub_id' = ?", [(string) $franciaId])->first();
    $this->assertNull($notImported);
}

public function test_geohub_source_returns_danger_when_selection_is_empty(): void
{
    $this->actingAsSuperAdmin();

    $geohubOwner = User::factory()->create();
    $geohubAppRow = App::factory()->create(['user_id' => $geohubOwner->id]);
    $app = App::factory()->create(['properties' => ['geohub_id' => $geohubAppRow->id]]);

    $corsicaId = $this->insertGeohubWhereWithoutIdentifier('Corsica');
    DB::connection('geohub')->table('layers')->insert([
        'app_id' => $geohubAppRow->id, 'name' => 'Layer test',
        'properties' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $geohubLayerId = DB::connection('geohub')->table('layers')->where('app_id', $geohubAppRow->id)->value('id');
    DB::connection('geohub')->table('taxonomy_whereables')->insert([
        'taxonomy_where_id' => $corsicaId, 'taxonomy_whereable_id' => $geohubLayerId,
        'taxonomy_whereable_type' => 'App\\Models\\Layer', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $action = new ImportTaxonomyWhere;
    $fields = new ActionFields(collect([
        'source_type' => 'geohub',
        'app_id' => $app->id,
        'geohub_where_ids' => [(string) $corsicaId => false],
    ]), collect());

    $response = $action->handle($fields, collect());

    $this->assertStringContainsString('Nessuna where selezionata', $response['danger']);
    $this->assertSame(0, TaxonomyWhere::count());
}
```

**Nota**: `insertGeohubWhereWithoutIdentifier()` è l'helper già presente in questo file (introdotto nel fix round 1 del ciclo precedente) — verifica che esista già prima di riscriverlo; se il nome è diverso, adatta le chiamate sopra al nome reale.

- [ ] **Step 2: Eseguire — devono fallire (filtro non ancora implementato)**

Run: `docker exec php-maphub vendor/bin/pest wm-package/tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSourceTest.php --filter="imports_everything_when_selection_field_is_absent|imports_only_selected_wheres|returns_danger_when_selection_is_empty"`
Expected: FAIL sui 3 nuovi test (oggi tutte le righe vengono sempre importate, nessun filtro).

- [ ] **Step 3: Implementare il filtro in `handleGeohub()`**

Subito dopo la riga (già presente da Task 1):
```php
        if (count($rows) === 0) {
            return Action::danger('GeoHub non ha restituito where senza admin_level per questa App.');
        }
```

aggiungi:

```php
        $selection = $fields->get('geohub_where_ids');
        if ($selection !== null) {
            $selectedIds = collect($selection)
                ->filter(fn ($checked) => (bool) $checked)
                ->keys()
                ->map(fn ($id) => (string) $id)
                ->all();

            if (empty($selectedIds)) {
                return Action::danger('Nessuna where selezionata.');
            }

            $rows = array_values(array_filter(
                $rows,
                fn ($row) => in_array((string) $row->id, $selectedIds, true)
            ));

            if (count($rows) === 0) {
                return Action::danger('Nessuna where selezionata.');
            }
        }
```

- [ ] **Step 4: Rieseguire — devono passare**

Run: `docker exec php-maphub vendor/bin/pest wm-package/tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSourceTest.php`
Expected: PASS su tutti (12 precedenti + 3 nuovi = 15)

- [ ] **Step 5: Rieseguire l'intera suite oc:8486 per la regressione finale**

Run: `docker exec php-maphub vendor/bin/pest wm-package/tests/Feature/Nova/Actions/ImportTaxonomyWhereTest.php wm-package/tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSourceTest.php wm-package/tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSelectionPanelTest.php wm-package/tests/Feature/Jobs/CopyTaxonomyWhereGeometryFromGeohubJobTest.php wm-package/tests/Feature/Jobs/SyncTaxonomyWhereTracksJobTest.php wm-package/tests/Unit/Models/TaxonomyWhereGeohubSourceTest.php`
Expected: PASS su tutti (4 + 15 + 4 + 2 + 1 + 3 = 29)

- [ ] **Step 6: Commit**

```bash
git add src/Nova/Actions/ImportTaxonomyWhere.php tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSourceTest.php
git commit -m "feat(oc:8486): filter geohub import to the selected wheres only"
```

---

## Task 4: Verifica manuale end-to-end e documentazione

**Files:**
- Modify: `docs/features/8486-import-taxonomywhere-da-geohub-per-una-data-app/notes.md` (append)

**Interfaces:** nessuna nuova — task di verifica e documentazione.

- [ ] **Step 1: Test manuale contro dati GeoHub reali (containers GeoHub avviati dal dev)**

Ripeti il procedimento già documentato nel ciclo precedente: reset DB da `storage/backups/last_dump.sql.gz`, `wm:import-from-geohub app 28` (Itinera Romanica), poi esegui l'azione da Nova UI (non da tinker, questa volta, per verificare il pannello) selezionando manualmente un sottoinsieme delle 14 where candidate. Verifica che solo le where selezionate vengano create.

- [ ] **Step 2: Verifica il toggle di selezione**

Conferma che deselezionare tutto e riaprire la modale dell'azione (chiudi e riapri, non solo cambia tab) resetta la lista a "tutto selezionato" — comportamento nativo di Nova per i campi `dependsOn`, nessun codice aggiuntivo necessario (decisione presa con il dev: nessun bottone "seleziona/deseleziona tutte" custom, si usa il reset naturale della modale + la "x" nativa del componente per svuotare in un colpo).

- [ ] **Step 3: Aggiornare `notes.md`**

Aggiungi in fondo al file esistente `docs/features/8486-import-taxonomywhere-da-geohub-per-una-data-app/notes.md` (non sovrascrivere le sezioni precedenti):

```markdown

## Follow-up: pannello di selezione where (post-riapertura)

### Decisioni
- Campo `BooleanGroup` (lista di checkbox), non `MultiSelect` — il dev ha chiarito di voler una lista di checkbox reale, non un dropdown con tag
- Nessun bottone "seleziona/deseleziona tutte" custom via JS — Nova non lo espone nativamente per BooleanGroup e costruirlo con hook su un componente Vue vendor comporterebbe un rischio di fragilità non giustificato; si riusa il comportamento nativo (riaprire la modale resetta a tutto selezionato)
- Gate e query GeoHub estratti in metodi condivisi nel trait, riusati sia dal pannello sia dall'esecuzione dell'azione, per evitare due controlli di sicurezza indipendenti
- Distinzione esplicita fra campo assente (nessun filtro, importa tutto — retrocompatibile con chiamate dirette/tinker) e campo presente con selezione vuota (danger)

### Deviazioni dal piano
[Da compilare durante l'esecuzione se emergono deviazioni, es. sul meccanismo `default()` per BooleanGroup dentro dependsOn]
```

Compila la sezione "Deviazioni dal piano" con quanto effettivamente accaduto durante l'esecuzione del Task 2 (in particolare l'esito della verifica sul pre-check delle checkbox).

- [ ] **Step 4: Commit**

```bash
git add docs/features/8486-import-taxonomywhere-da-geohub-per-una-data-app/notes.md
git commit -m "docs(oc:8486): document geohub where selection panel follow-up"
```

---

## Self-Review (compilata durante la stesura di questo piano)

**Spec coverage:** ogni requisito della sezione Follow-up di `overview.md` è coperto — campo BooleanGroup con dependsOn (Task 2), etichetta "già importata" (Task 2), tutto selezionato di default (Task 2), gate condiviso (Task 1), query condivisa (Task 1), distinzione campo assente/vuoto (Task 3), scope limitato a geohub (nessun task tocca osmfeatures/osm2cai). Il toggle "seleziona/deseleziona tutte" richiesto dal dev è stato ridiscusso durante la conversazione (l'utente ha chiesto un field HTML/checkbox invece di MultiSelect) e la soluzione finale concordata (nessun JS custom, comportamento nativo della modale) è documentata nel Task 4 anziché implementata come codice — nessun gap, è una decisione esplicita.

**Placeholder scan:** nessun placeholder — unica area di incertezza dichiarata esplicitamente (il meccanismo `default()` dentro `dependsOn`) è accompagnata da un fallback concreto e una verifica live obbligatoria, non lasciata vaga.

**Type consistency:** `isGeohubSourceAllowed(?Authenticatable $user): bool`, `resolveGeohubApp(App $app): object|string`, `fetchGeohubCandidateWheres(App $app, object $geohubApp): array`, `resolveAppFromFormData(FormData $formData): ?App` usati con la stessa identica firma tra Task 1 (definizione) e Task 2/3 (uso). `buildGeohubWhereOptions()` firma coerente tra Task 2 (definizione) e il callback `dependsOn` che lo consuma.

---

> Ticket: oc:8486 (follow-up 2)

# Follow-up 2: due modali per la selezione — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) o superpowers:executing-plans per eseguire questo piano task-by-task. Gli step usano checkbox (`- [ ]`) per il tracking.
>
> **ATTENZIONE numerazione**: questo file contiene già DUE piani precedenti, entrambi con una propria sequenza "Task 1"..."Task N". Questo terzo piano riparte anch'esso da "Task 1" — stessa collisione già documentata in `.superpowers/sdd/plan/followup-progress.md`. I brief dei task vanno estratti manualmente per range di riga in file nominati `followup2-task-N-brief.md` (non `task-N-brief.md` né `followup-task-N-brief.md`, già usati dai due cicli precedenti), e la ledger di questo ciclo va creata come `.superpowers/sdd/plan/followup2-progress.md`.

**Goal:** Sostituire il pannello di selezione a singola modale (campo `BooleanGroup` + `dependsOn`, introdotto nel follow-up precedente) con un flusso a **due modali Nova separate** originate dalla stessa Action: la prima modale (Sorgente + App, invariata) risolve gate/App/candidati e apre — tramite `Action::modal()` — una seconda modale con un componente Vue custom che mostra la lista di checkbox e, solo al click su un secondo bottone "Importa" separato, esegue realmente l'import via un nuovo endpoint HTTP dedicato.

**Architecture:** `handleGeohub()` smette di eseguire l'import: si ferma dopo aver costruito il payload (righe candidate pronte per il rendering) e ritorna `Action::modal($nome, $payload)` (**esattamente 2 argomenti** — con 3 il metodo si comporta diversamente, vedi Task 1). La seconda modale è un componente Vue registrato con una render function via `Nova.booting()` in un file JS caricato con `Nova::script()` — **non** una replica della build ag-grid/TypeScript/webpack di `LayerFeatures` (sovradimensionata per una checkbox list), ma lo stesso pattern leggero già usato per `trail-registry-notice-card` (`resources/js/domains/trail_registry.js`): nessun bundle da compilare. Il componente esegue da sé una `Nova.request().post(...)` verso un nuovo controller (`GeohubWhereSelectionController`, registrato in `WmPackageServiceProvider` con lo stesso pattern inline già usato per `layer-analytics`) che ri-verifica il gate super-admin, ri-deriva il set autoritativo di where candidate e vi interseca la selezione ricevuta, esegue creazione/aggiornamento/collision-handling (logica estratta da `handleGeohub()` in un nuovo metodo condiviso del trait), dispatcha `Bus::batch(...)->then(...)` come oggi, e risponde con un JSON che il componente mostra inline (spinner → messaggio/contatori o errore → bottone "Chiudi").

**Tech Stack:** Laravel 12, PHP 8.4, PostgreSQL + PostGIS, Nova 5.7.6, Vue 3 (render function, nessuna build dedicata), Pest.

**Spec:** `wm-package/docs/features/8486-import-taxonomywhere-da-geohub-per-una-data-app/overview.md`, sezione "Follow-up 2: due modali separati per la selezione".

## Global Constraints

- Nessun commit/push automatico durante l'esecuzione — solo il controller della pipeline, a fine ciclo, dopo approvazione esplicita dell'utente
- Test con `DatabaseTransactions`, mai `RefreshDatabase` — il DB di sviluppo condiviso contiene dati QA reali non cancellabili
- Identifier/dati di fixture GeoHub generati dinamicamente (`'<base>-'.Str::lower(Str::random(8))`), mai letterali fissi — collisione nota con righe QA reali (`corsica`/`francia`/altri, vedi `CLAUDE.md`)
- **`Action::modal($nome, $payload)` va chiamato con ESATTAMENTE 2 argomenti.** Con 3 argomenti (`Action::modal($nome, [], $payload)`) il metodo ritorna una nuova istanza `Action` no-op (pensata per essere registrata in `actions()`), non un `ActionResponse` — verificato in `vendor/laravel/nova/src/Actions/Action.php:453` e `ActionResponse.php:263`
- Nome del componente Vue/modale: costante `ImportTaxonomyWhere::GEOHUB_WHERE_SELECTION_MODAL_COMPONENT = 'geohub-where-selection-modal'` — deve combaciare esattamente lato PHP (`Action::modal()`) e lato JS (`app.component(...)`)
- Geometria PostGIS sempre in SQL puro (invariato dai cicli precedenti, nessuna modifica in questo ciclo)
- Il nuovo endpoint HTTP **ri-verifica autonomamente** il gate super-admin (`isGeohubSourceAllowed()`) — non eredita alcuna verifica già fatta in `handle()`, essendo un boundary HTTP separato; il middleware `nova` non porta autenticazione Nova (solo `nova.api_middleware` la porta, usato da `nova-api/*`, non da `nova-vendor/*`)
- Commit convention: `refactor(oc:8486): ...` / `feat(oc:8486): ...` — nessun commit o branch automatico, sono istruzioni testuali per l'utente

---

## Task 1: `handleGeohub()` ritorna `Action::modal()` invece di eseguire l'import — estrazione dei metodi condivisi

**Files:**
- Modify: `src/Nova/Actions/ImportTaxonomyWhere.php`
- Modify: `src/Nova/Actions/Concerns/HasTaxonomyWhereImportHelpers.php`
- Modify: `tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSourceTest.php`
- Delete: `tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSelectionPanelTest.php` (testava `buildGeohubWhereOptions()`, rimosso in questo task — la copertura equivalente si sposta sui nuovi test di `handle()` in `ImportTaxonomyWhereGeohubSourceTest.php`)

**Interfaces:**
- Consumes: `isGeohubSourceAllowed()`, `resolveApp()`, `resolveGeohubApp()`, `fetchGeohubCandidateWheres()`, `assignTaxonomyUserFromApp()` (tutti già esistenti nel trait, nessuna modifica alla loro firma)
- Produces:
  - `HasTaxonomyWhereImportHelpers::buildGeohubWhereSelectionPayload(App $app, object $geohubApp): array<int, array{id: string, label: string, checked: bool}>` — righe pronte per il rendering della seconda modale
  - `HasTaxonomyWhereImportHelpers::executeGeohubImport(App $app, object $geohubApp, array $selectedIds): ?array{created: int, updated: int}` — `null` se l'intersezione fra `$selectedIds` e il set candidato autoritativo è vuota. **Unico chiamante reale: il controller del Task 2** (`handle()` non esegue più creazione/aggiornamento)
  - `ImportTaxonomyWhere::GEOHUB_WHERE_SELECTION_MODAL_COMPONENT` (costante pubblica, stringa `'geohub-where-selection-modal'`)

- [ ] **Step 1: Aggiungere i due metodi condivisi al trait**

In `src/Nova/Actions/Concerns/HasTaxonomyWhereImportHelpers.php`, aggiungi in testa al file questi import (oltre a quelli già presenti):

```php
use Illuminate\Support\Facades\Bus;
use Wm\WmPackage\Jobs\TaxonomyWhere\CopyTaxonomyWhereGeometryFromGeohubJob;
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncTaxonomyWhereTracksJob;
```

Poi aggiungi questi due metodi nel trait, subito dopo `fetchGeohubCandidateWheres()`:

```php
    /**
     * Costruisce le righe pronte per il rendering della seconda modale
     * (checkbox list, tutte pre-selezionate) — sostituisce
     * buildGeohubWhereOptions() del follow-up precedente (campo Nova
     * BooleanGroup, ora rimosso). Stessa logica di etichettatura (nome
     * tradotto + identifier + "già importata"), ma il chiamante è handle()
     * stesso: App e riga GeoHub sono già risolte e il gate già verificato
     * PRIMA di arrivare qui, quindi questo metodo non ripete alcun controllo.
     *
     * @return array<int, array{id: string, label: string, checked: bool}>
     */
    protected function buildGeohubWhereSelectionPayload(App $app, object $geohubApp): array
    {
        $rows = $this->fetchGeohubCandidateWheres($app, $geohubApp);
        if (count($rows) === 0) {
            return [];
        }

        // Stesso motivo del ciclo precedente: pluck('properties->geohub_id')
        // sul query builder non funziona su Postgres senza alias esplicito
        // (la colonna estratta si chiama "?column?", non "properties->geohub_id").
        $importedGeohubIds = TaxonomyWhere::whereNotNull('properties->geohub_id')
            ->get()
            ->pluck('properties.geohub_id')
            ->map(fn ($v) => (string) $v)
            ->all();

        $payload = [];
        foreach ($rows as $row) {
            $name = is_string($row->name) ? (json_decode($row->name, true) ?? $row->name) : $row->name;
            $label = is_array($name) ? ($name['it'] ?? $name['en'] ?? (reset($name) ?: $row->identifier)) : $name;
            $label = $label.' — '.$row->identifier;

            if (in_array((string) $row->id, $importedGeohubIds, true)) {
                $label .= ' ('.__('già importata').')';
            }

            $payload[] = [
                'id' => (string) $row->id,
                'label' => $label,
                'checked' => true,
            ];
        }

        return $payload;
    }

    /**
     * Esegue l'import vero e proprio (creazione/aggiornamento/collision
     * handling + dispatch batch geometria) per il sottoinsieme di where
     * selezionato dall'admin nella seconda modale. Unico chiamante reale: il
     * controller HTTP del nuovo endpoint (handle()/handleGeohub() non
     * eseguono più alcuna scrittura). Ri-deriva autonomamente il set
     * autoritativo delle where candidate — non si fida ciecamente di
     * $selectedIds ricevuti dal client — e vi interseca la selezione: un id
     * fuori dal set candidato per questa App viene semplicemente escluso, non
     * causa un errore.
     *
     * @param  array<int, string|int>  $selectedIds
     * @return array{created: int, updated: int}|null Null se l'intersezione
     *                                                 con il set candidato è vuota.
     */
    protected function executeGeohubImport(App $app, object $geohubApp, array $selectedIds): ?array
    {
        $rows = $this->fetchGeohubCandidateWheres($app, $geohubApp);

        $selectedIds = array_map('strval', $selectedIds);
        $rows = array_values(array_filter(
            $rows,
            fn ($row) => in_array((string) $row->id, $selectedIds, true)
        ));

        if (count($rows) === 0) {
            return null;
        }

        $created = 0;
        $updated = 0;
        $geometryJobs = [];

        foreach ($rows as $row) {
            $name = is_string($row->name) ? (json_decode($row->name, true) ?? $row->name) : $row->name;

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
                    'name' => $name,
                    'properties' => array_merge($existing->properties ?? [], $properties),
                ]);
                $this->assignTaxonomyUserFromApp($existing, $app);
                $geometryJobs[] = new CopyTaxonomyWhereGeometryFromGeohubJob($existing->id, $row->id);
                $updated++;

                continue;
            }

            $taxonomyWhere = new TaxonomyWhere([
                'name' => $name,
                'properties' => $properties,
            ]);
            $taxonomyWhere->identifier = $row->identifier
                ? $taxonomyWhere->withCollisionCounter($row->identifier)
                : null;
            $taxonomyWhere->save();

            $this->assignTaxonomyUserFromApp($taxonomyWhere, $app);
            $geometryJobs[] = new CopyTaxonomyWhereGeometryFromGeohubJob($taxonomyWhere->id, $row->id);
            $created++;
        }

        // Stesso motivo dei cicli precedenti: syncTracksTaxonomyWhere() deve
        // partire solo a copia geometrie completata, non subito dopo il
        // dispatch asincrono dei job CopyTaxonomyWhereGeometryFromGeohubJob.
        Bus::batch($geometryJobs)
            ->then(function () {
                SyncTaxonomyWhereTracksJob::dispatch();
            })
            ->dispatch();

        return ['created' => $created, 'updated' => $updated];
    }
```

- [ ] **Step 2: Riscrivere `handleGeohub()`, aggiungere la costante, rimuovere il vecchio campo/metodo**

In `src/Nova/Actions/ImportTaxonomyWhere.php`:

1. Rimuovi questi import (non più usati dopo questo task): `Illuminate\Contracts\Auth\Authenticatable`, `Illuminate\Support\Facades\Bus`, `Laravel\Nova\Fields\BooleanGroup`, `Laravel\Nova\Fields\FormData`, `Wm\WmPackage\Jobs\TaxonomyWhere\CopyTaxonomyWhereGeometryFromGeohubJob`, `Wm\WmPackage\Jobs\TaxonomyWhere\SyncTaxonomyWhereTracksJob` (sono migrati nel trait, Step 1).

2. Aggiungi la costante subito dopo `public $standalone = true;`:

```php
    /**
     * Nome del componente Vue registrato per la seconda modale (Nova.booting,
     * vedi resources/js/geohub-where-selection.js, Task 3) — deve combaciare
     * ESATTAMENTE con la stringa passata ad Action::modal() qui sotto e con
     * app.component(...) lato JS.
     */
    public const GEOHUB_WHERE_SELECTION_MODAL_COMPONENT = 'geohub-where-selection-modal';
```

3. Sostituisci l'intero corpo di `handleGeohub()` (dalla riga `private function handleGeohub` fino alla sua chiusura, prima del metodo `buildGeohubWhereOptions()`) con:

```php
    private function handleGeohub(ActionFields $fields): mixed
    {
        if (! $this->isGeohubSourceAllowed(auth()->user())) {
            return Action::danger('Sorgente GeoHub riservata ai super-admin.');
        }

        $app = $this->resolveApp($fields);
        if (is_string($app)) {
            return Action::danger($app);
        }

        $geohubApp = $this->resolveGeohubApp($app);
        if (is_string($geohubApp)) {
            return Action::danger($geohubApp);
        }

        $rows = $this->buildGeohubWhereSelectionPayload($app, $geohubApp);

        if (count($rows) === 0) {
            return Action::danger('GeoHub non ha restituito where senza admin_level per questa App.');
        }

        // Action::modal() con ESATTAMENTE 2 argomenti ritorna subito un
        // ActionResponse::modal(...) che apre la seconda modale. Con 3
        // argomenti ritornerebbe invece una nuova istanza Action no-op,
        // pensata per essere registrata in actions() — mai eseguita qui.
        // Verificato in vendor/laravel/nova/src/Actions/Action.php:453 e
        // ActionResponse.php:263 (non per analogia).
        return Action::modal(self::GEOHUB_WHERE_SELECTION_MODAL_COMPONENT, [
            'app_id' => $app->id,
            'rows' => $rows,
        ]);
    }
```

4. Rimuovi interamente il metodo pubblico `buildGeohubWhereOptions()` (sostituito da `buildGeohubWhereSelectionPayload()` nel trait, Step 1).

5. In `fields()`, rimuovi interamente il blocco che aggiunge il campo `BooleanGroup::make(...)` (l'ultimo `$fields[] = ...` prima di `return $fields;`) — il metodo torna a contenere solo i due `Select` (Sorgente, App).

- [ ] **Step 3: Riscrivere i test — solo il comportamento di `handle()`/prima modale resta qui**

In `tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSourceTest.php`:

**Rimuovi** questi 7 test (esercitavano l'esecuzione diretta dell'import dentro `handle()`, comportamento non più presente — la copertura equivalente si sposta sul nuovo endpoint, Task 2): `test_geohub_source_imports_wheres_without_admin_level_for_app_content`, `test_geohub_source_reuses_geohub_identifier_slug_directly`, `test_geohub_source_is_idempotent_on_reimport`, `test_geohub_source_falls_back_to_identifier_lookup_for_manually_created_record`, `test_geohub_source_imports_everything_when_selection_field_is_absent`, `test_geohub_source_imports_only_selected_wheres`, `test_geohub_source_returns_danger_when_selection_is_empty`.

**Mantieni invariati** (il comportamento di gate/risoluzione non cambia): `test_geohub_source_returns_danger_for_non_super_admin`, `test_geohub_source_returns_danger_when_app_has_no_geohub_id`, `test_geohub_source_returns_danger_when_geohub_app_row_is_missing`, `test_geohub_source_still_returns_danger_for_non_super_admin_after_refactor`, i metodi helper `actingAsSuperAdmin()`, `insertGeohubWhere()`, `insertGeohubWhereWithoutIdentifier()`.

**Aggiungi** in fondo alla classe questi 3 nuovi test (aggiungi anche `use Wm\WmPackage\Nova\Actions\Responses\Modal;` — attenzione: la classe reale è `Laravel\Nova\Actions\Responses\Modal`, non nel namespace del package — usa `use Laravel\Nova\Actions\Responses\Modal;`):

```php
    public function test_geohub_source_returns_action_modal_with_candidate_rows(): void
    {
        $this->actingAsSuperAdmin();

        $geohubOwner = User::factory()->create();
        $geohubAppRow = App::factory()->create(['user_id' => $geohubOwner->id]);
        $app = App::factory()->create(['properties' => ['geohub_id' => $geohubAppRow->id]]);

        $corsicaId = $this->insertGeohubWhereWithoutIdentifier('Corsica');
        DB::connection('geohub')->table('layers')->insert([
            'app_id' => $geohubAppRow->id, 'name' => 'Layer test',
            'properties' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $geohubLayerId = DB::connection('geohub')->table('layers')->where('app_id', $geohubAppRow->id)->value('id');
        DB::connection('geohub')->table('taxonomy_whereables')->insert([
            'taxonomy_where_id' => $corsicaId, 'taxonomy_whereable_id' => $geohubLayerId,
            'taxonomy_whereable_type' => 'App\\Models\\Layer', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $action = new ImportTaxonomyWhere;
        $fields = new ActionFields(collect(['source_type' => 'geohub', 'app_id' => $app->id]), collect());

        $response = $action->handle($fields, collect());

        $modal = $response['modal'];
        $this->assertInstanceOf(Modal::class, $modal);
        $this->assertSame(ImportTaxonomyWhere::GEOHUB_WHERE_SELECTION_MODAL_COMPONENT, $modal->component);
        $this->assertSame($app->id, $modal->payload['app_id']);
        $this->assertCount(1, $modal->payload['rows']);
        $this->assertSame((string) $corsicaId, $modal->payload['rows'][0]['id']);
        $this->assertTrue($modal->payload['rows'][0]['checked']);
        $this->assertStringContainsString('Corsica', $modal->payload['rows'][0]['label']);
    }

    public function test_geohub_source_excludes_rows_with_admin_level_from_modal_payload(): void
    {
        $this->actingAsSuperAdmin();

        $geohubOwner = User::factory()->create();
        $geohubAppRow = App::factory()->create(['user_id' => $geohubOwner->id]);
        $app = App::factory()->create(['properties' => ['geohub_id' => $geohubAppRow->id]]);

        $corsicaId = $this->insertGeohubWhereWithoutIdentifier('Corsica');
        $liguriaId = $this->insertGeohubWhere('liguria-'.Str::lower(Str::random(8)), 4);

        DB::connection('geohub')->table('layers')->insert([
            'app_id' => $geohubAppRow->id, 'name' => 'Layer test',
            'properties' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $geohubLayerId = DB::connection('geohub')->table('layers')->where('app_id', $geohubAppRow->id)->value('id');
        DB::connection('geohub')->table('taxonomy_whereables')->insert([
            ['taxonomy_where_id' => $corsicaId, 'taxonomy_whereable_id' => $geohubLayerId, 'taxonomy_whereable_type' => 'App\\Models\\Layer', 'created_at' => now(), 'updated_at' => now()],
            ['taxonomy_where_id' => $liguriaId, 'taxonomy_whereable_id' => $geohubLayerId, 'taxonomy_whereable_type' => 'App\\Models\\Layer', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $action = new ImportTaxonomyWhere;
        $fields = new ActionFields(collect(['source_type' => 'geohub', 'app_id' => $app->id]), collect());

        $response = $action->handle($fields, collect());

        $ids = array_column($response['modal']->payload['rows'], 'id');
        $this->assertContains((string) $corsicaId, $ids);
        $this->assertNotContains((string) $liguriaId, $ids, 'liguria ha admin_level, non deve comparire nel payload');
    }

    public function test_geohub_source_labels_already_imported_rows_in_modal_payload(): void
    {
        $this->actingAsSuperAdmin();

        $geohubOwner = User::factory()->create();
        $geohubAppRow = App::factory()->create(['user_id' => $geohubOwner->id]);
        $app = App::factory()->create(['properties' => ['geohub_id' => $geohubAppRow->id]]);

        $corsicaId = $this->insertGeohubWhereWithoutIdentifier('Corsica');
        DB::connection('geohub')->table('layers')->insert([
            'app_id' => $geohubAppRow->id, 'name' => 'Layer test',
            'properties' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $geohubLayerId = DB::connection('geohub')->table('layers')->where('app_id', $geohubAppRow->id)->value('id');
        DB::connection('geohub')->table('taxonomy_whereables')->insert([
            'taxonomy_where_id' => $corsicaId, 'taxonomy_whereable_id' => $geohubLayerId,
            'taxonomy_whereable_type' => 'App\\Models\\Layer', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Simula una where già importata con lo stesso geohub_id.
        $imported = new TaxonomyWhere(['name' => 'Corsica']);
        $imported->identifier = 'imported-'.Str::lower(Str::random(8));
        $imported->properties = ['geohub_id' => $corsicaId, 'source' => 'geohub'];
        $imported->save();

        $action = new ImportTaxonomyWhere;
        $fields = new ActionFields(collect(['source_type' => 'geohub', 'app_id' => $app->id]), collect());

        $response = $action->handle($fields, collect());

        $row = collect($response['modal']->payload['rows'])->firstWhere('id', (string) $corsicaId);
        $this->assertNotNull($row);
        $this->assertStringContainsString('già importata', $row['label']);
    }
```

- [ ] **Step 4: Eliminare il file di test del pannello a singola modale, ormai superato**

```bash
rm tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSelectionPanelTest.php
```

- [ ] **Step 5: Eseguire la suite**

Run: `docker exec php-maphub vendor/bin/pest wm-package/tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSourceTest.php wm-package/tests/Feature/Nova/Actions/ImportTaxonomyWhereTest.php`
Expected: PASS su tutti — 4 test invariati + 3 nuovi = 7 in `ImportTaxonomyWhereGeohubSourceTest.php`, nessuna regressione su `ImportTaxonomyWhereTest.php` (osmfeatures/osm2cai, non toccati da questo task).

- [ ] **Step 6: Commit**

```bash
git add src/Nova/Actions/ImportTaxonomyWhere.php src/Nova/Actions/Concerns/HasTaxonomyWhereImportHelpers.php tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSourceTest.php
git rm tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSelectionPanelTest.php
git commit -m "refactor(oc:8486): handleGeohub returns Action::modal instead of executing import"
```

---

## Task 2: Nuovo endpoint HTTP che esegue l'import selezionato

**Files:**
- Create: `src/Http/Controllers/Nova/GeohubWhereSelectionController.php`
- Modify: `src/WmPackageServiceProvider.php`
- Create: `tests/Feature/Nova/Actions/GeohubWhereSelectionControllerTest.php`

**Interfaces:**
- Consumes: `HasTaxonomyWhereImportHelpers::isGeohubSourceAllowed()`, `resolveGeohubApp()`, `executeGeohubImport()` (Task 1, nessuna modifica alla loro firma)
- Produces: `POST /nova-vendor/geohub-where-selection/import` — body `{app_id: int, selected_ids: array<string>}`, risposta `{message: string, created: int, updated: int}` (200) o `{message: string}` (403/422)

- [ ] **Step 1: Creare il controller**

Crea `src/Http/Controllers/Nova/GeohubWhereSelectionController.php`:

```php
<?php

declare(strict_types=1);

namespace Wm\WmPackage\Http\Controllers\Nova;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Nova\Actions\Concerns\HasTaxonomyWhereImportHelpers;

class GeohubWhereSelectionController extends Controller
{
    use HasTaxonomyWhereImportHelpers;

    public function import(Request $request): JsonResponse
    {
        // Boundary HTTP separato dall'Action Nova: il middleware `nova` non
        // porta autenticazione Nova (solo `nova.api_middleware`, usato da
        // `nova-api/*`, la porta) — questo è l'UNICO controllo di
        // autorizzazione su questa route, non un controllo aggiuntivo sopra
        // un'autenticazione già garantita altrove. Vedi overview.md, sezione
        // Follow-up 2, "Attenzione al boundary di autenticazione".
        if (! $this->isGeohubSourceAllowed($request->user())) {
            return response()->json(['message' => 'Sorgente GeoHub riservata ai super-admin.'], 403);
        }

        $app = App::find($request->input('app_id'));
        if (! $app) {
            return response()->json(['message' => 'App non trovata.'], 422);
        }

        $geohubApp = $this->resolveGeohubApp($app);
        if (is_string($geohubApp)) {
            return response()->json(['message' => $geohubApp], 422);
        }

        $selectedIds = (array) $request->input('selected_ids', []);

        $result = $this->executeGeohubImport($app, $geohubApp, $selectedIds);

        if ($result === null) {
            return response()->json(['message' => 'Nessuna where selezionata.'], 422);
        }

        return response()->json([
            'message' => "Creati {$result['created']} record, aggiornati {$result['updated']} record TaxonomyWhere da GeoHub. Geometrie in copia in background; la sincronizzazione delle track partira' automaticamente al termine.",
            'created' => $result['created'],
            'updated' => $result['updated'],
        ]);
    }
}
```

- [ ] **Step 2: Registrare la route**

In `src/WmPackageServiceProvider.php`, aggiungi l'import in testa al file:

```php
use Wm\WmPackage\Http\Controllers\Nova\GeohubWhereSelectionController;
```

Poi, subito dopo il blocco esistente che registra `nova-vendor/layer-analytics` (dentro lo stesso `$this->app->call(function () use ($packageDirPath) { ... })`), aggiungi:

```php
            Route::middleware(['nova'])
                ->prefix('nova-vendor/geohub-where-selection')
                ->group(function () {
                    Route::post('/import', [GeohubWhereSelectionController::class, 'import']);
                });
```

- [ ] **Step 3: Test dell'endpoint**

Crea `tests/Feature/Nova/Actions/GeohubWhereSelectionControllerTest.php`:

```php
<?php

namespace Wm\WmPackage\Tests\Feature\Nova\Actions;

require_once __DIR__.'/../../../Concerns/SharesGeohubConnectionWithLocal.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;
use Wm\WmPackage\Jobs\TaxonomyWhere\CopyTaxonomyWhereGeometryFromGeohubJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Tests\Concerns\SharesGeohubConnectionWithLocal;

class GeohubWhereSelectionControllerTest extends TestCase
{
    use DatabaseTransactions, SharesGeohubConnectionWithLocal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shareGeohubConnectionWithLocal();

        if (! Schema::hasColumn('taxonomy_wheres', 'admin_level')) {
            Schema::table('taxonomy_wheres', function (Blueprint $table) {
                $table->integer('admin_level')->nullable();
            });
        }
        if (! Schema::hasColumn('taxonomy_wheres', 'source')) {
            Schema::table('taxonomy_wheres', function (Blueprint $table) {
                $table->text('source')->nullable();
            });
        }

        config(['wm-package.super_admin_emails' => ['super@webmapp.it']]);
    }

    private function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create(['email' => 'super@webmapp.it']);
        $this->actingAs($user);

        return $user;
    }

    private function insertGeohubWhereWithoutIdentifier(string $name, ?int $adminLevel = null): int
    {
        return DB::connection('geohub')->table('taxonomy_wheres')->insertGetId([
            'name' => json_encode(['it' => $name, 'en' => $name]),
            'identifier' => null,
            'admin_level' => $adminLevel,
            'source' => 'osm',
            'properties' => json_encode([]),
            'geometry' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeAppWithGeohubLayer(): array
    {
        $geohubOwner = User::factory()->create();
        $geohubAppRow = App::factory()->create(['user_id' => $geohubOwner->id]);
        $app = App::factory()->create(['properties' => ['geohub_id' => $geohubAppRow->id]]);

        DB::connection('geohub')->table('layers')->insert([
            'app_id' => $geohubAppRow->id, 'name' => 'Layer test',
            'properties' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $geohubLayerId = DB::connection('geohub')->table('layers')->where('app_id', $geohubAppRow->id)->value('id');

        return [$app, $geohubLayerId];
    }

    private function linkWhereToLayer(int $whereId, int $layerId): void
    {
        DB::connection('geohub')->table('taxonomy_whereables')->insert([
            'taxonomy_where_id' => $whereId, 'taxonomy_whereable_id' => $layerId,
            'taxonomy_whereable_type' => 'App\\Models\\Layer', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_import_returns_403_for_non_super_admin(): void
    {
        $user = User::factory()->create(['email' => 'nobody@example.com']);
        $this->actingAs($user);

        $response = $this->postJson('/nova-vendor/geohub-where-selection/import', [
            'app_id' => 999, 'selected_ids' => ['1'],
        ]);

        $response->assertStatus(403);
    }

    public function test_import_returns_422_when_app_not_found(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->postJson('/nova-vendor/geohub-where-selection/import', [
            'app_id' => 999999, 'selected_ids' => ['1'],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('App non trovata', $response->json('message'));
    }

    public function test_import_returns_422_when_app_not_linked_to_geohub(): void
    {
        $this->actingAsSuperAdmin();
        $app = App::factory()->create(['properties' => []]);

        $response = $this->postJson('/nova-vendor/geohub-where-selection/import', [
            'app_id' => $app->id, 'selected_ids' => ['1'],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('geohub_id assente', $response->json('message'));
    }

    public function test_import_returns_422_when_selection_is_empty(): void
    {
        $this->actingAsSuperAdmin();
        [$app, $geohubLayerId] = $this->makeAppWithGeohubLayer();
        $corsicaId = $this->insertGeohubWhereWithoutIdentifier('Corsica');
        $this->linkWhereToLayer($corsicaId, $geohubLayerId);

        $countBefore = TaxonomyWhere::count();

        $response = $this->postJson('/nova-vendor/geohub-where-selection/import', [
            'app_id' => $app->id, 'selected_ids' => [],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Nessuna where selezionata', $response->json('message'));
        $this->assertSame($countBefore, TaxonomyWhere::count());
    }

    public function test_import_ignores_ids_outside_candidate_set(): void
    {
        Bus::fake();
        $this->actingAsSuperAdmin();
        [$app, $geohubLayerId] = $this->makeAppWithGeohubLayer();
        $corsicaId = $this->insertGeohubWhereWithoutIdentifier('Corsica');
        $this->linkWhereToLayer($corsicaId, $geohubLayerId);

        // 999999999 non è nel set candidato per questa App (nessun link a
        // taxonomy_whereables) — un payload alterato/non aggiornato non deve
        // produrre un import fuori scope, deve semplicemente essere ignorato.
        $response = $this->postJson('/nova-vendor/geohub-where-selection/import', [
            'app_id' => $app->id, 'selected_ids' => [(string) $corsicaId, '999999999'],
        ]);

        $response->assertOk();
        $this->assertSame(1, $response->json('created'));
        $imported = TaxonomyWhere::whereRaw("properties->>'geohub_id' = ?", ['999999999'])->first();
        $this->assertNull($imported);
    }

    public function test_import_creates_and_updates_selected_wheres_only(): void
    {
        Bus::fake();
        $this->actingAsSuperAdmin();
        [$app, $geohubLayerId] = $this->makeAppWithGeohubLayer();
        $corsicaId = $this->insertGeohubWhereWithoutIdentifier('Corsica');
        $franciaId = $this->insertGeohubWhereWithoutIdentifier('Francia');
        $this->linkWhereToLayer($corsicaId, $geohubLayerId);
        $this->linkWhereToLayer($franciaId, $geohubLayerId);

        // Solo corsica selezionata: francia è candidata ma non selezionata,
        // non deve essere importata.
        $response = $this->postJson('/nova-vendor/geohub-where-selection/import', [
            'app_id' => $app->id, 'selected_ids' => [(string) $corsicaId],
        ]);

        $response->assertOk();
        $this->assertSame(1, $response->json('created'));
        $this->assertSame(0, $response->json('updated'));

        $imported = TaxonomyWhere::whereRaw("properties->>'geohub_id' = ?", [(string) $corsicaId])->first();
        $this->assertNotNull($imported);
        $this->assertSame('Corsica', $imported->getTranslation('name', 'it'));
        $notImported = TaxonomyWhere::whereRaw("properties->>'geohub_id' = ?", [(string) $franciaId])->first();
        $this->assertNull($notImported);

        Bus::assertBatched(function ($batch) use ($imported) {
            return $batch->jobs->contains(
                fn ($job) => $job instanceof CopyTaxonomyWhereGeometryFromGeohubJob
                    && $job->taxonomyWhereId === $imported->id
            );
        });
    }

    public function test_import_is_idempotent_on_reimport(): void
    {
        Bus::fake();
        $this->actingAsSuperAdmin();
        [$app, $geohubLayerId] = $this->makeAppWithGeohubLayer();

        $identifier = 'test-'.Str::lower(Str::random(8));
        $corsicaId = DB::connection('geohub')->table('taxonomy_wheres')->insertGetId([
            'name' => json_encode(['it' => 'Corsica', 'en' => 'Corsica']),
            'identifier' => $identifier, 'admin_level' => null, 'source' => 'osm',
            'properties' => json_encode([]), 'geometry' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->linkWhereToLayer($corsicaId, $geohubLayerId);

        $payload = ['app_id' => $app->id, 'selected_ids' => [(string) $corsicaId]];

        $this->postJson('/nova-vendor/geohub-where-selection/import', $payload);
        $response = $this->postJson('/nova-vendor/geohub-where-selection/import', $payload);

        $response->assertOk();
        $this->assertSame(0, $response->json('created'));
        $this->assertSame(1, $response->json('updated'));
        $this->assertSame(1, TaxonomyWhere::whereRaw("properties->>'geohub_id' = ?", [(string) $corsicaId])->count());
    }
}
```

- [ ] **Step 4: Eseguire la suite**

Run: `docker exec php-maphub vendor/bin/pest wm-package/tests/Feature/Nova/Actions/GeohubWhereSelectionControllerTest.php`
Expected: 7/7 PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Http/Controllers/Nova/GeohubWhereSelectionController.php src/WmPackageServiceProvider.php tests/Feature/Nova/Actions/GeohubWhereSelectionControllerTest.php
git commit -m "feat(oc:8486): add geohub where selection import endpoint"
```

---

## Task 3: Componente Vue della seconda modale + verifica live

**Files:**
- Create: `resources/js/geohub-where-selection.js`
- Modify: `src/WmPackageServiceProvider.php`

**Interfaces:**
- Consumes: payload di `Action::modal()` da Task 1 (`{app_id: int, rows: array<{id, label, checked}>}`), endpoint di Task 2 (`POST /nova-vendor/geohub-where-selection/import`)
- Produces: componente Vue registrato come `'geohub-where-selection-modal'` (deve combaciare con `ImportTaxonomyWhere::GEOHUB_WHERE_SELECTION_MODAL_COMPONENT`)

- [ ] **Step 1: Creare il componente**

Crea `resources/js/geohub-where-selection.js`:

```js
/**
 * Componente Vue per la seconda modale del redesign a due modali di
 * ImportTaxonomyWhere (sorgente geohub) — vedi overview.md, sezione
 * "Follow-up 2: due modali separati per la selezione". Registrato con una
 * render function (non un file .vue compilato): nessun bundle da costruire
 * per un componente cosi' semplice — stesso pattern gia' usato per
 * trail-registry-notice-card (resources/js/domains/trail_registry.js).
 *
 * Il nome del componente DEVE combaciare esattamente con
 * ImportTaxonomyWhere::GEOHUB_WHERE_SELECTION_MODAL_COMPONENT (PHP).
 *
 * Layout/backdrop in `style` inline, non classi Tailwind arbitrarie: oc:7546
 * ha gia' verificato che classi non usate altrove da Nova (es. bg-opacity-50,
 * px-5, gap-3) vengono eliminate dal purge del CSS compilato e non hanno
 * alcun effetto. Le uniche classi Tailwind qui sotto (nei bottoni) sono
 * copiate 1:1 da src/Nova/Fields/_shared/resources/js/components/Button.vue,
 * gia' verificate presenti nel CSS di Nova.
 */
Nova.booting((app) => {
    const h = Vue.h

    const BUTTON_BASE = 'border text-left appearance-none cursor-pointer rounded text-sm font-bold inline-flex items-center justify-center h-9 px-3'
    const BUTTON_VARIANTS = {
        primary: 'shadow bg-primary-500 border-primary-500 text-white hover:bg-primary-400 hover:border-primary-400',
        secondary: 'bg-transparent border-transparent text-gray-600 hover:bg-gray-100',
    }

    const button = (label, variant, onClick, disabled) => h('button', {
        type: 'button',
        class: `${BUTTON_BASE} ${BUTTON_VARIANTS[variant] || BUTTON_VARIANTS.primary}`,
        style: disabled ? 'opacity:0.5;pointer-events:none' : '',
        disabled: !!disabled,
        onClick,
    }, label)

    app.component('geohub-where-selection-modal', {
        props: {
            data: { type: Object, required: true },
        },

        emits: ['confirm', 'close'],

        data() {
            return {
                rows: (this.data.rows || []).map(row => ({ ...row })),
                loading: false,
                result: null,
                error: null,
            }
        },

        computed: {
            allSelected() {
                return this.rows.length > 0 && this.rows.every(row => row.checked)
            },
            selectedCount() {
                return this.rows.filter(row => row.checked).length
            },
        },

        methods: {
            toggleAll() {
                const target = !this.allSelected
                this.rows = this.rows.map(row => ({ ...row, checked: target }))
            },

            toggleRow(id) {
                this.rows = this.rows.map(row => (row.id === id ? { ...row, checked: !row.checked } : row))
            },

            async submit() {
                if (this.loading || this.selectedCount === 0) {
                    return
                }

                this.loading = true
                this.error = null

                try {
                    const response = await Nova.request().post('/nova-vendor/geohub-where-selection/import', {
                        app_id: this.data.app_id,
                        selected_ids: this.rows.filter(row => row.checked).map(row => row.id),
                    })
                    this.result = response.data
                } catch (e) {
                    this.error = (e.response && e.response.data && e.response.data.message) || "Errore imprevisto durante l'import."
                } finally {
                    this.loading = false
                }
            },

            close() {
                this.$emit('close')
            },
        },

        render() {
            const overlay = h('div', {
                style: 'position:absolute;inset:0;background-color:rgba(0,0,0,0.5)',
                onClick: this.close,
            })

            let body
            if (this.loading) {
                body = h('div', { style: 'padding:32px 0;text-align:center' }, 'Import in corso...')
            } else if (this.result) {
                body = h('div', { style: 'padding:16px 0' }, this.result.message)
            } else if (this.error) {
                body = h('div', { style: 'padding:16px 0;color:#ef4444' }, this.error)
            } else {
                body = h(
                    'div',
                    { style: 'max-height:360px;overflow-y:auto;padding:8px 0' },
                    this.rows.map(row => h('label', {
                        key: row.id,
                        style: 'display:flex;align-items:center;gap:8px;padding:6px 0;cursor:pointer',
                    }, [
                        h('input', {
                            type: 'checkbox',
                            checked: row.checked,
                            onChange: () => this.toggleRow(row.id),
                        }),
                        h('span', {}, row.label),
                    ]))
                )
            }

            const footer = (this.result || this.error)
                ? h('div', { style: 'display:flex;justify-content:flex-end;margin-top:16px' }, [
                    button('Chiudi', 'primary', this.close),
                ])
                : h('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-top:16px' }, [
                    button(this.allSelected ? 'Deseleziona tutte' : 'Seleziona tutte', 'secondary', this.toggleAll),
                    h('div', { style: 'display:flex;gap:8px' }, [
                        button('Annulla', 'secondary', this.close),
                        button(`Importa (${this.selectedCount})`, 'primary', this.submit, this.loading || this.selectedCount === 0),
                    ]),
                ])

            const panel = h('div', {
                style: 'position:relative;background:white;border-radius:8px;box-shadow:0 10px 25px rgba(0,0,0,0.35);width:100%;max-width:32rem;padding:24px;max-height:90vh;overflow-y:auto',
                class: 'dark:bg-gray-800 dark:text-white',
            }, [
                h('h3', { style: 'font-size:1.25rem;font-weight:400;margin-bottom:8px' }, 'Territori da importare'),
                body,
                footer,
            ])

            const wrapper = h('div', {
                style: 'position:fixed;inset:0;z-index:50;display:flex;align-items:center;justify-content:center;padding:16px',
            }, [overlay, panel])

            // Teleport verso <body>, stesso motivo gia' documentato per
            // oc:7546 (containing block alterato da transform in catena nei
            // pannelli Nova). DA VERIFICARE dal vivo (Step 2): se
            // `Vue.Teleport` non risultasse disponibile come globale, o se la
            // modale risultasse comunque correttamente posizionata senza,
            // rimuovi il wrapping e ritorna `wrapper` direttamente.
            return h(Vue.Teleport, { to: 'body' }, [wrapper])
        },
    })
})
```

- [ ] **Step 2: Registrare lo script**

In `src/WmPackageServiceProvider.php`, dentro `Nova::serving(function () { ... })`, subito dopo la riga `Nova::script('wm-nova-overrides', __DIR__.'/../resources/js/nova.js');`, aggiungi:

```php
            Nova::script('wm-geohub-where-selection', __DIR__.'/../resources/js/geohub-where-selection.js');
```

- [ ] **Step 3: Verifica live in browser (OBBLIGATORIA, non sostituibile da sola lettura di codice)**

Pattern net-new nel package (`Action::modal()` mai usato altrove) — richiede conferma visiva concreta, non solo lettura statica del sorgente vendor, prima di considerare il task chiuso (stesso standard già applicato al Task 2 del follow-up precedente). Se l'implementer non ha accesso a un browser interattivo (stesso limite già riscontrato nel ciclo precedente), segnala esplicitamente questo passo come non eseguito nel report — la verifica va poi eseguita dal controller della pipeline, che ha accesso agli strumenti di automazione browser.

Passi da verificare dal vivo, con un'App reale collegata a GeoHub e almeno una where candidata:
1. Selezionando Sorgente=GeoHub + App e premendo "Run Action", la **prima modale si chiude** e si apre una **seconda modale** (non stackata) con la lista di checkbox, tutte pre-selezionate, etichette corrette (incluso "già importata" dove applicabile)
2. Il bottone "Seleziona tutte"/"Deseleziona tutte" funziona e il suo testo si aggiorna in base allo stato corrente
3. Deselezionando una checkbox e premendo "Importa (N)", il bottone si disabilita, appare lo spinner, poi il risultato (messaggio con contatori) sostituisce la lista **nella stessa modale**
4. Il record deselezionato **non** viene creato/aggiornato nel DB (verifica diretta, non solo il messaggio)
5. Il posizionamento della modale è corretto (non clippata/disallineata) — se necessario, applica l'aggiustamento su `Vue.Teleport` annotato nello Step 1

Documenta l'esito (incluse eventuali correzioni al componente) in `notes.md` (Task 4).

- [ ] **Step 4: Commit**

```bash
git add resources/js/geohub-where-selection.js src/WmPackageServiceProvider.php
git commit -m "feat(oc:8486): add geohub where selection second-modal Vue component"
```

---

## Task 4: Regressione finale e `notes.md`

**Files:**
- Modify: `docs/features/8486-import-taxonomywhere-da-geohub-per-una-data-app/notes.md`

**Interfaces:**
- Consumes: nessuna nuova — verifica soltanto che tutto il lavoro dei Task 1-3 sia coerente insieme
- Produces: nessuna

- [ ] **Step 1: Rieseguire l'intera suite rilevante**

Run: `docker exec php-maphub vendor/bin/pest wm-package/tests/Feature/Nova/Actions/ImportTaxonomyWhereTest.php wm-package/tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSourceTest.php wm-package/tests/Feature/Nova/Actions/GeohubWhereSelectionControllerTest.php wm-package/tests/Feature/Jobs/CopyTaxonomyWhereGeometryFromGeohubJobTest.php wm-package/tests/Feature/Jobs/SyncTaxonomyWhereTracksJobTest.php wm-package/tests/Unit/Models/TaxonomyWhereGeohubSourceTest.php`

Expected: tutti PASS, nessuna regressione sui due handler esistenti (osmfeatures/osm2cai) né sui job di geometria/sync (invariati da questo ciclo).

- [ ] **Step 2: Verificare che non resti alcun riferimento al vecchio meccanismo**

```bash
grep -rn "buildGeohubWhereOptions\|geohub_where_ids\|BooleanGroup" src/Nova/Actions/ImportTaxonomyWhere.php
```

Expected: nessun risultato (il campo, il metodo e la chiave di payload del ciclo precedente sono stati completamente rimossi nel Task 1).

- [ ] **Step 3: Aggiornare `notes.md`**

Aggiungi in fondo al file esistente `docs/features/8486-import-taxonomywhere-da-geohub-per-una-data-app/notes.md`:

```markdown

## Follow-up 2: due modali per la selezione

### Decisioni
- Sostituito il campo `BooleanGroup`/`dependsOn` (follow-up precedente) con `Action::modal()`: prima modale invariata (Sorgente+App), seconda modale component-driven con la checkbox list — motivato da un bug UX reale (click-through abituale che eseguiva l'import prima che l'admin potesse deselezionare)
- `Action::modal($nome, $payload)` va chiamato con ESATTAMENTE 2 argomenti — con 3 ritorna un'istanza Action no-op invece di un ActionResponse (verificato in vendor/laravel/nova/src/Actions/Action.php:453)
- Componente Vue registrato con render function + Nova.booting() (nessuna build dedicata), non una replica della toolchain ag-grid/TypeScript/webpack di LayerFeatures — sproporzionata per una checkbox list
- Nuovo endpoint HTTP dedicato (non un'esecuzione automatica di Nova alla conferma della seconda modale, che non esiste) — ri-verifica autonomamente il gate super-admin ed è l'unico controllo di autorizzazione sul boundary (il middleware `nova` non porta autenticazione Nova)
- L'endpoint ri-deriva il set autoritativo delle where candidate e vi interseca `selected_ids` ricevuti dal client, invece di fidarsi ciecamente del payload — un id fuori dal set candidato viene ignorato, non causa un errore

### Verifica live (Task 3, Step 3)
[Da compilare durante l'esecuzione con l'esito effettivo di ciascuno dei 5 punti verificati, incluso l'esito della verifica su Vue.Teleport]

### Deviazioni dal piano
[Da compilare durante l'esecuzione se emergono deviazioni]
```

Compila le sezioni "Verifica live" e "Deviazioni dal piano" con quanto effettivamente accaduto durante l'esecuzione.

- [ ] **Step 4: Commit**

```bash
git add docs/features/8486-import-taxonomywhere-da-geohub-per-una-data-app/notes.md
git commit -m "docs(oc:8486): document two-modal geohub where selection redesign"
```

---

## Self-Review (compilata durante la stesura di questo piano)

**Spec coverage:** ogni requisito della sezione "Follow-up 2" di `overview.md` è coperto — prima modale invariata + `Action::modal()` con arità corretta (Task 1), payload pre-costruito lato server (Task 1), rimozione `BooleanGroup`/`buildGeohubWhereOptions()` (Task 1), nuova cartella/endpoint dedicato con gate ri-verificato indipendentemente (Task 2), contratto esplicito di `executeGeohubImport()` incluso il ri-derivare il set candidato invece di fidarsi del client (Task 1/2), toggle seleziona/deseleziona tutte (Task 3), risultato inline nella stessa modale (Task 3), verifica live obbligatoria come step esplicito non solo come rischio in prosa (Task 3), test riscritti sul nuovo endpoint invece che su `handleGeohub()` diretto (Task 1 rimuove, Task 2 aggiunge). La cartella dedicata "mirror di LayerFeatures" richiesta durante la reverse-interaction è stata interpretata come "posizione/organizzazione dedicata", non come replica letterale della sua toolchain di build — scelta motivata esplicitamente in Architecture (riduce rischio e complessità per un componente molto più semplice), non una deviazione silenziosa.

**Placeholder scan:** nessun placeholder — l'unica area di incertezza dichiarata esplicitamente (`Vue.Teleport` disponibile come globale, necessità reale del Teleport in questo punto dell'albero DOM) è accompagnata da un'istruzione concreta su cosa fare in entrambi i casi, verificata dal vivo nel Task 3 Step 3, non lasciata vaga.

**Type consistency:** `buildGeohubWhereSelectionPayload(App $app, object $geohubApp): array` ed `executeGeohubImport(App $app, object $geohubApp, array $selectedIds): ?array` usati con la stessa identica firma tra Task 1 (definizione nel trait) e Task 1/2 (uso in `handleGeohub()` e nel controller). Nome del componente (`GEOHUB_WHERE_SELECTION_MODAL_COMPONENT` / `'geohub-where-selection-modal'`) identico tra Task 1 (PHP) e Task 3 (JS). Struttura delle righe payload (`{id: string, label: string, checked: bool}`) coerente tra il metodo che le produce (Task 1) e sia i test (Task 1) sia il componente Vue che le consuma (Task 3).
