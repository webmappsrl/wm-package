> Ticket: oc:8564

# Fix logo layer non aggiorna config Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Far sì che l'aggiunta o la rimozione di un media di **qualsiasi** collection (logo, immagine, future) di un `Layer` rigeneri sempre la config pubblica dell'app (`config.json`, `MAP->layers[].logo_image`/`.feature_image`), tramite un observer dedicato su `Media` che dispatcha `UpdateAppConfigJob` con delay.

**Architecture:** Nuovo observer Eloquent (`LayerMediaObserver`, rinominato da `LayerLogoMediaObserver` dopo la generalizzazione del Task 5) registrato in aggiunta a `MediaObserver` esistente sul modello `Media` (Eloquent supporta più observer sullo stesso model — stesso pattern già in uso per `Layer::observe()`). Filtra solo sul modello risolto (`$media->model instanceof Layer`), mai su stringhe di morph-map grezze; nessun filtro sulla collection (generalizzato — vedi Task 5). Dispatch con delay di 10s (`->delay(now()->addSeconds(10))->onQueue('default')`), stesso pattern di `LayerObserver::updateAppConf()` nel package, per lasciare tempo alle conversion (thumbnail) di completarsi. Nessuna modifica ai percorsi esistenti (`LayerObserver`, `RecalculateLayerAttributesJob`).

**Tech Stack:** Laravel 11/12, Spatie MediaLibrary (`spatie/laravel-medialibrary`), Pest (test), PostgreSQL/PostGIS.

**Spec:** `wm-package/docs/features/8564-fix-logo-layer-non-si-aggiorna-dopo-upload/overview.md`

## Global Constraints

- Fix interamente in `wm-package` (submodule), generico — non camminiditalia-specifico.
- **Aggiornato al Task 5**: delay di 10s sul dispatch di `UpdateAppConfigJob`, applicato a
  qualsiasi collection (non solo `'logo'`) — `GeometryModel::registerMediaConversions()`
  registra conversion per l'intero modello, e `feature_image` (`'default'`) le consuma via
  `MediaService::getThumbnailUrl()`.
- **Aggiornato dopo il riallineamento a `develop`**: nessuna deduplica scritta da noi —
  `UpdateAppConfigJob` implementa già `ShouldBeUnique` (`uniqueFor() = 600`) grazie a oc:8488,
  già mergiato in `develop`. Il nostro observer la eredita senza modifiche. Non aggiungere
  `Cache::lock` o logica di deduplica propria: sarebbe ridondante.
- **Aggiornato al Task 5**: filtro solo sul modello risolto a `Layer` — nessun filtro sulla
  collection (generalizzato a qualsiasi collection del Layer).
- Non toccare `wm-package/src/Observers/LayerObserver.php`, `app/Jobs/RecalculateLayerAttributesJob.php`, `app/Observers/LayerObserver.php` — restano invariati.
- Nessun backfill per layer già in questo stato.
- Nessun file in `app/` di camminiditalia va modificato — solo bump del puntatore submodule a fine piano.

---

## File Structure

- `wm-package/src/Observers/LayerMediaObserver.php` (rinominato da `LayerLogoMediaObserver.php` al Task 5) — observer Eloquent dedicato su `Media`, dispatcha `UpdateAppConfigJob` (con delay) su `created`/`deleted` filtrato solo su modello `Layer`, qualsiasi collection.
- `wm-package/src/Models/Media.php` (modifica) — registra l'observer in `booted()`, accanto a `MediaObserver`.
- `wm-package/tests/Feature/LayerMediaObserverTest.php` (rinominato da `LayerLogoMediaObserverTest.php` al Task 5) — copertura Pest: add/remove su `'logo'` e `'default'` con delay, più il caso negativo "altro modello".

---

### Task 1: Test che fallisce — dispatch su aggiunta del logo

**Files:**
- Test: `wm-package/tests/Feature/LayerLogoMediaObserverTest.php`

**Interfaces:**
- Consumes: `Wm\WmPackage\Models\Layer::addMedia()` (Spatie `HasMedia`, già esistente), `Wm\WmPackage\Jobs\UpdateAppConfigJob` (già esistente, costruttore `__construct(public int $appId)`).
- Produces: helper locale `makeLayerForMedia(): Layer` (crea `App` + `Layer` via factory, `createQuietly()`), riusato dai task successivi nello stesso file.

- [ ] **Step 1: Scrivi il test che fallisce**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;

uses(TestCase::class, DatabaseTransactions::class);

function makeLayerForMedia(): Layer
{
    App::factory()->createQuietly();

    return Layer::factory()->createQuietly();
}

it('dispatches UpdateAppConfigJob when a logo media is added to a Layer', function () {
    Storage::fake('wmfe');
    $layer = makeLayerForMedia();

    Bus::fake();

    $layer->addMedia(UploadedFile::fake()->image('logo.png', 512, 512))
        ->toMediaCollection('logo');

    Bus::assertDispatched(UpdateAppConfigJob::class, function (UpdateAppConfigJob $job) use ($layer) {
        return $job->appId === $layer->app_id;
    });
});
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `docker exec laravel-camminiditalia php artisan test --filter="dispatches UpdateAppConfigJob when a logo media is added to a Layer"`

Se la suite del repo principale non raggiunge i test di `wm-package/tests/`, esegui invece dentro `wm-package`:

Run: `docker exec laravel-camminiditalia sh -c "cd wm-package && vendor/bin/pest tests/Feature/LayerLogoMediaObserverTest.php --filter='dispatches UpdateAppConfigJob when a logo media is added to a Layer'"`

Expected: FAIL — `Bus::assertDispatched` non trova nessun `UpdateAppConfigJob` dispatchato (nessun observer lo dispatcha ancora).

Se `vendor/bin/pest` non è disponibile in `wm-package` (debito noto — `composer install` su `wm-package` a volte non completa in locale, vedi `wm-package/CLAUDE.md`), verifica con:

Run: `docker exec laravel-camminiditalia sh -c "cd wm-package && composer install --no-interaction 2>&1 | tail -20"`

Se fallisce per credenziali `laravel/nova` scadute, documenta il blocco in `notes.md` (sezione "Bug trovati") e prosegui i task successivi scrivendo comunque test e implementazione — la verifica reale si sposta sulla CI di `wm-package` al push, pattern già usato in altri cicli di questo repo.

---

### Task 2: Observer dedicato — implementazione minima per l'aggiunta

**Files:**
- Create: `wm-package/src/Observers/LayerLogoMediaObserver.php`
- Modify: `wm-package/src/Models/Media.php:42-44`

**Interfaces:**
- Consumes: `Wm\WmPackage\Models\Layer` (per l'`instanceof` check), `Wm\WmPackage\Models\Media` (parametro dei metodi observer), `Wm\WmPackage\Jobs\UpdateAppConfigJob::dispatch(int $appId)`.
- Produces: `LayerLogoMediaObserver::created(Media $media): void`, `LayerLogoMediaObserver::deleted(Media $media): void` (quest'ultimo implementato nel Task 4, ma la firma è definita qui per intero — il corpo di `deleted()` esiste già da questo task, vedi Step 3).

- [ ] **Step 1: Crea l'observer dedicato**

```php
<?php

namespace Wm\WmPackage\Observers;

use Wm\WmPackage\Jobs\UpdateAppConfigJob;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\Media;

/**
 * Nessuno dei trigger esistenti su Layer (wasChanged('properties') nel
 * package, calculatedValuesAreUnchanged() in camminiditalia) reagisce a un
 * cambio isolato del solo media 'logo' — vedi oc:8564. Observer dedicato
 * (non dentro MediaObserver, già globale su ogni media del sistema) per
 * tenere questo rischio isolato e testabile separatamente.
 */
class LayerLogoMediaObserver
{
    private const LOGO_COLLECTION = 'logo';

    public function created(Media $media): void
    {
        $this->dispatchIfLayerLogo($media);
    }

    public function deleted(Media $media): void
    {
        $this->dispatchIfLayerLogo($media);
    }

    private function dispatchIfLayerLogo(Media $media): void
    {
        if ($media->collection_name !== self::LOGO_COLLECTION) {
            return;
        }

        $model = $media->model;

        if (! $model instanceof Layer) {
            return;
        }

        UpdateAppConfigJob::dispatch($model->app_id);
    }
}
```

- [ ] **Step 2: Registra l'observer sul modello Media**

In `wm-package/src/Models/Media.php`, sostituisci:

```php
    protected static function booted()
    {
        Media::observe(MediaObserver::class);
    }
```

con:

```php
    protected static function booted()
    {
        Media::observe(MediaObserver::class);
        Media::observe(LayerLogoMediaObserver::class);
    }
```

E aggiungi l'import in cima al file, accanto agli altri `use Wm\WmPackage\Observers\...`:

```php
use Wm\WmPackage\Observers\LayerLogoMediaObserver;
```

- [ ] **Step 3: Esegui il test del Task 1 e verifica che passi**

Run: `docker exec laravel-camminiditalia sh -c "cd wm-package && vendor/bin/pest tests/Feature/LayerLogoMediaObserverTest.php --filter='dispatches UpdateAppConfigJob when a logo media is added to a Layer'"`

Expected: PASS

- [ ] **Step 4: Commit**

```bash
git -C wm-package add src/Observers/LayerLogoMediaObserver.php src/Models/Media.php tests/Feature/LayerLogoMediaObserverTest.php
git -C wm-package commit -m "feat(oc:8564): dispatch UpdateAppConfigJob on Layer logo media add"
```

---

### Task 3: Test + verifica — dispatch su rimozione del logo

**Files:**
- Modify: `wm-package/tests/Feature/LayerLogoMediaObserverTest.php`

**Interfaces:**
- Consumes: `LayerLogoMediaObserver::deleted()` (già implementato nel Task 2, Step 1).

- [ ] **Step 1: Aggiungi il test per la rimozione**

Aggiungi in coda al file `wm-package/tests/Feature/LayerLogoMediaObserverTest.php`:

```php
it('dispatches UpdateAppConfigJob when a logo media is deleted from a Layer', function () {
    Storage::fake('wmfe');
    $layer = makeLayerForMedia();

    $media = $layer->addMedia(UploadedFile::fake()->image('logo.png', 512, 512))
        ->toMediaCollection('logo');

    Bus::fake();

    $media->delete();

    Bus::assertDispatched(UpdateAppConfigJob::class, function (UpdateAppConfigJob $job) use ($layer) {
        return $job->appId === $layer->app_id;
    });
});
```

- [ ] **Step 2: Esegui il test e verifica che passi**

Run: `docker exec laravel-camminiditalia sh -c "cd wm-package && vendor/bin/pest tests/Feature/LayerLogoMediaObserverTest.php --filter='dispatches UpdateAppConfigJob when a logo media is deleted from a Layer'"`

Expected: PASS (l'implementazione del Task 2 copre già `deleted()` — questo task verifica solo che il comportamento sia corretto anche per la rimozione, dato che `Media::delete()` non ha un evento Spatie dedicato ma passa comunque dall'evento Eloquent `deleted`).

Se FALLISCE, verifica che `clearMediaCollection`/`Media::delete()` in questa versione di Spatie MediaLibrary sparino effettivamente l'evento Eloquent `deleted` (non un soft-delete o un bypass `forceDelete` diverso) — ispeziona `wm-package/vendor/spatie/laravel-medialibrary/src/MediaCollections/Models/Media.php` per eventuali override di `delete()`.

- [ ] **Step 3: Commit**

```bash
git -C wm-package add tests/Feature/LayerLogoMediaObserverTest.php
git -C wm-package commit -m "test(oc:8564): cover UpdateAppConfigJob dispatch on Layer logo media removal"
```

---

### Task 4: Test negativi — filtro su collection e modello

**Files:**
- Modify: `wm-package/tests/Feature/LayerLogoMediaObserverTest.php`

**Interfaces:**
- Consumes: `Wm\WmPackage\Models\EcPoi::factory()` (esistente, `createQuietly()`), stesso pattern di `EcPoiFactory` già presente in `wm-package/database/factories/EcPoiFactory.php`.

- [ ] **Step 1: Aggiungi i due test negativi**

Aggiungi in coda al file `wm-package/tests/Feature/LayerLogoMediaObserverTest.php` (aggiungi anche l'import `use Wm\WmPackage\Models\EcPoi;` in cima al file):

```php
it('does not dispatch UpdateAppConfigJob when media is added to a different Layer collection', function () {
    Storage::fake('wmfe');
    $layer = makeLayerForMedia();

    Bus::fake();

    $layer->addMedia(UploadedFile::fake()->image('cover.png', 512, 512))
        ->toMediaCollection('default');

    Bus::assertNotDispatched(UpdateAppConfigJob::class);
});

it('does not dispatch UpdateAppConfigJob when a logo-named media is added to a non-Layer model', function () {
    Storage::fake('wmfe');
    App::factory()->createQuietly();
    $poi = EcPoi::factory()->createQuietly();

    Bus::fake();

    $poi->addMedia(UploadedFile::fake()->image('poi-logo.png', 512, 512))
        ->toMediaCollection('logo');

    Bus::assertNotDispatched(UpdateAppConfigJob::class);
});
```

- [ ] **Step 2: Esegui l'intera suite del file e verifica che tutti i test passino**

Run: `docker exec laravel-camminiditalia sh -c "cd wm-package && vendor/bin/pest tests/Feature/LayerLogoMediaObserverTest.php"`

Expected: PASS — 4 test verdi (add, remove, altra collection, altro modello).

- [ ] **Step 3: Commit**

```bash
git -C wm-package add tests/Feature/LayerLogoMediaObserverTest.php
git -C wm-package commit -m "test(oc:8564): verify LayerLogoMediaObserver ignores other collections and models"
```

---

### Task 5: Generalizza l'observer a qualsiasi collection del Layer, con delay

> ⚠️ Deviazione dal piano rispetto ai Task 1-4: emerso da una richiesta del dev dopo l'implementazione iniziale (scoperto che `'default'`/Immagine ha lo stesso bug e in più necessita del delay per le conversion). Vedi `notes.md` per il dettaglio della decisione.

**Files:**
- Rename + Modify: `wm-package/src/Observers/LayerLogoMediaObserver.php` → `wm-package/src/Observers/LayerMediaObserver.php`
- Modify: `wm-package/src/Models/Media.php`
- Rename + Modify: `wm-package/tests/Feature/LayerLogoMediaObserverTest.php` → `wm-package/tests/Feature/LayerMediaObserverTest.php`

**Interfaces:**
- Consumes: `Wm\WmPackage\Jobs\UpdateAppConfigJob::dispatch(int $appId)` (esistente), stesso pattern di delay già usato in `wm-package/src/Observers/LayerObserver.php::updateAppConf()` (`->delay(now()->addSeconds(10))->onQueue('default')`).
- Produces: `LayerMediaObserver::created(Media $media): void`, `LayerMediaObserver::deleted(Media $media): void` — stessa firma di prima, filtro ridotto alla sola condizione sul modello.

- [ ] **Step 1: Rinomina i file**

```bash
git -C wm-package mv src/Observers/LayerLogoMediaObserver.php src/Observers/LayerMediaObserver.php
git -C wm-package mv tests/Feature/LayerLogoMediaObserverTest.php tests/Feature/LayerMediaObserverTest.php
```

(Se il piano viene eseguito senza commit intermedi, un semplice `mv` sul filesystem è equivalente — il commit avviene comunque solo dopo approvazione esplicita, vedi override del workflow.)

- [ ] **Step 2: Riscrivi l'observer (rimuovi il filtro sulla collection, aggiungi il delay)**

Sostituisci il contenuto di `wm-package/src/Observers/LayerMediaObserver.php` con:

```php
<?php

namespace Wm\WmPackage\Observers;

use Wm\WmPackage\Jobs\UpdateAppConfigJob;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\Media;

/**
 * Nessuno dei trigger esistenti su Layer (wasChanged('properties') nel
 * package, calculatedValuesAreUnchanged() nei consumer) reagisce a un
 * cambio isolato di un qualsiasi media collegato a un Layer — vedi oc:8564.
 * Observer dedicato (non dentro MediaObserver, già globale su ogni media
 * del sistema) per tenere questo rischio isolato e testabile
 * separatamente. Delay di 10s coerente con LayerObserver::updateAppConf(),
 * per lasciare il tempo alle conversion (thumbnail, registrate per
 * l'intero modello da GeometryModel::registerMediaConversions()) di
 * essere generate prima di rigenerare la config pubblica.
 */
class LayerMediaObserver
{
    private const DISPATCH_DELAY_SECONDS = 10;

    public function created(Media $media): void
    {
        $this->dispatchIfLayerMedia($media);
    }

    public function deleted(Media $media): void
    {
        $this->dispatchIfLayerMedia($media);
    }

    private function dispatchIfLayerMedia(Media $media): void
    {
        $model = $media->model;

        if (! $model instanceof Layer) {
            return;
        }

        UpdateAppConfigJob::dispatch($model->app_id)
            ->delay(now()->addSeconds(self::DISPATCH_DELAY_SECONDS))
            ->onQueue('default');
    }
}
```

- [ ] **Step 3: Aggiorna la registrazione in Media.php**

In `wm-package/src/Models/Media.php`, sostituisci l'import `use Wm\WmPackage\Observers\LayerLogoMediaObserver;` con `use Wm\WmPackage\Observers\LayerMediaObserver;`, e in `booted()` sostituisci `Media::observe(LayerLogoMediaObserver::class);` con `Media::observe(LayerMediaObserver::class);`.

- [ ] **Step 4: Riscrivi il file di test**

Sostituisci il contenuto di `wm-package/tests/Feature/LayerMediaObserverTest.php` con:

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\Layer;

uses(TestCase::class, DatabaseTransactions::class);

function makeLayerForLogoMediaObserverTest(): Layer
{
    App::factory()->createQuietly();

    return Layer::factory()->createQuietly();
}

it('dispatches UpdateAppConfigJob with a delay when a logo media is added to a Layer', function () {
    Storage::fake('wmfe');
    $layer = makeLayerForLogoMediaObserverTest();

    Bus::fake();

    $layer->addMedia(UploadedFile::fake()->image('logo.png', 512, 512))
        ->toMediaCollection('logo');

    Bus::assertDispatched(UpdateAppConfigJob::class, function (UpdateAppConfigJob $job) use ($layer) {
        return $job->appId === $layer->app_id && $job->delay !== null;
    });
});

it('dispatches UpdateAppConfigJob with a delay when a logo media is deleted from a Layer', function () {
    Storage::fake('wmfe');
    $layer = makeLayerForLogoMediaObserverTest();

    $media = $layer->addMedia(UploadedFile::fake()->image('logo.png', 512, 512))
        ->toMediaCollection('logo');

    Bus::fake();

    $media->delete();

    Bus::assertDispatched(UpdateAppConfigJob::class, function (UpdateAppConfigJob $job) use ($layer) {
        return $job->appId === $layer->app_id && $job->delay !== null;
    });
});

it('dispatches UpdateAppConfigJob with a delay when a media is added to the default Layer collection', function () {
    Storage::fake('wmfe');
    $layer = makeLayerForLogoMediaObserverTest();

    Bus::fake();

    $layer->addMedia(UploadedFile::fake()->image('cover.png', 512, 512))
        ->toMediaCollection('default');

    Bus::assertDispatched(UpdateAppConfigJob::class, function (UpdateAppConfigJob $job) use ($layer) {
        return $job->appId === $layer->app_id && $job->delay !== null;
    });
});

it('dispatches UpdateAppConfigJob with a delay when a media is deleted from the default Layer collection', function () {
    Storage::fake('wmfe');
    $layer = makeLayerForLogoMediaObserverTest();

    $media = $layer->addMedia(UploadedFile::fake()->image('cover.png', 512, 512))
        ->toMediaCollection('default');

    Bus::fake();

    $media->delete();

    Bus::assertDispatched(UpdateAppConfigJob::class, function (UpdateAppConfigJob $job) use ($layer) {
        return $job->appId === $layer->app_id && $job->delay !== null;
    });
});

it('does not dispatch UpdateAppConfigJob when a logo-named media is added to a non-Layer model', function () {
    Storage::fake('wmfe');
    App::factory()->createQuietly();
    $poi = EcPoi::factory()->createQuietly();

    Bus::fake();

    $poi->addMedia(UploadedFile::fake()->image('poi-logo.png', 512, 512))
        ->toMediaCollection('logo');

    Bus::assertNotDispatched(UpdateAppConfigJob::class);
});
```

Nota: il test "altra collection" del Task 4 (che verificava che `'default'` NON dispatchasse) viene rimosso perché non è più corretto — ora qualsiasi collection deve dispatchare. Il test "altro modello" resta invariato (unica condizione di filtro rimasta).

- [ ] **Step 5: Esegui l'intera suite del file e verifica che tutti i test passino**

Run: `docker exec laravel-camminiditalia sh -c "vendor/bin/pest wm-package/tests/Feature/LayerMediaObserverTest.php"`

Expected: PASS — 5 test verdi (add logo con delay, remove logo con delay, add default con delay, remove default con delay, altro modello nessun dispatch).

- [ ] **Step 6: Commit**

```bash
git -C wm-package add src/Observers/LayerMediaObserver.php src/Models/Media.php tests/Feature/LayerMediaObserverTest.php
git -C wm-package rm src/Observers/LayerLogoMediaObserver.php tests/Feature/LayerLogoMediaObserverTest.php 2>/dev/null || true
git -C wm-package commit -m "feat(oc:8564): generalize LayerMediaObserver to all Layer media collections with delay"
```

---

### Task 6: Fix del lock univoco di UpdateAppConfigJob su Postgres (bug bloccante scoperto durante il test)

> ⚠️ Deviazione dal piano: bug pre-esistente scoperto testando dal vivo su Nova dopo il
> riallineamento a `develop` (non introdotto da questo ticket, viene da oc:8488), ma blocca
> anche il dispatch del nostro `LayerMediaObserver` — necessario per sbloccare oc:8564. Vedi
> `notes.md` per il dettaglio completo della diagnosi.

**Files:**
- Modify: `wm-package/src/Jobs/UpdateAppConfigJob.php`

**Interfaces:**
- Consumes: `Illuminate\Support\Facades\Cache` (già disponibile, nessuna nuova dipendenza), store `'redis'` già configurato in `config/cache.php` (verificato).
- Produces: `UpdateAppConfigJob::uniqueVia(): Illuminate\Contracts\Cache\Repository` — metodo consultato da Laravel (`Illuminate\Bus\UniqueLock`) se presente sul job, al posto dello store di cache di default.

**Causa**: `DatabaseLock::acquire()` (Laravel, `vendor/laravel/framework/src/Illuminate/Cache/DatabaseLock.php`, righe ~71-84) prova un `INSERT`; se fallisce per chiave duplicata, cattura la `QueryException` e ripiega su un `UPDATE` nello stesso blocco try/catch. Su PostgreSQL, una query fallita dentro una transazione blocca (`25P02`) tutte le query successive nella stessa transazione finché non arriva un `ROLLBACK` — quindi anche l'`UPDATE` di fallback fallisce, con lo stesso errore generico che nasconde la causa vera. Nova avvolge ogni salvataggio di risorsa in una transazione (`ResourceUpdateController.php`), quindi qualunque dispatch di `UpdateAppConfigJob` (da `LayerObserver::updateAppConf()`, dal nostro `LayerMediaObserver`, o da uno qualsiasi degli altri 5 chiamanti) va in crash se la riga di lock per quell'`app_id` esiste già in `cache_locks`.

- [ ] **Step 1: Modifica il job**

In `wm-package/src/Jobs/UpdateAppConfigJob.php`, aggiungi gli import:

```php
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
```

E aggiungi il metodo (accanto a `uniqueId()`/`uniqueFor()`):

```php
    public function uniqueVia(): Repository
    {
        return Cache::store('redis');
    }
```

- [ ] **Step 2: Verifica che il lock non tocchi più la tabella Postgres**

Run:
```bash
docker exec laravel-camminiditalia php artisan tinker --execute="
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;
DB::transaction(function () {
    UpdateAppConfigJob::dispatch(999999);
    UpdateAppConfigJob::dispatch(999999);
    echo 'OK: nessuna eccezione dentro la transazione' . PHP_EOL;
});
echo 'Righe cache_locks per 999999: ' . DB::table('cache_locks')->where('key', 'like', '%999999%')->count() . PHP_EOL;
"
```

Expected: `OK: nessuna eccezione dentro la transazione` e `Righe cache_locks per 999999: 0` (il lock ora passa da Redis, non tocca la tabella).

- [ ] **Step 3: Rilancia la suite e PHPStan**

Run: `docker exec laravel-camminiditalia sh -c "vendor/bin/pest wm-package/tests/Feature/LayerMediaObserverTest.php"`

Expected: PASS — 5 test verdi, nessuna regressione.

Run: `docker exec laravel-camminiditalia vendor/bin/phpstan analyse`

Expected: nessun errore.

- [ ] **Step 4: Commit**

```bash
git -C wm-package add src/Jobs/UpdateAppConfigJob.php
git -C wm-package commit -m "fix(oc:8564): use redis lock for UpdateAppConfigJob uniqueness to avoid Postgres transaction abort"
```

---

### Task 7: Verifica manuale end-to-end su Nova

**Files:** nessuno (task manuale, nessun file da modificare).

**Interfaces:** nessuna — checklist operativa per il dev.

- [ ] **Step 1: Avvia l'ambiente e apri un Layer esistente in Nova**

```bash
docker exec laravel-camminiditalia php artisan horizon:status
```

Se Horizon non è attivo: `composer run dev` (vedi CLAUDE.md del repo principale).

Apri `/nova/resources/layers/{id}` (edit) per un Layer di test.

- [ ] **Step 2: Carica un logo**

Carica un'immagine nel campo "Logo" e premi il pulsante di salvataggio del form.

- [ ] **Step 3: Verifica in Horizon che UpdateAppConfigJob sia stato dispatchato con delay**

Apri la dashboard Horizon (`/horizon`), cerca `Wm\WmPackage\Jobs\UpdateAppConfigJob` nei job recenti — deve comparire circa **10 secondi dopo** il salvataggio (delay applicato al Task 5), non immediatamente.

- [ ] **Step 4: Verifica il config.json pubblicato**

Recupera l'URL del `config.json` dell'app (vedi `AppConfigService::writeAppConfigOnAws()` per il path S3/CDN usato in questo ambiente) e verifica che `MAP.layers[].logo_image` per il layer di test punti al file appena caricato.

- [ ] **Step 5: Ripeti per la rimozione del logo**

Rimuovi il logo dal campo "Logo" e salva. Verifica di nuovo in Horizon che `UpdateAppConfigJob` scatti (con delay), e che il `config.json` aggiornato non contenga più `logo_image` per quel layer (o lo mostri `null`, coerente con l'accessor `getLogoImageAttribute()` che ritorna `null` senza media).

- [ ] **Step 6: Ripeti per il campo Immagine**

Carica un'immagine nel campo "Image" (collection `'default'`) e salva. Verifica in Horizon il dispatch con delay, e verifica che `MAP.layers[].feature_image` nel `config.json` rifletta la nuova thumbnail (non solo che l'URL cambi, ma che la thumbnail sia effettivamente generata — apri l'URL e conferma che l'immagine è quella corretta, ritagliata). Ripeti poi la rimozione, come al Step 5.

- [ ] **Step 7: Verifica il caso di sostituzione (doppio dispatch atteso, innocuo)**

Sostituisci un logo già presente con uno nuovo (upload diretto su un campo già valorizzato). Verifica in Horizon che compaiano **due** dispatch di `UpdateAppConfigJob` (delete del vecchio + create del nuovo) — comportamento accettato per design (vedi Rischi in `overview.md`), non un bug: conferma solo che entrambi completino senza errori.

- [ ] **Step 8: Documenta l'esito**

Se tutti i passaggi precedenti sono verdi, annota in `wm-package/docs/features/8564-fix-logo-layer-non-si-aggiorna-dopo-upload/notes.md` (sezione "Decisioni" o "Follow-up") che la verifica E2E manuale è stata eseguita con esito positivo, con data, specificando che copre sia Logo sia Immagine.

Se qualcosa non torna, non proseguire al Task 7: torna al Task 5 e correggi prima di continuare.

---

### Task 8: Bump del puntatore submodule in camminiditalia

**Files:**
- Modify (repo principale `camminiditalia`, non `wm-package`): gitlink del submodule `wm-package`.

**Interfaces:** nessuna — solo comandi git, da eseguire manualmente dal dev dopo aver pushato i commit di `wm-package` (nessun commit/push automatico previsto da questo piano).

- [ ] **Step 1: Push dei commit di wm-package**

```bash
git -C wm-package push origin <nome-branch-wm-package>
```

- [ ] **Step 2: Aggiorna il puntatore submodule nel repo principale**

```bash
cd /Users/peco/Documents/BackEnd/camminiditalia
git add wm-package
git commit -m "fix(oc:8564): bump wm-package (dispatch UpdateAppConfigJob on Layer logo media change)"
```

- [ ] **Step 3: Verifica che il puntatore sia corretto**

```bash
git -C wm-package rev-parse HEAD
git ls-tree HEAD wm-package
```

I due hash devono coincidere (il gitlink in camminiditalia deve puntare esattamente all'ultimo commit pushato di wm-package).

---

## Self-Review

**1. Copertura spec (requisiti overview.md, aggiornata dopo la generalizzazione):**
- Requisito 1 (dispatch su add, qualsiasi collection) → Task 1-2 (add logo), Task 5 (generalizzazione a tutte le collection).
- Requisito 2 (dispatch su remove, qualsiasi collection) → Task 3 (remove logo), Task 5.
- Requisito 3 (delay uniforme 10s, correzione sulla premessa "nessuna conversion") → Task 5 Step 2.
- Requisito 4 (fix generico in wm-package) → tutto il piano vive in `wm-package/`, nessun file `app/`.
- Requisito 5 (test automatico con caso negativo "altro modello") → Task 1, 3, 4 (versione iniziale), Task 5 (riscritti per il delay e la generalizzazione, rimosso il test "altra collection" ormai non più valido).
- Requisito 6 (verifica manuale E2E su Logo e Immagine) → Task 6.
- Decisione "observer dedicato, non MediaObserver" → Task 2 (nuova classe, poi rinominata `LayerMediaObserver` al Task 5), resta separata.
- Decisione "nessuna deduplica" → nessun `Cache::lock` in nessun task, coerente anche dopo la generalizzazione.
- Decisione "filtro solo su modello risolto a Layer" (non più su collection) → Task 5 Step 2, Task 5 Step 4 (unico test negativo rimasto: altro modello).
- Bump submodule → Task 7.

**2. Placeholder scan:** nessun "TBD"/"implement later" nei task — ogni step ha codice completo o comandi shell espliciti.

**3. Coerenza dei tipi:** `UpdateAppConfigJob::__construct(public int $appId)` (esistente, verificato in `wm-package/src/Jobs/UpdateAppConfigJob.php`) — il test asserisce `$job->appId === $layer->app_id`, coerente in tutti i task. `LayerMediaObserver::created()`/`deleted()` (rinominato da `LayerLogoMediaObserver` al Task 5) mantengono la stessa firma `(Media $media): void` in tutti i riferimenti.
