> Ticket: oc:8718

# UgcTrack duplicati con lo stesso uuid — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Regola Webmapp:** nessun `git commit`, `git add`, `git push` né creazione di branch durante l'esecuzione. Gli step "Commit" sono istruzioni per il dev, eseguite solo dopo il review-gate.

**Goal:** lo store UGC con un uuid già presente aggiorna il record esistente (unendo le properties e senza duplicare le immagini), e un command sistema le UgcTrack già duplicate archiviando le copie.

**Architecture:** la ricerca per uuid si sposta in `UgcController::store()` e legge l'uuid dai dati già validati (l'app manda un multipart con il campo `feature` in JSON, che `$request->input('properties.uuid')` non vede). La deduplica delle immagini per sha256 vive in un servizio dedicato, usato sia dallo store sia dal command. Il command delega la logica a un servizio che lavora un gruppo di duplicati per transazione, in SQL puro dove tocca le geometrie.

**Tech Stack:** Laravel, PostgreSQL/PostGIS, Spatie Media Library 11, Pest, Orchestra Testbench.

**Spec:** [overview.md](overview.md) (wm-package) e `docs/features/8718-ugctrack-duplicati-stesso-uuid/overview.md` (camminiditalia).

## Global Constraints

- PHP minimo `>8.1`: niente `const` nei trait.
- Le geometrie PostGIS passano da SQL puro: mai risalvare un modello geometrico via Eloquent nel command.
- Query raw con binding posizionali: l'operatore jsonb `?` si scrive `??`; nessun commento `--` con `oc:NNNN` dentro l'SQL.
- Documentazione e commenti in italiano, termini tecnici in inglese.
- Test del package: si lanciano dal repo camminiditalia con
  `docker run --rm --network camminiditalia_default -e DB_HOST=postgres-camminiditalia -v "$PWD/wm-package:/app" -w /app wm-phpfpm:8.4 vendor/bin/pest <test>` (DB `wm_package`).
- Le chiavi custom dei progetti (es. `layer_id_auto_resolved` di camminiditalia) non compaiono mai nel codice del package.

## Review Focus

1. Richiesta dell'app reale (multipart, campo `feature` JSON + `images[]`): lo store deve riconoscere l'uuid. Coperto in Task 1.
2. Retry con le stesse immagini già salvate: nessuna immagine in più; retry con un'immagine nuova: solo quella aggiunta. Coperto in Task 2.
3. Unione delle properties nel command: la modifica più recente dell'utente vince, le chiavi del server restano del padre. Coperto in Task 4.
4. Command rilanciato dopo `--execute`: non trova più nulla e non scrive. Coperto in Task 4.

---

## File Structure

| File | Repo | Responsabilità |
|---|---|---|
| `src/Http/Controllers/Api/Abstracts/UgcController.php` | wm-package | store: ricerca per uuid, unione properties; `fillModelWithRequest`: immagini via servizio hash |
| `src/Http/Controllers/Api/UgcPoiController.php` | wm-package | `getModelIstance()` torna a restituire solo un modello nuovo (la ricerca è nello store) |
| `src/Services/Models/UgcMediaHashService.php` | wm-package | sha256 dei media (salvato in `custom_properties.sha256`), ricerca doppioni per contenuto |
| `src/Services/Models/UgcDuplicatesService.php` | wm-package | gruppi di duplicati, unione di un gruppo in transazione, archivio |
| `src/Commands/WmFixDuplicatedUgcTracksCommand.php` | wm-package | `wm:fix-duplicated-ugc-tracks`: report CSV, `--execute` (CSV poi tolto: vedi [notes.md](notes.md#task-3-report-senza-csv)) |
| `database/migrations/zz_2026_10_07_000001_create_ugc_duplicates_archive_table.php.stub` | wm-package | tabella `ugc_duplicates_archive` |
| `src/WmPackageServiceProvider.php` | wm-package | registrazione del command |
| `tests/Feature/UgcStoreUuidTest.php` | wm-package | Task 1 |
| `tests/Feature/UgcStoreImageDedupTest.php` | wm-package | Task 2 |
| `tests/Feature/WmFixDuplicatedUgcTracksCommandTest.php` | wm-package | Task 3–4 |
| gitlink `wm-package`, `database/migrations/` | camminiditalia | Task 5 |

---

### Task 1: Store idempotente per uuid con unione delle properties

**Files:**
- Modify: `src/Http/Controllers/Api/Abstracts/UgcController.php:42-51` (`store()`)
- Modify: `src/Http/Controllers/Api/UgcPoiController.php:13-25` (`getModelIstance()`)
- Test: `tests/Feature/UgcStoreUuidTest.php`

**Interfaces:**
- Produces: `protected function findExistingByUuid(array $validated): ?GeometryModel` in `UgcController`.

- [ ] **Step 1: Scrivi i test che falliscono**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;

beforeEach(function () {
    config()->set('wm-package.shard_name', 'test_shard');
    config()->set('medialibrary.disk_name', 'public');
    Storage::fake('s3');
    Storage::fake('wmfe');
    Storage::fake('public');
    $this->withoutMiddleware('auth.jwt');
    $this->artisan('jwt:secret --always-no');
    $this->app_ = App::factory()->createQuietly();
    $this->user = User::factory()->create(['app_id' => $this->app_->id]);
    $this->actingAs($this->user, 'api');
});

/** Richiesta come la costruisce l'app (wm-core `_buildFormData`): multipart con `feature` in JSON. */
function trackFeature(string $uuid, array $extra = []): array
{
    return [
        'type' => 'Feature',
        'properties' => array_merge([
            'name' => 'Traccia di prova',
            'uuid' => $uuid,
            'app_id' => test()->app_->id,
            'form' => ['id' => 'track', 'title' => 'Traccia di prova', 'difficulty' => 'easy'],
            'updatedAt' => '2026-06-16T14:00:58.399Z',
        ], $extra),
        'geometry' => ['type' => 'LineString', 'coordinates' => [[13.0, 43.0, 10], [13.001, 43.001, 10]]],
    ];
}

function postAppStyle(string $url, array $feature, array $files = [])
{
    // Come l'app: il campo images[] c'è solo se ci sono foto.
    $data = ['feature' => json_encode($feature)];
    if ($files !== []) {
        $data['images'] = $files;
    }

    return test()->post($url, $data);
}

it('crea una traccia nuova quando l\'uuid non esiste', function () {
    $response = postAppStyle('api/ugc/track/store', trackFeature('uuid-nuovo'));

    $response->assertStatus(201);
    expect(UgcTrack::where('properties->uuid', 'uuid-nuovo')->count())->toBe(1);
});

it('aggiorna la traccia esistente quando l\'uuid c\'è già (richiesta multipart come l\'app)', function () {
    $first = postAppStyle('api/ugc/track/store', trackFeature('uuid-retry'))->json('id');

    $second = postAppStyle('api/ugc/track/store', trackFeature('uuid-retry', ['form' => ['id' => 'track', 'difficulty' => 'medium']]));

    $second->assertStatus(201);
    expect($second->json('id'))->toBe($first)
        ->and(UgcTrack::where('properties->uuid', 'uuid-retry')->count())->toBe(1)
        ->and(UgcTrack::find($first)->properties['form']['difficulty'])->toBe('medium');
});

it('conserva le chiavi che l\'app non manda (es. layer_id assegnato dal server)', function () {
    $id = postAppStyle('api/ugc/track/store', trackFeature('uuid-merge'))->json('id');
    $track = UgcTrack::find($id);
    UgcTrack::withoutEvents(function () use ($track) {
        $props = $track->properties;
        $props['layer_id'] = 42;
        $props['chiave_del_progetto'] = false;
        $track->properties = $props;
        $track->saveQuietly();
    });

    postAppStyle('api/ugc/track/store', trackFeature('uuid-merge'))->assertStatus(201);

    expect(UgcTrack::where('properties->uuid', 'uuid-merge')->count())->toBe(1);
    $props = UgcTrack::find($id)->properties;
    expect($props['layer_id'])->toBe(42)
        ->and($props['chiave_del_progetto'])->toBeFalse();
});

it('con duplicati già presenti aggiorna sempre la riga più vecchia', function () {
    $older = postAppStyle('api/ugc/track/store', trackFeature('uuid-doppio'))->json('id');
    $newer = UgcTrack::withoutEvents(fn () => UgcTrack::factory()->create([
        'user_id' => $this->user->id, 'app_id' => $this->app_->id,
        'properties' => ['name' => 'copia', 'uuid' => 'uuid-doppio'],
    ]))->id;
    expect($newer)->toBeGreaterThan($older);

    $response = postAppStyle('api/ugc/track/store', trackFeature('uuid-doppio'));

    expect($response->json('id'))->toBe($older);
});

it('senza uuid crea sempre un record nuovo', function () {
    $feature = trackFeature('x');
    unset($feature['properties']['uuid']);

    postAppStyle('api/ugc/track/store', $feature)->assertStatus(201);
    postAppStyle('api/ugc/track/store', $feature)->assertStatus(201);

    expect(UgcTrack::where('name', 'Traccia di prova')->count())->toBe(2);
});

it('vale anche per i POI con la richiesta multipart dell\'app', function () {
    $poi = fn () => [
        'type' => 'Feature',
        'properties' => ['name' => 'Poi', 'uuid' => 'uuid-poi', 'app_id' => $this->app_->id, 'form' => ['id' => 'poi']],
        'geometry' => ['type' => 'Point', 'coordinates' => [13.0, 43.0]],
    ];

    $first = postAppStyle('api/ugc/poi/store', $poi())->json('id');
    $second = postAppStyle('api/ugc/poi/store', $poi())->json('id');

    expect($second)->toBe($first)
        ->and(UgcPoi::where('properties->uuid', 'uuid-poi')->count())->toBe(1);
});
```

- [ ] **Step 2: Lancia i test e verifica che falliscano**

Run: `docker run --rm --network camminiditalia_default -e DB_HOST=postgres-camminiditalia -v "$PWD/wm-package:/app" -w /app wm-phpfpm:8.4 vendor/bin/pest tests/Feature/UgcStoreUuidTest.php`
Expected: FAIL su "aggiorna la traccia esistente", "conserva le chiavi", "riga più vecchia" e **anche su "vale anche per i POI"** (conferma che oggi `$request->input('properties.uuid')` non vede l'uuid dentro `feature`). Se il test POI passa, fermati e segnalalo nelle note: l'ipotesi sul multipart è sbagliata.

- [ ] **Step 3: Implementa**

In `UgcController.php` sostituisci `store()`:

```php
public function store(Request $request): JsonResponse
{
    $validated = $this->validateGeojson($request);

    $existing = $this->findExistingByUuid($validated);
    if ($existing) {
        // Retry dell'app (stesso uuid): si aggiorna il record. Le properties ricevute vanno sopra
        // quelle salvate, così restano le chiavi scritte dal server che l'app non conosce.
        $validated['properties'] = array_merge($existing->properties ?? [], $validated['properties']);
    }

    $model = $this->fillModelWithRequest($existing ?? $this->getModelIstance(), $request, $validated);

    $this->enrichUgcWithTaxonomyWhere($model);

    return response()->json(['id' => $model->id, 'message' => 'Created successfully'], 201);
}

/**
 * UGC già salvato con lo stesso properties.uuid. L'uuid si legge dai dati validati: l'app manda
 * un multipart con la feature in JSON nel campo `feature`, che `$request->input()` non decodifica.
 * Con duplicati già presenti si prende il più vecchio, cioè il padre del command di normalizzazione.
 */
protected function findExistingByUuid(array $validated): ?GeometryModel
{
    $uuid = $validated['properties']['uuid'] ?? null;
    if (! is_string($uuid) || $uuid === '') {
        return null;
    }

    return $this->getModelIstance()->newQuery()
        ->where('properties->uuid', $uuid)
        ->orderBy('id')
        ->first();
}
```

Aggiungi `use Wm\WmPackage\Models\Abstracts\GeometryModel;` se non già importato.

In `UgcPoiController.php` la ricerca non serve più:

```php
protected function getModelIstance(?Request $request = null): UgcPoi
{
    return new UgcPoi;
}
```

- [ ] **Step 4: Lancia i test e verifica che passino**

Run: lo stesso comando dello Step 2, più `tests/Feature/UgcPoiController tests/Feature/UgcControllerTaxonomyWhereAsyncFallbackTest.php`.
Expected: PASS.

- [ ] **Step 5: Commit (istruzione per il dev)**

```bash
git -C wm-package add src/Http/Controllers/Api tests/Feature/UgcStoreUuidTest.php
git -C wm-package commit -m "fix(oc:8718): store UGC idempotente per uuid, con unione delle properties"
```

---

### Task 2: Immagini senza doppioni (sha256)

**Files:**
- Create: `src/Services/Models/UgcMediaHashService.php`
- Modify: `src/Http/Controllers/Api/Abstracts/UgcController.php` (`fillModelWithRequest()`, blocco `if ($request->has('images'))`)
- Test: `tests/Feature/UgcStoreImageDedupTest.php`

**Interfaces:**
- Produces: `UgcMediaHashService::make()`; `hashOf(Media $media): string`; `hashOfFile(string $path): string`; `findByContent(HasMedia $model, string $hash, string $collection = 'default'): ?Media`; costante `HASH_PROPERTY = 'sha256'`.

- [ ] **Step 1: Scrivi i test che falliscono**

```php
<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;

beforeEach(function () {
    config()->set('wm-package.shard_name', 'test_shard');
    config()->set('medialibrary.disk_name', 'public');
    Storage::fake('s3');
    Storage::fake('wmfe');
    Storage::fake('public');
    $this->withoutMiddleware('auth.jwt');
    $this->artisan('jwt:secret --always-no');
    $this->app_ = App::factory()->createQuietly();
    $this->user = User::factory()->create(['app_id' => $this->app_->id]);
    $this->actingAs($this->user, 'api');
});

function featureWithUuid(string $uuid): string
{
    return json_encode([
        'type' => 'Feature',
        'properties' => ['name' => 'T', 'uuid' => $uuid, 'app_id' => test()->app_->id],
        'geometry' => ['type' => 'LineString', 'coordinates' => [[13.0, 43.0, 10], [13.001, 43.001, 10]]],
    ]);
}

/** JPEG vero generato da GD: a parità di seme (quindi di dimensioni) i byte sono gli stessi. */
function jpeg(string $seed): UploadedFile
{
    $side = 10 + ord($seed) - ord('a');

    return UploadedFile::fake()->image("image_{$seed}.jpg", $side, $side);
}

it('un retry con le stesse immagini non le duplica', function () {
    $id = $this->post('api/ugc/track/store', ['feature' => featureWithUuid('u1'), 'images' => [jpeg('a'), jpeg('b')]])->json('id');
    $this->post('api/ugc/track/store', ['feature' => featureWithUuid('u1'), 'images' => [jpeg('a'), jpeg('b')]]);

    expect(UgcTrack::find($id)->getMedia('default'))->toHaveCount(2);
});

it('un retry con un\'immagine nuova aggiunge solo quella', function () {
    $id = $this->post('api/ugc/track/store', ['feature' => featureWithUuid('u2'), 'images' => [jpeg('a')]])->json('id');
    $this->post('api/ugc/track/store', ['feature' => featureWithUuid('u2'), 'images' => [jpeg('a'), jpeg('c')]]);

    expect(UgcTrack::find($id)->getMedia('default'))->toHaveCount(2);
});

it('salva lo sha256 nei custom_properties del media', function () {
    $file = jpeg('a');
    $expected = hash_file('sha256', $file->getRealPath());
    $id = $this->post('api/ugc/track/store', ['feature' => featureWithUuid('u4'), 'images' => [$file]])->json('id');

    $media = UgcTrack::find($id)->getFirstMedia('default');
    expect($media->getCustomProperty('sha256'))->toBe($expected);
});

it('riconosce un media vecchio senza hash, calcolandolo al volo', function () {
    $id = $this->post('api/ugc/track/store', ['feature' => featureWithUuid('u5'), 'images' => [jpeg('a')]])->json('id');
    $media = UgcTrack::find($id)->getFirstMedia('default');
    $media->forgetCustomProperty('sha256');
    $media->save();

    $this->post('api/ugc/track/store', ['feature' => featureWithUuid('u5'), 'images' => [jpeg('a')]]);

    $track = UgcTrack::find($id);
    expect($track->getMedia('default'))->toHaveCount(1)
        ->and($track->getFirstMedia('default')->getCustomProperty('sha256'))->not->toBeNull();
});
```

- [ ] **Step 2: Lancia i test e verifica che falliscano**

Run: `docker run --rm --network camminiditalia_default -e DB_HOST=postgres-camminiditalia -v "$PWD/wm-package:/app" -w /app wm-phpfpm:8.4 vendor/bin/pest tests/Feature/UgcStoreImageDedupTest.php`
Expected: FAIL (oggi le immagini si aggiungono sempre e nessun hash viene salvato).

- [ ] **Step 3: Scrivi il servizio**

```php
<?php

namespace Wm\WmPackage\Services\Models;

use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Wm\WmPackage\Services\BaseService;

/**
 * Riconosce le immagini UGC per contenuto (oc:8718).
 *
 * L'app, a ogni retry di una store, rimanda tutte le immagini con gli stessi byte ma nomi
 * posizionali (image_0.jpg, …): il confronto si fa sullo sha256 del file. L'hash si salva nei
 * custom_properties del media all'upload; per i media che non ce l'hanno si calcola scaricando il
 * file, e poi si salva.
 */
class UgcMediaHashService extends BaseService
{
    public const HASH_PROPERTY = 'sha256';

    public function hashOfFile(string $path): string
    {
        return hash_file('sha256', $path);
    }

    public function hashOf(Media $media): string
    {
        $hash = $media->getCustomProperty(self::HASH_PROPERTY);
        if (is_string($hash) && $hash !== '') {
            return $hash;
        }

        $stream = $media->stream();
        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        $hash = hash_final($context);

        $media->setCustomProperty(self::HASH_PROPERTY, $hash);
        $media->saveQuietly();

        return $hash;
    }

    public function findByContent(HasMedia $model, string $hash, string $collection = 'default'): ?Media
    {
        foreach ($model->getMedia($collection) as $media) {
            if ($this->hashOf($media) === $hash) {
                return $media;
            }
        }

        return null;
    }
}
```

`Wm\WmPackage\Services\BaseService` fornisce `public static function make(): static` (verificato).

- [ ] **Step 4: Usa il servizio in `fillModelWithRequest()`**

Sostituisci il blocco `if ($request->has('images')) { … }`:

```php
if ($request->hasFile('images')) {
    $hashes = UgcMediaHashService::make();
    foreach ((array) $request->file('images') as $file) {
        $hash = $hashes->hashOfFile($file->getRealPath());
        // Immagine già presente (retry dell'app): non si salva.
        if ($hashes->findByContent($model, $hash)) {
            continue;
        }
        $model->addMedia($file)
            ->withCustomProperties([UgcMediaHashService::HASH_PROPERTY => $hash])
            ->toMediaCollection('default');
    }
}
```


- [ ] **Step 5: Lancia i test**

Run: lo stesso comando dello Step 2, più `tests/Feature/UgcStoreUuidTest.php tests/Feature/UgcPoiController`.
Expected: PASS.

- [ ] **Step 6: Commit (istruzione per il dev)**

```bash
git -C wm-package add src/Services/Models/UgcMediaHashService.php src/Http/Controllers/Api/Abstracts/UgcController.php tests/Feature/UgcStoreImageDedupTest.php
git -C wm-package commit -m "fix(oc:8718): immagini UGC deduplicate per contenuto (sha256)"
```

---

### Task 3: Tabella di archivio e command in modalità report

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-report-senza-csv); command poi rinominato `wm:fix-duplicated-ugc` ed esteso ai POI: [notes.md](notes.md#poi-allineati-alle-tracce-08102026)

**Files:**
- Create: `database/migrations/zz_2026_10_07_000001_create_ugc_duplicates_archive_table.php.stub`
- Create: `src/Services/Models/UgcDuplicatesService.php`
- Create: `src/Commands/WmFixDuplicatedUgcTracksCommand.php`
- Modify: `src/WmPackageServiceProvider.php` (import + elenco `hasCommands`, accanto a `WmCleanUgcTrackGeometryCommand`)
- Test: `tests/Feature/WmFixDuplicatedUgcTracksCommandTest.php`

**Interfaces:**
- Produces:
  - `UgcDuplicatesService::groups(?int $appId = null): Collection` — una voce per uuid duplicato: `['uuid' => string, 'parent_id' => int, 'copy_ids' => int[], 'max_distance_m' => float]`
  - `UgcDuplicatesService::DEFAULT_MAX_DISTANCE_METERS = 1.0`
  - command `wm:fix-duplicated-ugc-tracks {--execute} {--app-id=} {--max-distance=1}`; CSV in `storage/logs/duplicated-ugc-tracks-YYYY-MM-DD.csv`

- [ ] **Step 1: Scrivi la migration (stub)**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ugc_duplicates_archive')) {
            return;
        }

        // Copie UGC duplicate (stesso properties.uuid) rimosse dal command di normalizzazione
        // (oc:8718). reference_id è il record tenuto: niente FK, l'archivio deve sopravvivere
        // anche se un giorno il padre viene cancellato.
        Schema::create('ugc_duplicates_archive', function (Blueprint $table) {
            $table->id();
            $table->string('model_type');
            $table->unsignedBigInteger('original_id');
            $table->unsignedBigInteger('reference_id');
            $table->string('uuid')->nullable();
            $table->integer('user_id')->nullable();
            $table->integer('app_id')->nullable();
            $table->jsonb('properties')->nullable();
            $table->jsonb('media_ids')->nullable();
            $table->timestamp('original_created_at')->nullable();
            $table->timestamp('original_updated_at')->nullable();
            $table->timestamp('archived_at');
            $table->timestamps();

            $table->index(['model_type', 'reference_id']);
            $table->index('uuid');
        });

        // Geografia senza vincolo di tipo: deve contenere sia linee (tracce) sia punti (POI).
        DB::statement('ALTER TABLE ugc_duplicates_archive ADD COLUMN geometry geography');
    }

    public function down(): void
    {
        Schema::dropIfExists('ugc_duplicates_archive');
    }
};
```

- [ ] **Step 2: Scrivi i test del report che falliscono**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;

beforeEach(function () {
    $this->app_ = App::factory()->createQuietly();
    $this->user = User::factory()->create(['app_id' => $this->app_->id]);
});

/** Traccia salvata senza observer, con geometria scritta in SQL (come i dati reali). */
function dupTrack(int $userId, int $appId, string $uuid, array $props = [], string $wkt = 'MULTILINESTRING Z ((13 43 10, 13.001 43.001 10))', ?string $createdAt = null): UgcTrack
{
    $track = UgcTrack::withoutEvents(fn () => UgcTrack::factory()->create([
        'user_id' => $userId,
        'app_id' => $appId,
        'name' => $props['name'] ?? 'Tappa',
        'properties' => array_merge(['name' => 'Tappa', 'uuid' => $uuid], $props),
    ]));
    DB::update("UPDATE ugc_tracks SET geometry = ST_GeomFromEWKT(?), created_at = COALESCE(?::timestamp, created_at) WHERE id = ?", ["SRID=4326;{$wkt}", $createdAt, $track->id]);

    return $track->refresh();
}

it('in report elenca i gruppi senza scrivere nulla', function () {
    $a = dupTrack($this->user->id, $this->app_->id, 'g1', [], createdAt: '2026-06-16 14:06:14');
    $b = dupTrack($this->user->id, $this->app_->id, 'g1', [], createdAt: '2026-06-16 14:33:34');

    $this->artisan('wm:fix-duplicated-ugc-tracks')
        ->expectsOutputToContain('1 gruppi')
        ->assertSuccessful();

    expect(UgcTrack::whereIn('id', [$a->id, $b->id])->count())->toBe(2)
        ->and(DB::table('ugc_duplicates_archive')->count())->toBe(0);
});

it('segnala da verificare un gruppo con geometrie distanti più di 1 metro (calcolo in metri)', function () {
    dupTrack($this->user->id, $this->app_->id, 'g2');
    // circa 11 m più a nord: in gradi sarebbe 0.0001, molto sotto 1
    dupTrack($this->user->id, $this->app_->id, 'g2', [], 'MULTILINESTRING Z ((13 43.0001 10, 13.001 43.0011 10))');

    $this->artisan('wm:fix-duplicated-ugc-tracks')
        ->expectsOutputToContain('da verificare')
        ->assertSuccessful();
});

it('tratta come uguali geometrie che differiscono solo per arrotondamento', function () {
    dupTrack($this->user->id, $this->app_->id, 'g3');
    dupTrack($this->user->id, $this->app_->id, 'g3', [], 'MULTILINESTRING Z ((13.0000000001 43 10, 13.001 43.001 10))');

    $groups = \Wm\WmPackage\Services\Models\UgcDuplicatesService::make()->groups();

    expect($groups->firstWhere('uuid', 'g3')['max_distance_m'])->toBeLessThan(1.0);
});
```

- [ ] **Step 3: Lancia i test e verifica che falliscano**

Run: `docker run --rm --network camminiditalia_default -e DB_HOST=postgres-camminiditalia -v "$PWD/wm-package:/app" -w /app wm-phpfpm:8.4 vendor/bin/pest tests/Feature/WmFixDuplicatedUgcTracksCommandTest.php`
Expected: FAIL ("command not found" / classe mancante).

- [ ] **Step 4: Scrivi `UgcDuplicatesService::groups()`**

```php
<?php

namespace Wm\WmPackage\Services\Models;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Services\BaseService;

/**
 * Normalizzazione delle UgcTrack duplicate per properties.uuid (oc:8718): nascono dai retry
 * dell'app quando la store non ha risposto. Il padre è la riga più vecchia; le copie vengono
 * unite nel padre, archiviate in ugc_duplicates_archive e cancellate.
 */
class UgcDuplicatesService extends BaseService
{
    public const DEFAULT_MAX_DISTANCE_METERS = 1.0;

    /** Chiavi di properties scritte dal server: restano sempre quelle del padre. */
    public const SERVER_PROPERTY_KEYS = ['id', 'created_at', 'updated_at', 'taxonomy_where', 'taxonomyWheres'];

    public function groups(?int $appId = null): Collection
    {
        $appFilter = $appId !== null ? 'AND app_id = ?' : '';
        $bindings = $appId !== null ? [$appId] : [];

        $rows = DB::select("
            SELECT properties->>'uuid' AS uuid,
                   array_agg(id ORDER BY created_at, id) AS ids
            FROM ugc_tracks
            WHERE properties->>'uuid' IS NOT NULL AND properties->>'uuid' <> '' {$appFilter}
            GROUP BY properties->>'uuid'
            HAVING count(*) > 1
            ORDER BY min(id)
        ", $bindings);

        return collect($rows)->map(function ($row) {
            $ids = array_map('intval', explode(',', trim($row->ids, '{}')));
            $parentId = array_shift($ids);

            return [
                'uuid' => $row->uuid,
                'parent_id' => $parentId,
                'copy_ids' => $ids,
                'max_distance_m' => $this->maxDistanceMeters($parentId, $ids),
            ];
        });
    }

    /**
     * Distanza massima (metri) fra la geometria del padre e quella delle copie. La colonna è
     * geography: ST_HausdorffDistance non la accetta e su geometry 4326 misurerebbe in gradi.
     * Si proietta in 3857 e si corregge il fattore di scala con il coseno della latitudine.
     */
    public function maxDistanceMeters(int $parentId, array $copyIds): float
    {
        if ($copyIds === []) {
            return 0.0;
        }
        $placeholders = implode(',', array_fill(0, count($copyIds), '?'));
        $row = DB::selectOne("
            SELECT COALESCE(max(
                ST_HausdorffDistance(
                    ST_Transform(ST_Force2D(p.geometry::geometry), 3857),
                    ST_Transform(ST_Force2D(c.geometry::geometry), 3857)
                ) * cos(radians(ST_Y(ST_Centroid(p.geometry::geometry))))
            ), 0) AS d
            FROM ugc_tracks p, ugc_tracks c
            WHERE p.id = ? AND c.id IN ({$placeholders})
        ", array_merge([$parentId], $copyIds));

        return (float) $row->d;
    }
}
```

- [ ] **Step 5: Scrivi il command (solo report per ora; `--execute` arriva nel Task 4)**

```php
<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Wm\WmPackage\Services\Models\UgcDuplicatesService;

class WmFixDuplicatedUgcTracksCommand extends Command
{
    protected $signature = 'wm:fix-duplicated-ugc-tracks
                            {--execute : Applica le modifiche; senza, produce solo il report}
                            {--app-id= : Limita ai record con questo app_id}
                            {--max-distance=1 : Oltre questa distanza in metri fra le geometrie il gruppo non viene toccato}';

    protected $description = 'Unisce le UgcTrack duplicate per properties.uuid nella più vecchia, archiviando le copie (oc:8718).';

    public function handle(UgcDuplicatesService $service): int
    {
        $execute = (bool) $this->option('execute');
        $appId = $this->option('app-id') !== null && $this->option('app-id') !== '' ? (int) $this->option('app-id') : null;
        $maxDistance = (float) $this->option('max-distance');

        $rows = [];
        foreach ($service->groups($appId) as $group) {
            $skip = $group['max_distance_m'] > $maxDistance;
            $esito = $skip ? 'da verificare' : ($execute ? 'unito' : 'da unire');
            $rows[] = [$group['uuid'], $group['parent_id'], implode(' ', $group['copy_ids']), sprintf('%.2f', $group['max_distance_m']), $esito];
        }

        $csv = $this->writeCsv($rows);
        $this->table(['uuid', 'padre', 'copie', 'distanza max (m)', 'esito'], $rows);
        $this->info(count($rows).' gruppi trovati'.($execute ? '.' : ' (report, nessuna scrittura).').' CSV: '.$csv);

        return self::SUCCESS;
    }

    private function writeCsv(array $rows): string
    {
        $path = storage_path('logs/duplicated-ugc-tracks-'.now()->format('Y-m-d').'.csv');
        $handle = fopen($path, 'w');
        fputcsv($handle, ['uuid', 'parent_id', 'copy_ids', 'max_distance_m', 'esito']);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        return $path;
    }
}
```

Registralo in `WmPackageServiceProvider::configurePackage()`: `use Wm\WmPackage\Commands\WmFixDuplicatedUgcTracksCommand;` e `WmFixDuplicatedUgcTracksCommand::class,` accanto a `WmCleanUgcTrackGeometryCommand::class`.

- [ ] **Step 6: Lancia i test e verifica che passino**

Run: lo stesso comando dello Step 3.
Expected: PASS.

- [ ] **Step 7: Commit (istruzione per il dev)**

```bash
git -C wm-package add database/migrations/zz_2026_10_07_000001_create_ugc_duplicates_archive_table.php.stub src/Services/Models/UgcDuplicatesService.php src/Commands/WmFixDuplicatedUgcTracksCommand.php src/WmPackageServiceProvider.php tests/Feature/WmFixDuplicatedUgcTracksCommandTest.php
git -C wm-package commit -m "feat(oc:8718): command di report delle UgcTrack duplicate e tabella di archivio"
```

---

### Task 4: `--execute` — unione di un gruppo

**Files:**
- Modify: `src/Services/Models/UgcDuplicatesService.php` (nuovi metodi)
- Modify: `src/Commands/WmFixDuplicatedUgcTracksCommand.php` (`handle()`)
- Test: `tests/Feature/WmFixDuplicatedUgcTracksCommandTest.php`

**Interfaces:**
- Consumes: `UgcMediaHashService::hashOf()`, `UgcDuplicatesService::groups()`.
- Produces: `UgcDuplicatesService::mergeGroup(array $group): void`; `mergedProperties(array $rowsInOrder, array $parentProperties): array`.

Ordine delle operazioni, tutto in una transazione per gruppo, tranne la cancellazione dei file:
1. properties del padre = unione di tutte le righe in ordine di data (`properties.updatedAt`, poi `updated_at`, poi `id`), con le `SERVER_PROPERTY_KEYS` del padre; colonna `name` = `properties.name`. `taxonomy_where` non si ricalcola: la geometria del padre non cambia, quindi il risultato sarebbe identico (deviazione voluta dall'overview, da riportare in notes).
2. media delle copie: se il contenuto (sha256) è già sul padre o su un'altra copia già spostata → da scartare; altrimenti `UPDATE media SET model_id = padre`.
3. riferimenti esterni verso le copie (FK su `ugc_tracks`): **non gestiti** in questo ciclo. Su `develop` di camminiditalia non ce ne sono; `validated_ec_track_ugc_track` (oc:8165) e `ugc_media` di osm2cai2 sono annotati in `docs/knowledge/` (vedi Task 5).
4. archivio: `INSERT … SELECT` dalla riga della copia, con `media_ids` = media spostati.
5. `DELETE FROM ugc_tracks WHERE id IN (copie)` in SQL (niente evento Eloquent, che cancellerebbe i file dei media).
6. dopo il commit: `$media->delete()` dei media scartati (rimuove anche il file).

- [ ] **Step 1: Scrivi i test che falliscono** (aggiungi al file del Task 3)

```php
it('con --execute unisce nel più vecchio, archivia e cancella le copie', function () {
    $p = dupTrack($this->user->id, $this->app_->id, 'e1', ['updatedAt' => '2026-06-16T14:00:58Z', 'form' => ['difficulty' => 'easy']], createdAt: '2026-06-16 14:06:14');
    $c = dupTrack($this->user->id, $this->app_->id, 'e1', ['updatedAt' => '2026-06-16T14:36:17Z', 'form' => ['difficulty' => 'medium'], 'id' => 999], createdAt: '2026-06-16 14:33:34');

    $this->artisan('wm:fix-duplicated-ugc-tracks', ['--execute' => true])->assertSuccessful();

    $parent = UgcTrack::find($p->id);
    expect(UgcTrack::find($c->id))->toBeNull()
        ->and($parent->properties['form']['difficulty'])->toBe('medium')
        ->and($parent->properties)->not->toHaveKey('id')
        ->and(DB::table('ugc_duplicates_archive')->where('original_id', $c->id)->value('reference_id'))->toBe($p->id);
});

it('conserva le chiavi del padre che le copie non hanno (es. layer_id)', function () {
    $p = dupTrack($this->user->id, $this->app_->id, 'e2', ['layer_id' => 10, 'chiave_del_progetto' => false]);
    dupTrack($this->user->id, $this->app_->id, 'e2');

    $this->artisan('wm:fix-duplicated-ugc-tracks', ['--execute' => true])->assertSuccessful();

    expect(UgcTrack::where('properties->uuid', 'e2')->count())->toBe(1)
        ->and(UgcTrack::find($p->id)->properties)->toMatchArray(['layer_id' => 10, 'chiave_del_progetto' => false]);
});

it('allinea la colonna name a properties.name', function () {
    $p = dupTrack($this->user->id, $this->app_->id, 'e3', ['name' => 'Vecchio', 'updatedAt' => '2026-01-01T00:00:00Z']);
    dupTrack($this->user->id, $this->app_->id, 'e3', ['name' => 'Nuovo', 'updatedAt' => '2026-01-02T00:00:00Z']);

    $this->artisan('wm:fix-duplicated-ugc-tracks', ['--execute' => true])->assertSuccessful();

    expect(DB::table('ugc_tracks')->where('id', $p->id)->value('name'))->toBe('Nuovo');
});

it('non tocca i gruppi da verificare', function () {
    $p = dupTrack($this->user->id, $this->app_->id, 'e4');
    $c = dupTrack($this->user->id, $this->app_->id, 'e4', [], 'MULTILINESTRING Z ((13 43.0001 10, 13.001 43.0011 10))');

    $this->artisan('wm:fix-duplicated-ugc-tracks', ['--execute' => true])->assertSuccessful();

    expect(UgcTrack::find($c->id))->not->toBeNull();
});

it('sposta i media delle copie sul padre scartando quelli con lo stesso contenuto', function () {
    config()->set('wm-package.shard_name', 'test_shard');
    \Illuminate\Support\Facades\Storage::fake('s3');
    \Illuminate\Support\Facades\Storage::fake('wmfe');
    \Illuminate\Support\Facades\Storage::fake('public');
    config()->set('medialibrary.disk_name', 'public');
    $p = dupTrack($this->user->id, $this->app_->id, 'e5');
    $c = dupTrack($this->user->id, $this->app_->id, 'e5');
    $file = fn ($s) => \Illuminate\Http\UploadedFile::fake()->image("i{$s}.jpg", 10 + ord($s) - ord('a'), 10);
    $p->addMedia($file('a'))->toMediaCollection('default');
    $c->addMedia($file('a'))->toMediaCollection('default');
    $c->addMedia($file('b'))->toMediaCollection('default');

    $this->artisan('wm:fix-duplicated-ugc-tracks', ['--execute' => true])->assertSuccessful();

    expect(UgcTrack::find($p->id)->getMedia('default'))->toHaveCount(2)
        ->and(DB::table('media')->where('model_id', $c->id)->where('model_type', $p->getMorphClass())->count())->toBe(0);
});

it('rilanciato dopo --execute non trova più nulla', function () {
    dupTrack($this->user->id, $this->app_->id, 'e7');
    dupTrack($this->user->id, $this->app_->id, 'e7');

    $this->artisan('wm:fix-duplicated-ugc-tracks', ['--execute' => true])->assertSuccessful();
    $this->artisan('wm:fix-duplicated-ugc-tracks', ['--execute' => true])
        ->expectsOutputToContain('0 gruppi')
        ->assertSuccessful();
});
```


- [ ] **Step 2: Lancia i test e verifica che falliscano**

Run: `docker run --rm --network camminiditalia_default -e DB_HOST=postgres-camminiditalia -v "$PWD/wm-package:/app" -w /app wm-phpfpm:8.4 vendor/bin/pest tests/Feature/WmFixDuplicatedUgcTracksCommandTest.php`
Expected: FAIL sui test di `--execute`.

- [ ] **Step 3: Implementa in `UgcDuplicatesService`**

```php
use Wm\WmPackage\Models\Media;
use Wm\WmPackage\Models\UgcTrack;

/**
 * Unisce le copie nel padre. Restituisce i media scartati come doppioni: vanno cancellati dal
 * chiamante dopo il commit, perché Spatie cancella i file subito e il rollback non li riporta.
 *
 * @return Media[]
 */
public function mergeGroup(array $group): array
{
    $parentId = $group['parent_id'];
    $copyIds = $group['copy_ids'];
    $morph = (new UgcTrack)->getMorphClass();

    return DB::transaction(function () use ($parentId, $copyIds, $morph) {
        $rows = DB::select('SELECT id, properties::text AS properties, updated_at FROM ugc_tracks WHERE id = ANY(?::bigint[]) ORDER BY id',
            ['{'.implode(',', array_merge([$parentId], $copyIds)).'}']);
        $byId = collect($rows)->keyBy('id')->map(fn ($r) => ['id' => (int) $r->id, 'properties' => json_decode($r->properties, true) ?? [], 'updated_at' => $r->updated_at]);
        $parentProps = $byId[$parentId]['properties'];

        $ordered = $byId->values()->sortBy([
            fn ($a, $b) => strcmp((string) ($a['properties']['updatedAt'] ?? ''), (string) ($b['properties']['updatedAt'] ?? '')),
            fn ($a, $b) => strcmp((string) $a['updated_at'], (string) $b['updated_at']),
            fn ($a, $b) => $a['id'] <=> $b['id'],
        ])->pluck('properties')->all();
        $merged = $this->mergedProperties($ordered, $parentProps);

        DB::update('UPDATE ugc_tracks SET properties = ?::jsonb, name = COALESCE(?, name), updated_at = now() WHERE id = ?',
            [json_encode($merged), $merged['name'] ?? null, $parentId]);

        [$movedByCopy, $discarded] = $this->moveMedia($parentId, $copyIds, $morph);

        foreach ($copyIds as $copyId) {
            DB::insert("
                INSERT INTO ugc_duplicates_archive (model_type, original_id, reference_id, uuid, user_id, app_id, properties, geometry, media_ids, original_created_at, original_updated_at, archived_at, created_at, updated_at)
                SELECT ?, id, ?, properties->>'uuid', user_id, app_id, properties::jsonb, geometry, ?::jsonb, created_at, updated_at, now(), now(), now()
                FROM ugc_tracks WHERE id = ?
            ", [$morph, $parentId, json_encode($movedByCopy[$copyId] ?? []), $copyId]);
        }

        DB::delete('DELETE FROM ugc_tracks WHERE id = ANY(?::bigint[])', ['{'.implode(',', $copyIds).'}']);

        return $discarded;
    });
}

/** Righe in ordine cronologico: ognuna va sopra la precedente; le chiavi del server restano del padre. */
public function mergedProperties(array $rowsInOrder, array $parentProperties): array
{
    $merged = array_merge(...array_map(fn ($p) => (array) $p, $rowsInOrder));
    foreach (self::SERVER_PROPERTY_KEYS as $key) {
        if (array_key_exists($key, $parentProperties)) {
            $merged[$key] = $parentProperties[$key];
        } else {
            unset($merged[$key]);
        }
    }

    return $merged;
}

/** @return array{0: array<int,int[]>, 1: Media[]} media spostati per copia, media scartati */
private function moveMedia(int $parentId, array $copyIds, string $morph): array
{
    $hashes = UgcMediaHashService::make();
    $known = Media::where('model_type', $morph)->where('model_id', $parentId)->get()
        ->mapWithKeys(fn (Media $m) => [$hashes->hashOf($m) => true])->all();

    $moved = [];
    $discarded = [];
    foreach ($copyIds as $copyId) {
        foreach (Media::where('model_type', $morph)->where('model_id', $copyId)->orderBy('id')->get() as $media) {
            $hash = $hashes->hashOf($media);
            if (isset($known[$hash])) {
                $discarded[] = $media;

                continue;
            }
            $known[$hash] = true;
            DB::update('UPDATE media SET model_id = ? WHERE id = ?', [$parentId, $media->id]);
            $moved[$copyId][] = $media->id;
        }
    }

    return [$moved, $discarded];
}

```


- [ ] **Step 4: Collega `--execute` nel command**

In `handle()`, dentro il ciclo, prima di aggiungere la riga:

```php
if ($execute && ! $skip) {
    $discarded = $service->mergeGroup($group);
    foreach ($discarded as $media) {
        // Dopo il commit: cancella riga e file del media doppione.
        $media->delete();
    }
}
```

Dopo la tabella, se `$execute`: `$this->warn('Fatto. Le copie sono in ugc_duplicates_archive; il rollback si fa dal backup del DB.');`. Se non `$execute`: `$this->warn('Report: prima di --execute fai il backup del DB.');`.

- [ ] **Step 5: Lancia i test**

Run: lo stesso comando dello Step 2, poi tutta la parte UGC: `vendor/bin/pest tests/Feature/WmFixDuplicatedUgcTracksCommandTest.php tests/Feature/UgcStoreUuidTest.php tests/Feature/UgcStoreImageDedupTest.php tests/Feature/UgcPoiController tests/Feature/UgcTrackGeometryCleanupTest.php tests/Feature/WmCleanUgcTrackGeometryCommandTest.php`.
Expected: PASS.

- [ ] **Step 6: PHPStan e Pint sul package**

Run (dal container del package): `vendor/bin/phpstan analyse` e `vendor/bin/pint src/Http/Controllers/Api src/Services/Models/UgcMediaHashService.php src/Services/Models/UgcDuplicatesService.php src/Commands/WmFixDuplicatedUgcTracksCommand.php tests/Feature/UgcStore*.php tests/Feature/WmFixDuplicatedUgcTracksCommandTest.php`. Dopo Pint controlla `git -C wm-package status` e scarta i file fuori dal lavoro.

- [ ] **Step 7: Commit (istruzione per il dev)**

```bash
git -C wm-package add src/Services/Models/UgcDuplicatesService.php src/Commands/WmFixDuplicatedUgcTracksCommand.php tests/Feature/WmFixDuplicatedUgcTracksCommandTest.php
git -C wm-package commit -m "feat(oc:8718): --execute unisce le UgcTrack duplicate e archivia le copie"
```

---

### Task 5: camminiditalia — bump, migration, report sul DB di sviluppo, documentazione

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-report-senza-csv) (niente CSV da leggere al passo 4 del rilascio)

**Files:**
- Modify: gitlink `wm-package`
- Create: `database/migrations/zz_2026_10_07_000001_create_ugc_duplicates_archive_table.php` (pubblicato dal package)
- Modify: `docs/features/8718-ugctrack-duplicati-stesso-uuid/notes.md` (procedura di rilascio ed esito del report)

- [ ] **Step 1: Migration**

```bash
docker exec laravel-camminiditalia php artisan wm-package:publish-missing-migrations --dry-run
docker exec laravel-camminiditalia php artisan wm-package:publish-missing-migrations
docker exec laravel-camminiditalia php artisan migrate
docker exec laravel-camminiditalia php artisan migrate --env=testing
```

Expected: viene pubblicato solo lo stub `create_ugc_duplicates_archive_table`; il secondo `--dry-run` esce 0.

- [ ] **Step 2: Report sul DB di sviluppo (sola lettura)**

```bash
docker exec laravel-camminiditalia php artisan wm:fix-duplicated-ugc-tracks
```

Expected: `15 gruppi trovati`, nessuno "da verificare" (`1529846e` ha distanza ~0). Riporta tabella ed esito in `notes.md`. **Non lanciare `--execute`**: si decide dopo la verifica, con il dev.

- [ ] **Step 3: Suite camminiditalia**

Run: `docker exec laravel-camminiditalia php artisan test`
Expected: tutti verdi (riferimento: 145 passati in oc:8092).

- [ ] **Step 4: Procedura di rilascio in `notes.md`**

```markdown
## Rilascio
1. Deploy di wm-package e bump del submodule.
2. `php artisan wm-package:publish-missing-migrations --dry-run` → `migrate`.
3. Backup del DB.
4. `php artisan wm:fix-duplicated-ugc-tracks` → leggere il CSV in `storage/logs/`.
5. Se il report torna: `php artisan wm:fix-duplicated-ugc-tracks --execute`.
6. Controllo: il comando rilanciato riporta `0 gruppi`.
```

- [ ] **Step 5: Commit (istruzione per il dev, dopo il merge del package)**

```bash
git -C /Users/bongiu/Documents/camminiditalia add wm-package database/migrations docs/features/8718-ugctrack-duplicati-stesso-uuid
git -C /Users/bongiu/Documents/camminiditalia commit -m "fix(oc:8718): bump wm-package, migration archivio duplicati UGC"
```
