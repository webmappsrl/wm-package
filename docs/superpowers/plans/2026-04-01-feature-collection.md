# FeatureCollection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Introdurre il modello `FeatureCollection` che sostituisce `OverlayLayer` del vecchio sistema Geohub, supportando tre modalità (generated, upload, external), rigenerazione asincrona via job, e serializzazione in `MAP.controls.overlays` nel config app.

**Architecture:** `FeatureCollection` appartiene a un'App e può avere molti Layer associati (per mode=generated). Il job `GenerateFeatureCollectionJob` aggrega le TaxonomyWhere dei layer associati in un GeoJSON FeatureCollection scritto in S3/MinIO. L'`AppConfigService` serializza le FeatureCollection abilitate nel config app seguendo il pattern già commentato nel codice.

**Tech Stack:** Laravel 12, PHP 8.4, PostgreSQL, Nova 5, S3/MinIO (disk `wmfe`), Horizon/Redis queue, Pest tests.

---

## File Map

### Nuovi file — wm-package
- `database/migrations/XXXX_create_feature_collections_table.php` — tabella feature_collections
- `database/migrations/XXXX_create_feature_collection_layer_table.php` — pivot
- `src/Models/FeatureCollection.php` — modello Eloquent
- `src/Services/Models/FeatureCollectionService.php` — logica generazione GeoJSON
- `src/Jobs/FeatureCollection/GenerateFeatureCollectionJob.php` — job asincrono
- `src/Nova/FeatureCollection.php` — risorsa Nova
- `tests/Feature/FeatureCollectionServiceTest.php` — test generazione
- `tests/Feature/AppConfigServiceOverlaysTest.php` — test serializzazione config

### File modificati — wm-package
- `src/Models/App.php` — aggiungere `featureCollections()`, `config_overlays` cast, `overlays_label` translatable
- `src/Models/Layer.php` — aggiungere `featureCollections()` BelongsToMany
- `src/Observers/LayerObserver.php` (nuovo o esistente) — trigger rigenerazione su taxonomy_where change / delete
- `src/Services/StorageService.php` — aggiungere `storeFeatureCollection()`
- `src/Services/Models/App/AppConfigService.php` — decommentare e adattare sezione overlays
- `src/Nova/App.php` — aggiungere tab "Overlays"
- `src/Nova/Cards/ApiLinksCard/src/LayerApiLinksCard.php` — aggiungere link FeatureCollection
- `src/WmPackageServiceProvider.php` — registrare observer Layer se non esiste

### Nuovi file — forestas (app)
- `app/Nova/FeatureCollection.php` — wrapper thin

---

## Task 1: Migration — tabella `feature_collections`

**Files:**
- Create: `wm-package/database/migrations/2026_04_01_000001_create_feature_collections_table.php`

- [ ] **Step 1: Crea la migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->string('name');
            $table->jsonb('label')->nullable();
            $table->boolean('enabled')->default(false);
            $table->enum('mode', ['generated', 'upload', 'external'])->default('generated');
            $table->string('external_url')->nullable();
            $table->string('file_path')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->boolean('default')->default(false);
            $table->boolean('clickable')->default(true);
            $table->string('fill_color')->nullable();
            $table->string('stroke_color')->nullable();
            $table->float('stroke_width')->nullable();
            $table->text('icon')->nullable();
            $table->jsonb('configuration')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_collections');
    }
};
```

- [ ] **Step 2: Pubblica ed esegui la migration**

```bash
docker exec -it php-${APP_NAME} php artisan vendor:publish --tag=wm-package-migrations
docker exec -it php-${APP_NAME} php artisan migrate
```

Expected: `Created Migration: 2026_04_01_000001_create_feature_collections_table` e migration eseguita senza errori.

- [ ] **Step 3: Commit**

```bash
git -C wm-package add database/migrations/2026_04_01_000001_create_feature_collections_table.php
git -C wm-package commit -m "feat: add feature_collections migration"
```

---

## Task 2: Migration — pivot `feature_collection_layer`

**Files:**
- Create: `wm-package/database/migrations/2026_04_01_000002_create_feature_collection_layer_table.php`

- [ ] **Step 1: Crea la migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_collection_layer', function (Blueprint $table) {
            $table->foreignId('feature_collection_id')->constrained('feature_collections')->cascadeOnDelete();
            $table->foreignId('layer_id')->constrained('layers')->cascadeOnDelete();
            $table->primary(['feature_collection_id', 'layer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_collection_layer');
    }
};
```

- [ ] **Step 2: Pubblica ed esegui**

```bash
docker exec -it php-${APP_NAME} php artisan vendor:publish --tag=wm-package-migrations
docker exec -it php-${APP_NAME} php artisan migrate
```

Expected: migration eseguita senza errori.

- [ ] **Step 3: Commit**

```bash
git -C wm-package add database/migrations/2026_04_01_000002_create_feature_collection_layer_table.php
git -C wm-package commit -m "feat: add feature_collection_layer pivot migration"
```

---

## Task 3: Aggiungere `config_overlays` e `overlays_label` all'App

**Files:**
- Create: `wm-package/database/migrations/2026_04_01_000003_add_overlays_fields_to_apps_table.php`
- Modify: `wm-package/src/Models/App.php`

- [ ] **Step 1: Crea la migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->jsonb('config_overlays')->nullable()->after('config_home');
            $table->jsonb('overlays_label')->nullable()->after('config_overlays');
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn(['config_overlays', 'overlays_label']);
        });
    }
};
```

- [ ] **Step 2: Esegui la migration**

```bash
docker exec -it php-${APP_NAME} php artisan vendor:publish --tag=wm-package-migrations
docker exec -it php-${APP_NAME} php artisan migrate
```

- [ ] **Step 3: Aggiorna App model**

Nel file `wm-package/src/Models/App.php`, aggiungi `config_overlays` e `overlays_label` ai `$fillable` e `$casts`, e aggiungi la relazione `featureCollections()`.

Cerca `'config_home'` in `$fillable` e aggiungi dopo:
```php
'config_overlays',
'overlays_label',
```

Cerca `'config_home'` nei `$casts` e aggiungi dopo:
```php
'config_overlays' => 'array',
'overlays_label' => 'array',
```

Aggiungi `overlays_label` all'array `$translatable` (cerca la proprietà `public array $translatable`):
```php
'overlays_label',
```

Aggiungi la relazione alla fine della sezione relazioni:
```php
public function featureCollections(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(\Wm\WmPackage\Models\FeatureCollection::class);
}
```

- [ ] **Step 4: Commit**

```bash
git -C wm-package add database/migrations/2026_04_01_000003_add_overlays_fields_to_apps_table.php src/Models/App.php
git -C wm-package commit -m "feat: add config_overlays and overlays_label to App"
```

---

## Task 4: Modello `FeatureCollection`

**Files:**
- Create: `wm-package/src/Models/FeatureCollection.php`
- Modify: `wm-package/src/Models/Layer.php`

- [ ] **Step 1: Scrivi il test**

Crea `wm-package/tests/Feature/FeatureCollectionModelTest.php`:

```php
<?php

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\FeatureCollection;
use Wm\WmPackage\Models\Layer;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('belongs to an app', function () {
    $app = App::factory()->createQuietly();
    $fc = FeatureCollection::factory()->createQuietly(['app_id' => $app->id]);

    expect($fc->app)->toBeInstanceOf(App::class);
    expect($fc->app->id)->toBe($app->id);
});

it('can have many layers', function () {
    $app = App::factory()->createQuietly();
    $fc = FeatureCollection::factory()->createQuietly(['app_id' => $app->id, 'mode' => 'generated']);
    $layer = Layer::factory()->createQuietly(['app_id' => $app->id]);

    $fc->layers()->attach($layer->id);

    expect($fc->layers)->toHaveCount(1);
    expect($fc->layers->first()->id)->toBe($layer->id);
});

it('enforces only one default per app', function () {
    $app = App::factory()->createQuietly();
    $fc1 = FeatureCollection::factory()->createQuietly(['app_id' => $app->id, 'default' => true]);
    $fc2 = FeatureCollection::factory()->createQuietly(['app_id' => $app->id, 'default' => false]);

    $fc2->default = true;
    $fc2->save();

    expect($fc1->fresh()->default)->toBeFalse();
    expect($fc2->fresh()->default)->toBeTrue();
});
```

- [ ] **Step 2: Esegui il test — verifica che fallisca**

```bash
docker exec -it php-${APP_NAME} vendor/bin/pest tests/Feature/FeatureCollectionModelTest.php
```

Expected: FAIL — class `FeatureCollection` not found.

- [ ] **Step 3: Crea il modello**

Crea `wm-package/src/Models/FeatureCollection.php`:

```php
<?php

namespace Wm\WmPackage\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class FeatureCollection extends Model
{
    use HasFactory;

    protected $fillable = [
        'app_id',
        'name',
        'label',
        'enabled',
        'mode',
        'external_url',
        'file_path',
        'generated_at',
        'default',
        'clickable',
        'fill_color',
        'stroke_color',
        'stroke_width',
        'icon',
        'configuration',
    ];

    protected $casts = [
        'label' => 'array',
        'enabled' => 'boolean',
        'default' => 'boolean',
        'clickable' => 'boolean',
        'configuration' => 'array',
        'generated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $model) {
            if ($model->default) {
                static::where('app_id', $model->app_id)
                    ->where('id', '!=', $model->id ?? 0)
                    ->update(['default' => false]);
            }
        });
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public function layers(): BelongsToMany
    {
        return $this->belongsToMany(Layer::class, 'feature_collection_layer');
    }

    public function getUrl(): ?string
    {
        if ($this->mode === 'external') {
            return $this->external_url;
        }

        if ($this->file_path) {
            return \Illuminate\Support\Facades\Storage::disk('wmfe')->url($this->file_path);
        }

        return null;
    }
}
```

- [ ] **Step 4: Crea il factory**

Crea `wm-package/database/factories/FeatureCollectionFactory.php`:

```php
<?php

namespace Wm\WmPackage\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\FeatureCollection;

class FeatureCollectionFactory extends Factory
{
    protected $model = FeatureCollection::class;

    public function definition(): array
    {
        return [
            'app_id' => App::factory(),
            'name' => $this->faker->words(3, true),
            'label' => ['it' => $this->faker->sentence(3), 'en' => $this->faker->sentence(3)],
            'enabled' => false,
            'mode' => 'generated',
            'default' => false,
            'clickable' => true,
        ];
    }
}
```

- [ ] **Step 5: Aggiungi `featureCollections()` al modello Layer**

In `wm-package/src/Models/Layer.php`, aggiungi la relazione:

```php
public function featureCollections(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
{
    return $this->belongsToMany(\Wm\WmPackage\Models\FeatureCollection::class, 'feature_collection_layer');
}
```

- [ ] **Step 6: Esegui i test**

```bash
docker exec -it php-${APP_NAME} vendor/bin/pest tests/Feature/FeatureCollectionModelTest.php
```

Expected: PASS (3 test).

- [ ] **Step 7: Commit**

```bash
git -C wm-package add src/Models/FeatureCollection.php database/factories/FeatureCollectionFactory.php src/Models/Layer.php tests/Feature/FeatureCollectionModelTest.php
git -C wm-package commit -m "feat: add FeatureCollection model with factory and Layer relation"
```

---

## Task 5: StorageService — metodo `storeFeatureCollection`

**Files:**
- Modify: `wm-package/src/Services/StorageService.php`

- [ ] **Step 1: Aggiungi il metodo a StorageService**

In `wm-package/src/Services/StorageService.php`, aggiungi dopo il metodo `storeLayerFeatureCollection`:

```php
public function storeFeatureCollection(int $appId, int $featureCollectionId, string $contents): string|false
{
    try {
        $path = $this->getShardBasePath($appId)."feature-collection/{$featureCollectionId}.geojson";

        $success = $this->getRemoteWfeDisk()->put($path, $contents);

        if ($success) {
            return $path;
        }

        return false;
    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::error('Failed to store feature collection: '.$e->getMessage());
        throw $e;
    }
}
```

Note: il metodo ritorna il **path** (non l'URL) così il modello può memorizzarlo in `file_path` e ricavare l'URL via `Storage::disk('wmfe')->url($path)`.

- [ ] **Step 2: Commit**

```bash
git -C wm-package add src/Services/StorageService.php
git -C wm-package commit -m "feat: add storeFeatureCollection to StorageService"
```

---

## Task 6: FeatureCollectionService — logica generazione GeoJSON

**Files:**
- Create: `wm-package/src/Services/Models/FeatureCollectionService.php`
- Create: `wm-package/tests/Feature/FeatureCollectionServiceTest.php`

- [ ] **Step 1: Scrivi il test**

Crea `wm-package/tests/Feature/FeatureCollectionServiceTest.php`:

```php
<?php

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\FeatureCollection;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Models\FeatureCollectionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

it('generates a valid geojson feature collection from layers taxonomy wheres', function () {
    $app = App::factory()->createQuietly();
    $layer = Layer::factory()->createQuietly(['app_id' => $app->id]);

    // Inserisce un TaxonomyWhere con geometria
    $whereId = DB::table('taxonomy_wheres')->insertGetId([
        'name' => json_encode(['it' => 'Test Where']),
        'geometry' => DB::raw("ST_GeomFromText('POLYGON((0 0, 1 0, 1 1, 0 1, 0 0))', 4326)"),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('taxonomy_whereables')->insert([
        'taxonomy_where_id' => $whereId,
        'taxonomy_whereable_type' => Layer::class,
        'taxonomy_whereable_id' => $layer->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $fc = FeatureCollection::factory()->createQuietly([
        'app_id' => $app->id,
        'mode' => 'generated',
        'clickable' => true,
    ]);
    $fc->layers()->attach($layer->id);

    $service = app(FeatureCollectionService::class);
    $geojson = $service->generate($fc->fresh());

    expect($geojson)->toBeArray();
    expect($geojson['type'])->toBe('FeatureCollection');
    expect($geojson['features'])->toHaveCount(1);
    expect($geojson['features'][0]['properties']['layer_id'])->toBe($layer->id);
    expect($geojson['features'][0]['properties']['clickable'])->toBeTrue();
});

it('returns empty feature collection when no layers have taxonomy wheres', function () {
    $app = App::factory()->createQuietly();
    $layer = Layer::factory()->createQuietly(['app_id' => $app->id]);

    $fc = FeatureCollection::factory()->createQuietly([
        'app_id' => $app->id,
        'mode' => 'generated',
    ]);
    $fc->layers()->attach($layer->id);

    $service = app(FeatureCollectionService::class);
    $geojson = $service->generate($fc->fresh());

    expect($geojson['type'])->toBe('FeatureCollection');
    expect($geojson['features'])->toHaveCount(0);
});
```

- [ ] **Step 2: Esegui il test — verifica che fallisca**

```bash
docker exec -it php-${APP_NAME} vendor/bin/pest tests/Feature/FeatureCollectionServiceTest.php
```

Expected: FAIL — class `FeatureCollectionService` not found.

- [ ] **Step 3: Crea FeatureCollectionService**

Crea `wm-package/src/Services/Models/FeatureCollectionService.php`:

```php
<?php

namespace Wm\WmPackage\Services\Models;

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\FeatureCollection;
use Wm\WmPackage\Services\StorageService;

class FeatureCollectionService
{
    public function generate(FeatureCollection $fc): array
    {
        $features = [];

        foreach ($fc->layers as $layer) {
            $wheres = $layer->taxonomyWheres;

            foreach ($wheres as $where) {
                $geometry = DB::selectOne(
                    'SELECT ST_AsGeoJSON(geometry) as geojson FROM taxonomy_wheres WHERE id = ?',
                    [$where->id]
                );

                if (! $geometry || ! $geometry->geojson) {
                    continue;
                }

                $features[] = [
                    'type' => 'Feature',
                    'geometry' => json_decode($geometry->geojson, true),
                    'properties' => [
                        'layer_id' => $layer->id,
                        'clickable' => $fc->clickable,
                    ],
                ];
            }
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
        ];
    }

    public function generateAndStore(FeatureCollection $fc): bool
    {
        $geojson = $this->generate($fc);
        $contents = json_encode($geojson);

        $path = StorageService::make()->storeFeatureCollection(
            $fc->app_id,
            $fc->id,
            $contents
        );

        if ($path === false) {
            return false;
        }

        $fc->update([
            'file_path' => $path,
            'generated_at' => now(),
        ]);

        return true;
    }
}
```

- [ ] **Step 4: Esegui i test**

```bash
docker exec -it php-${APP_NAME} vendor/bin/pest tests/Feature/FeatureCollectionServiceTest.php
```

Expected: PASS (2 test).

- [ ] **Step 5: Commit**

```bash
git -C wm-package add src/Services/Models/FeatureCollectionService.php tests/Feature/FeatureCollectionServiceTest.php
git -C wm-package commit -m "feat: add FeatureCollectionService with generate and generateAndStore"
```

---

## Task 7: Job `GenerateFeatureCollectionJob`

**Files:**
- Create: `wm-package/src/Jobs/FeatureCollection/GenerateFeatureCollectionJob.php`

- [ ] **Step 1: Crea il job**

Crea `wm-package/src/Jobs/FeatureCollection/GenerateFeatureCollectionJob.php`:

```php
<?php

namespace Wm\WmPackage\Jobs\FeatureCollection;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Wm\WmPackage\Models\FeatureCollection;
use Wm\WmPackage\Services\Models\FeatureCollectionService;

class GenerateFeatureCollectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(protected int $featureCollectionId) {}

    public function handle(FeatureCollectionService $service): void
    {
        $fc = FeatureCollection::find($this->featureCollectionId);

        if (! $fc || $fc->mode !== 'generated') {
            return;
        }

        $service->generateAndStore($fc);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git -C wm-package add src/Jobs/FeatureCollection/GenerateFeatureCollectionJob.php
git -C wm-package commit -m "feat: add GenerateFeatureCollectionJob"
```

---

## Task 8: Observer su Layer — trigger rigenerazione

**Files:**
- Modify: `wm-package/src/Observers/LayerableObserver.php` (oppure crea `src/Observers/LayerFeatureCollectionObserver.php`)
- Modify: `wm-package/src/WmPackageServiceProvider.php`

- [ ] **Step 1: Leggi LayerableObserver**

Apri `wm-package/src/Observers/LayerableObserver.php` e verifica se già gestisce eventi su TaxonomyWhere. Se no, crea un observer dedicato.

- [ ] **Step 2: Crea `TaxonomyWhereableObserver` per Layer**

Crea `wm-package/src/Observers/LayerTaxonomyWhereObserver.php`:

```php
<?php

namespace Wm\WmPackage\Observers;

use Wm\WmPackage\Jobs\FeatureCollection\GenerateFeatureCollectionJob;
use Wm\WmPackage\Models\Layer;

class LayerTaxonomyWhereObserver
{
    public $afterCommit = true;

    public function dispatchForLayer(Layer $layer): void
    {
        $layer->featureCollections()
            ->where('mode', 'generated')
            ->where('enabled', true)
            ->each(function ($fc) {
                GenerateFeatureCollectionJob::dispatch($fc->id);
            });
    }
}
```

- [ ] **Step 3: Aggiungi listener sulle pivot taxonomy_whereables**

In `wm-package/src/Models/Layer.php`, nella definizione della relazione `taxonomyWheres()`, aggiungi un observer tramite evento `pivotAttached` / `pivotDetached`. Il modo più semplice è agganciarlo nel `booted()` del modello Layer:

```php
protected static function booted(): void
{
    static::deleted(function (Layer $layer) {
        $layer->featureCollections()
            ->where('mode', 'generated')
            ->each(function ($fc) {
                \Wm\WmPackage\Jobs\FeatureCollection\GenerateFeatureCollectionJob::dispatch($fc->id);
            });
    });
}
```

Per il cambio delle taxonomyWheres, aggiungi i listener nella definizione della relazione. Cerca dove viene registrato `taxonomyWheres()` in `TaxonomyWhereAbleModel` trait e aggiungi il dispatch dopo `sync`/`attach`/`detach`. In alternativa, osserva la tabella pivot direttamente tramite Model events nel trait.

Il punto di aggancio più semplice e sicuro è in `TaxonomyWhereAbleModel` — dopo ogni `sync` delle taxonomyWheres, dispatchare il job. Aggiungi questo metodo al trait `wm-package/src/Traits/TaxonomyWhereAbleModel.php`:

```php
protected function dispatchFeatureCollectionRegeneration(): void
{
    if ($this instanceof \Wm\WmPackage\Models\Layer) {
        $this->featureCollections()
            ->where('mode', 'generated')
            ->where('enabled', true)
            ->each(function ($fc) {
                \Wm\WmPackage\Jobs\FeatureCollection\GenerateFeatureCollectionJob::dispatch($fc->id);
            });
    }
}
```

Chiama `$this->dispatchFeatureCollectionRegeneration()` dopo ogni operazione che modifica le taxonomyWheres nel trait.

- [ ] **Step 4: Commit**

```bash
git -C wm-package add src/Observers/LayerTaxonomyWhereObserver.php src/Models/Layer.php src/Traits/TaxonomyWhereAbleModel.php
git -C wm-package commit -m "feat: trigger FeatureCollection regeneration on Layer taxonomy_where change or delete"
```

---

## Task 9: AppConfigService — serializzazione overlays

**Files:**
- Modify: `wm-package/src/Services/Models/App/AppConfigService.php`
- Create: `wm-package/tests/Feature/AppConfigServiceOverlaysTest.php`

- [ ] **Step 1: Scrivi il test**

Crea `wm-package/tests/Feature/AppConfigServiceOverlaysTest.php`:

```php
<?php

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\FeatureCollection;
use Wm\WmPackage\Services\Models\App\AppConfigService;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('includes enabled feature collections in MAP.controls.overlays ordered by config_overlays', function () {
    $app = App::factory()->createQuietly([
        'primary_color' => '#FF0000',
    ]);

    $fc1 = FeatureCollection::factory()->createQuietly([
        'app_id' => $app->id,
        'enabled' => true,
        'mode' => 'external',
        'external_url' => 'https://example.com/fc1.geojson',
        'label' => ['it' => 'Overlay 1'],
        'clickable' => true,
    ]);

    $fc2 = FeatureCollection::factory()->createQuietly([
        'app_id' => $app->id,
        'enabled' => true,
        'mode' => 'external',
        'external_url' => 'https://example.com/fc2.geojson',
        'label' => ['it' => 'Overlay 2'],
    ]);

    $app->update(['config_overlays' => [$fc2->id, $fc1->id]]); // fc2 prima di fc1

    $service = new AppConfigService($app);
    $config = $service->config();

    $overlays = $config['MAP']['controls']['overlays'] ?? [];
    $urls = collect($overlays)->where('type', 'button')->pluck('url')->values()->all();

    expect($urls[0])->toBe('https://example.com/fc2.geojson');
    expect($urls[1])->toBe('https://example.com/fc1.geojson');
});

it('excludes disabled feature collections from overlays', function () {
    $app = App::factory()->createQuietly();

    $fc = FeatureCollection::factory()->createQuietly([
        'app_id' => $app->id,
        'enabled' => false,
        'mode' => 'external',
        'external_url' => 'https://example.com/fc.geojson',
        'label' => ['it' => 'Disabled'],
    ]);

    $app->update(['config_overlays' => [$fc->id]]);

    $service = new AppConfigService($app);
    $config = $service->config();

    $overlays = $config['MAP']['controls']['overlays'] ?? [];
    expect($overlays)->toHaveCount(0);
});
```

- [ ] **Step 2: Esegui il test — verifica che fallisca**

```bash
docker exec -it php-${APP_NAME} vendor/bin/pest tests/Feature/AppConfigServiceOverlaysTest.php
```

Expected: FAIL.

- [ ] **Step 3: Aggiorna AppConfigService**

In `wm-package/src/Services/Models/App/AppConfigService.php`, sostituisci il blocco commentato (righe ~427-470) con:

```php
// FeatureCollections (overlays)
$configOverlays = $this->app->config_overlays ?? [];
if (! empty($configOverlays)) {
    $fcs = \Wm\WmPackage\Models\FeatureCollection::whereIn('id', $configOverlays)
        ->where('app_id', $this->app->id)
        ->where('enabled', true)
        ->get()
        ->keyBy('id');

    $data['MAP']['controls']['overlays'][] = [
        'label' => $this->app->getTranslations('overlays_label'),
        'type' => 'title',
    ];

    foreach ($configOverlays as $fcId) {
        $fc = $fcs->get($fcId);
        if (! $fc) {
            continue;
        }

        $array = [];
        $array['label'] = $fc->label ?? [];

        if ($fc->default) {
            $array['default'] = true;
        }

        if ($fc->icon) {
            $array['icon'] = $fc->icon;
        }

        $primaryColor = $this->app->primary_color ?? '#000000';
        $array['fillColor'] = $fc->fill_color ? hexToRgba($fc->fill_color) : hexToRgba($primaryColor);
        $array['strokeColor'] = $fc->stroke_color ? hexToRgba($fc->stroke_color) : hexToRgba($primaryColor);

        if ($fc->stroke_width) {
            $array['strokeWidth'] = $fc->stroke_width;
        }

        $array['url'] = $fc->getUrl();

        if ($fc->configuration) {
            $array = array_merge($array, $fc->configuration);
        }

        $array['type'] = 'button';
        $data['MAP']['controls']['overlays'][] = $array;
    }
}
```

Aggiungi anche `use Wm\WmPackage\Models\FeatureCollection;` in testa al file se non presente.

- [ ] **Step 4: Esegui i test**

```bash
docker exec -it php-${APP_NAME} vendor/bin/pest tests/Feature/AppConfigServiceOverlaysTest.php
```

Expected: PASS (2 test).

- [ ] **Step 5: Commit**

```bash
git -C wm-package add src/Services/Models/App/AppConfigService.php tests/Feature/AppConfigServiceOverlaysTest.php
git -C wm-package commit -m "feat: implement overlays serialization in AppConfigService using FeatureCollection"
```

---

## Task 10: Nova Resource `FeatureCollection` (wm-package)

**Files:**
- Create: `wm-package/src/Nova/FeatureCollection.php`

- [ ] **Step 1: Crea la risorsa Nova**

Crea `wm-package/src/Nova/FeatureCollection.php`:

```php
<?php

namespace Wm\WmPackage\Nova;

use Illuminate\Http\Request;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;
use Laravel\Nova\Resource;
use Outl1ne\NovaTabTranslatable\NovaTabTranslatable;
use Wm\WmPackage\Nova\Actions\FeatureCollection\GenerateFeatureCollectionAction;

class FeatureCollection extends Resource
{
    public static $model = \Wm\WmPackage\Models\FeatureCollection::class;

    public static $title = 'name';

    public static $search = ['id', 'name'];

    public static $with = ['app', 'layers'];

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            // Pannello Base
            Panel::make(__('Base'), [
                Text::make(__('Name'), 'name')->rules('required')->sortable(),
                NovaTabTranslatable::make([
                    Text::make(__('Label'), 'label'),
                ]),
                BelongsTo::make(__('App'), 'app', App::class)->required(),
                Boolean::make(__('Enabled'), 'enabled'),
                Boolean::make(__('Default'), 'default'),
                Boolean::make(__('Clickable'), 'clickable'),
            ]),

            // Pannello Sorgente
            Panel::make(__('Source'), [
                Select::make(__('Mode'), 'mode')
                    ->options([
                        'generated' => 'Generated (from layers)',
                        'upload' => 'Upload file',
                        'external' => 'External URL',
                    ])
                    ->required()
                    ->displayUsingLabels(),

                BelongsToMany::make(__('Layers'), 'layers', Layer::class)
                    ->hideWhenCreating()
                    ->help(__('Only used in generated mode')),

                Text::make(__('External URL'), 'external_url')
                    ->nullable()
                    ->help(__('Only used in external mode')),
            ]),

            // Pannello Stile
            Panel::make(__('Style'), [
                Text::make(__('Fill Color'), 'fill_color')->nullable(),
                Text::make(__('Stroke Color'), 'stroke_color')->nullable(),
                Text::make(__('Stroke Width'), 'stroke_width')->nullable(),
                Textarea::make(__('Icon (SVG)'), 'icon')->nullable()->hideFromIndex(),
            ]),

            // Pannello Configurazione
            Panel::make(__('Configuration'), [
                Code::make(__('Configuration'), 'configuration')
                    ->json()
                    ->nullable()
                    ->hideFromIndex(),
            ]),

            // Pannello Stato (solo detail)
            Panel::make(__('Status'), [
                Text::make(__('File Path'), 'file_path')->readonly()->onlyOnDetail(),
                DateTime::make(__('Generated At'), 'generated_at')->readonly()->onlyOnDetail(),
            ]),
        ];
    }

    public function actions(NovaRequest $request): array
    {
        return [
            new GenerateFeatureCollectionAction,
        ];
    }
}
```

- [ ] **Step 2: Crea l'Action Nova "Rigenera"**

Crea `wm-package/src/Nova/Actions/FeatureCollection/GenerateFeatureCollectionAction.php`:

```php
<?php

namespace Wm\WmPackage\Nova\Actions\FeatureCollection;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Jobs\FeatureCollection\GenerateFeatureCollectionJob;

class GenerateFeatureCollectionAction extends Action
{
    use InteractsWithQueue, Queueable;

    public $name = 'Rigenera';

    public function handle(Collection $models): void
    {
        foreach ($models as $fc) {
            if ($fc->mode === 'generated') {
                GenerateFeatureCollectionJob::dispatch($fc->id);
            }
        }
    }

    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
```

- [ ] **Step 3: Registra la risorsa nel ServiceProvider**

In `wm-package/src/WmPackageServiceProvider.php`, aggiungi `FeatureCollection::class` nell'array delle risorse Nova registrate (cerca dove vengono registrate le altre risorse Nova del package).

- [ ] **Step 4: Commit**

```bash
git -C wm-package add src/Nova/FeatureCollection.php src/Nova/Actions/FeatureCollection/GenerateFeatureCollectionAction.php src/WmPackageServiceProvider.php
git -C wm-package commit -m "feat: add FeatureCollection Nova resource and GenerateFeatureCollectionAction"
```

---

## Task 11: Nova App — Tab "Overlays"

**Files:**
- Modify: `wm-package/src/Nova/App.php`

- [ ] **Step 1: Aggiorna Nova App**

In `wm-package/src/Nova/App.php`, individua dove sono definiti i tab (cerca `Tab::make` o la struttura dei pannelli). Aggiungi il tab "Overlays" seguendo il pattern degli altri tab:

```php
Tab::make(__('Overlays'), [
    NovaTabTranslatable::make([
        Text::make(__('Overlays Label'), 'overlays_label'),
    ]),
    HasMany::make(__('Feature Collections'), 'featureCollections', FeatureCollection::class),
]),
```

Aggiungi gli import necessari in testa al file:
```php
use Wm\WmPackage\Nova\FeatureCollection;
use Laravel\Nova\Fields\HasMany;
```

- [ ] **Step 2: Commit**

```bash
git -C wm-package add src/Nova/App.php
git -C wm-package commit -m "feat: add Overlays tab to Nova App resource"
```

---

## Task 12: LayerApiLinksCard — link FeatureCollection

**Files:**
- Modify: `wm-package/src/Nova/Cards/ApiLinksCard/src/LayerApiLinksCard.php`

- [ ] **Step 1: Aggiorna LayerApiLinksCard**

Apri `wm-package/src/Nova/Cards/ApiLinksCard/src/LayerApiLinksCard.php`. Il costruttore attuale aggiunge solo il link Elasticsearch. Aggiorna per aggiungere anche i link delle FeatureCollection:

```php
<?php

namespace Wm\WmPackage\Nova\Cards\ApiLinksCard\src;

use Wm\WmPackage\Models\Layer;

class LayerApiLinksCard extends ApiLinksCard
{
    public function __construct(Layer $layer)
    {
        parent::__construct([
            [
                'label' => 'Elasticsearch',
                'url' => url('/api/v2/elasticsearch')
                    .'?app=geohub_app_'.$layer->app_id
                    .'&layer='.$layer->id,
            ],
        ]);

        $layer->featureCollections()
            ->where('mode', 'generated')
            ->where('enabled', true)
            ->whereNotNull('file_path')
            ->each(function ($fc) {
                $this->addLink(
                    'FeatureCollection: '.($fc->name),
                    $fc->getUrl()
                );
            });
    }
}
```

- [ ] **Step 2: Commit**

```bash
git -C wm-package add src/Nova/Cards/ApiLinksCard/src/LayerApiLinksCard.php
git -C wm-package commit -m "feat: add FeatureCollection links to LayerApiLinksCard"
```

---

## Task 13: Wrapper forestas app

**Files:**
- Create: `app/Nova/FeatureCollection.php`

- [ ] **Step 1: Crea il wrapper Nova**

Crea `app/Nova/FeatureCollection.php`:

```php
<?php

namespace App\Nova;

use Wm\WmPackage\Nova\FeatureCollection as WmFeatureCollection;

class FeatureCollection extends WmFeatureCollection {}
```

- [ ] **Step 2: Registra la risorsa in NovaServiceProvider**

In `app/Providers/NovaServiceProvider.php`, aggiungi `App\Nova\FeatureCollection::class` nell'array `$resources` o nel metodo `resources()`.

- [ ] **Step 3: Commit**

```bash
git add app/Nova/FeatureCollection.php app/Providers/NovaServiceProvider.php
git commit -m "feat: register FeatureCollection Nova resource in forestas"
```

---

## Task 14: Test end-to-end job dispatch

**Files:**
- Create: `wm-package/tests/Feature/GenerateFeatureCollectionJobTest.php`

- [ ] **Step 1: Scrivi il test**

```php
<?php

use Illuminate\Support\Facades\Queue;
use Wm\WmPackage\Jobs\FeatureCollection\GenerateFeatureCollectionJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\FeatureCollection;
use Wm\WmPackage\Models\Layer;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('dispatches GenerateFeatureCollectionJob when layer taxonomy_where changes', function () {
    Queue::fake();

    $app = App::factory()->createQuietly();
    $layer = Layer::factory()->createQuietly(['app_id' => $app->id]);
    $fc = FeatureCollection::factory()->createQuietly([
        'app_id' => $app->id,
        'mode' => 'generated',
        'enabled' => true,
    ]);
    $fc->layers()->attach($layer->id);

    // Simula attach di una taxonomy where al layer
    $layer->taxonomyWheres()->sync([]);

    Queue::assertDispatched(GenerateFeatureCollectionJob::class, function ($job) use ($fc) {
        return $job->featureCollectionId === $fc->id;
    });
});
```

- [ ] **Step 2: Esegui il test**

```bash
docker exec -it php-${APP_NAME} vendor/bin/pest tests/Feature/GenerateFeatureCollectionJobTest.php
```

Expected: PASS (1 test). Se fallisce, verifica che il trigger dell'observer sia correttamente collegato alla sync delle taxonomyWheres nel Task 8.

- [ ] **Step 3: Esegui tutta la suite**

```bash
docker exec -it php-${APP_NAME} vendor/bin/pest
```

Expected: tutta la suite verde.

- [ ] **Step 4: Commit**

```bash
git -C wm-package add tests/Feature/GenerateFeatureCollectionJobTest.php
git -C wm-package commit -m "test: add end-to-end job dispatch test for FeatureCollection"
```

---

## Self-Review

### Spec coverage
- ✅ Modello `FeatureCollection` con tre mode (generated/upload/external)
- ✅ Tabella `feature_collection_layer` pivot
- ✅ `clickable` configurabile per FeatureCollection
- ✅ Un solo `default` per app (enforced in `booted()`)
- ✅ Generazione GeoJSON: per ogni layer → per ogni TaxonomyWhere → Feature con `{layer_id, clickable}`
- ✅ Storage in S3/MinIO a `/{shard}/{appId}/feature-collection/{id}.geojson`
- ✅ Job asincrono `GenerateFeatureCollectionJob`
- ✅ Trigger rigenerazione su change taxonomy_where del layer e su delete del layer
- ✅ Serializzazione in `MAP.controls.overlays` in AppConfigService
- ✅ Ordine da `config_overlays` su App
- ✅ `overlays_label` traducibile su App
- ✅ Nova risorsa con Action "Rigenera"
- ✅ Tab "Overlays" su Nova App
- ✅ `LayerApiLinksCard` con link FeatureCollection
- ✅ Wrapper thin in forestas

### Note
- Il Task 8 (observer) richiede attenzione: il punto esatto dove agganciare il dispatch dipende dall'implementazione del trait `TaxonomyWhereAbleModel`. Leggere il trait prima di procedere.
- `hexToRgba()` è una helper function già presente nel codebase (usata nel codice commentato). Verificare che sia disponibile prima del Task 9.
- Il `featureCollectionId` nel job deve essere una proprietà `public` per permettere al test di accedervi: aggiungi `public` alla proprietà nel costruttore del job.
