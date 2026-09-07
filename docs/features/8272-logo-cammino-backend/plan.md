> Ticket: oc:8272

# Logo cammino: backend (Layer model, Nova, API) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Aggiungere una collection Spatie Media Library `logo` al modello `Layer` (wm-package), esporla in Nova con validazione formato/proporzione, ed esporla come accessor `logo_image` disponibile ovunque il Layer venga serializzato.

**Architecture:** `Layer` eredita già `HasMedia`/`InteractsWithMedia` da `GeometryModel` (via `Polygon`) — nessuna nuova interfaccia da implementare. Si estende `registerMediaCollections()` (override + `parent::registerMediaCollections()`) per aggiungere la collection `logo`, si aggiunge un campo Nova `Images::make` con `singleMediaRules()`, e un accessor Eloquent `getLogoImageAttribute()` aggiunto a `$appends`.

**Tech Stack:** Laravel, Laravel Nova, Spatie Media Library (`Ebess\AdvancedNovaMediaLibrary\Fields\Images`), Pest (test del package, `Wm\WmPackage\Tests\TestCase`).

## Global Constraints

- Formati accettati: **PNG + WebP** — SVG rimosso dopo review formale: il campo Nova `Images::make()` (Ebess) applica sempre una regola base hardcoded `image` non sovrascrivibile via `singleMediaRules()`, e la versione Laravel installata esclude SVG dalla regola `image` implicita per sicurezza — ogni upload SVG viene sempre rifiutato indipendentemente dalla forma.
- Validazione: **solo proporzione quadrata `ratio=1/1`**, nessuna soglia minima/massima di pixel — fuori scope per mancanza di specifiche cliente.
- Usare **sempre `singleMediaRules()`**, mai `->rules()` sul campo Nova `Images::make` — trappola nota (oc:8247): `->rules()` valida l'intero array della collection (mix ID esistenti + nuovi file) e fallisce sempre con rule tipo `dimensions`/`mimes` perché un array non è mai un'istanza `File`.
- `logo_image` va in `$appends` del modello (globale, non solo nel controller API) — costo N+1 query accettato consapevolmente dal dev.
- Nessuna migration DB — la media library usa la tabella polimorfica `media` già esistente.
- Nessuna verifica manuale Nova richiesta come criterio di completamento — il test Feature è sufficiente.
- **NO commit o branch automatici** — ogni step "Commit" in questo piano è un'istruzione testuale per lo sviluppatore/reviewer umano, non un'azione da eseguire autonomamente durante l'esecuzione del piano.

---

### Task 1: Collection media `logo` su Layer

**Files:**
- Modify: `src/Models/Layer.php`
- Test: `tests/Feature/LayerLogoMediaTest.php` (nuovo file, creato in questo task e completato nei task successivi)

**Interfaces:**
- Consumes: `GeometryModel::registerMediaCollections()` (già esistente, registra la collection `'default'` — vedi `src/Models/Abstracts/GeometryModel.php:281-286`)
- Produces: `Layer` ha una collection media `'logo'` (single file) utilizzabile da `$layer->addMedia(...)->toMediaCollection('logo')` e `$layer->getFirstMediaUrl('logo')`

- [ ] **Step 1: Scrivi il test che verifica l'esistenza della collection `logo`**

Crea il file `tests/Feature/LayerLogoMediaTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;

uses(TestCase::class, DatabaseTransactions::class);

function makeLayerForMedia(): Layer
{
    App::factory()->createQuietly();

    return Layer::factory()->createQuietly();
}

it('registers a logo media collection as single file', function () {
    Storage::fake('wmfe');
    $layer = makeLayerForMedia();

    $layer->addMedia(UploadedFile::fake()->image('logo.png', 512, 512))
        ->toMediaCollection('logo');
    $layer->addMedia(UploadedFile::fake()->image('logo-2.png', 512, 512))
        ->toMediaCollection('logo');

    expect($layer->fresh()->getMedia('logo'))->toHaveCount(1);
});
```

- [ ] **Step 2: Esegui il test per verificare che fallisca**

Comando: `docker exec laravel-camminiditalia php artisan test --filter=LayerLogoMediaTest wm-package/tests/Feature/LayerLogoMediaTest.php`

Atteso: FAIL — `Layer` non ha ancora la collection `logo` (il media viene salvato nella collection di default o l'aggiunta di due file al posto di uno non viene sostituita).

Nota: se il comando `php artisan test` non risolve il path del package, esegui la suite dedicata del package: `docker exec laravel-camminiditalia sh -c "cd wm-package && vendor/bin/phpunit --filter=LayerLogoMediaTest"` (verifica il comando esatto disponibile in `wm-package/composer.json`, script `test`).

- [ ] **Step 3: Implementa `registerMediaCollections()` su Layer**

In `src/Models/Layer.php`, aggiungi il metodo (posizionalo subito prima di `appOwner()`, riga ~76):

```php
    public function registerMediaCollections(): void
    {
        parent::registerMediaCollections();
        $this->addMediaCollection('logo')->singleFile();
    }
```

- [ ] **Step 4: Esegui il test per verificare che passi**

Comando: `docker exec laravel-camminiditalia php artisan test --filter=LayerLogoMediaTest wm-package/tests/Feature/LayerLogoMediaTest.php`

Atteso: PASS

- [ ] **Step 5: Commit**

```bash
git -C /Users/bongiu/Documents/camminiditalia/wm-package add src/Models/Layer.php tests/Feature/LayerLogoMediaTest.php
git -C /Users/bongiu/Documents/camminiditalia/wm-package commit -m "feat(oc:8272): add logo media collection to Layer"
```

---

### Task 2: Accessor `logo_image` su Layer

**Files:**
- Modify: `src/Models/Layer.php`
- Test: `tests/Feature/LayerLogoMediaTest.php`

**Interfaces:**
- Consumes: collection `logo` da Task 1 (`$this->getFirstMediaUrl('logo')`)
- Produces: `Layer::getLogoImageAttribute(): ?string`, disponibile come `$layer->logo_image` e incluso in `$layer->toArray()`/`toJson()` tramite `$appends`

- [ ] **Step 1: Scrivi il test che verifica `logo_image` in `toArray()`**

Aggiungi in `tests/Feature/LayerLogoMediaTest.php`:

```php
it('exposes logo_image in toArray when a logo is attached', function () {
    Storage::fake('wmfe');
    $layer = makeLayerForMedia();

    $layer->addMedia(UploadedFile::fake()->image('logo.png', 512, 512))
        ->toMediaCollection('logo');

    $fresh = $layer->fresh();
    expect($fresh->logo_image)->toBeString()
        ->and($fresh->toArray()['logo_image'])->toBe($fresh->logo_image);
});

it('returns null for logo_image when no logo is attached', function () {
    $layer = makeLayerForMedia();

    expect($layer->fresh()->logo_image)->toBeNull()
        ->and($layer->fresh()->toArray()['logo_image'])->toBeNull();
});
```

- [ ] **Step 2: Esegui il test per verificare che fallisca**

Comando: `docker exec laravel-camminiditalia php artisan test --filter=LayerLogoMediaTest wm-package/tests/Feature/LayerLogoMediaTest.php`

Atteso: FAIL — `logo_image` non esiste su `Layer` (né come accessor né in `$appends`), il test su `toArray()['logo_image']` fallisce con chiave assente.

- [ ] **Step 3: Implementa l'accessor e `$appends`**

In `src/Models/Layer.php`, modifica la riga esistente (riga ~77):

```php
    // protected $appends = ['query_string'];
```

sostituiscila con:

```php
    protected $appends = ['logo_image'];
```

Poi aggiungi il metodo accessor subito dopo `registerMediaCollections()` (definito in Task 1):

```php
    public function getLogoImageAttribute(): ?string
    {
        return $this->getFirstMediaUrl('logo') ?: null;
    }
```

- [ ] **Step 4: Esegui il test per verificare che passi**

Comando: `docker exec laravel-camminiditalia php artisan test --filter=LayerLogoMediaTest wm-package/tests/Feature/LayerLogoMediaTest.php`

Atteso: PASS (tutti e 3 i test del file finora)

- [ ] **Step 5: Commit**

```bash
git -C /Users/bongiu/Documents/camminiditalia/wm-package add src/Models/Layer.php tests/Feature/LayerLogoMediaTest.php
git -C /Users/bongiu/Documents/camminiditalia/wm-package commit -m "feat(oc:8272): expose logo_image accessor on Layer"
```

---

### Task 3: Campo Nova `Logo` con validazione mimetype + ratio 1:1

**Files:**
- Modify: `src/Nova/Layer.php`
- Test: `tests/Feature/Nova/LayerLogoFieldValidationTest.php` (nuovo file)

**Interfaces:**
- Consumes: collection `logo` da Task 1, pattern di test da `tests/Feature/Nova/AppIconSplashDimensionsValidationTest.php` (helper `mediaUploadRequest`, uso di `Ebess\AdvancedNovaMediaLibrary\Fields\Images` + `fill()`)
- Produces: campo Nova `Images::make(__('Logo'), 'logo')` visibile nel dettaglio/edit del Layer, subito dopo il campo `Image` (`default`)

- [ ] **Step 1: Scrivi i test di validazione del campo Nova**

Crea `tests/Feature/Nova/LayerLogoFieldValidationTest.php`:

```php
<?php

declare(strict_types=1);

use Ebess\AdvancedNovaMediaLibrary\Fields\Images;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Nova\Layer as LayerResource;

uses(TestCase::class, DatabaseTransactions::class);

function layerImagesField(Layer $model, string $attribute): Images
{
    $request = NovaRequest::create('/');
    $resource = new LayerResource($model);

    foreach ($resource->fields($request) as $item) {
        $fields = $item instanceof Panel ? collect($item->data) : collect([$item]);
        $found = $fields->first(fn ($f) => $f instanceof Images && $f->attribute === $attribute);
        if ($found) {
            return $found;
        }
    }

    throw new RuntimeException("Images field '{$attribute}' not found in Layer resource");
}

function layerMediaUploadRequest(int $layerId, string $collection, UploadedFile $file): NovaRequest
{
    $symfonyRequest = HttpRequest::create("/nova-api/layers/{$layerId}", 'PUT');
    $symfonyRequest->files->set('__media__', [$collection => [$file]]);

    return NovaRequest::createFrom($symfonyRequest, new NovaRequest);
}

function makeLayerForNovaMedia(): Layer
{
    App::factory()->createQuietly();

    return Layer::factory()->createQuietly();
}

it('rejects a non-square logo', function () {
    Storage::fake('wmfe');
    $layer = makeLayerForNovaMedia();
    $field = layerImagesField($layer, 'logo');
    $request = layerMediaUploadRequest($layer->id, 'logo', UploadedFile::fake()->image('logo.png', 1024, 512));

    expect(fn () => $field->fill($request, $layer))->toThrow(ValidationException::class);
});

it('rejects a non png/svg logo', function () {
    Storage::fake('wmfe');
    $layer = makeLayerForNovaMedia();
    $field = layerImagesField($layer, 'logo');
    $request = layerMediaUploadRequest($layer->id, 'logo', UploadedFile::fake()->create('logo.jpg', 100, 'image/jpeg'));

    expect(fn () => $field->fill($request, $layer))->toThrow(ValidationException::class);
});

it('accepts a valid square png logo', function () {
    Storage::fake('wmfe');
    $layer = makeLayerForNovaMedia();
    $field = layerImagesField($layer, 'logo');
    $request = layerMediaUploadRequest($layer->id, 'logo', UploadedFile::fake()->image('logo.png', 512, 512));

    $callback = $field->fill($request, $layer);
    if (is_callable($callback)) {
        $callback();
    }

    expect($layer->fresh()->getFirstMedia('logo'))->not->toBeNull();
});
```

- [ ] **Step 2: Esegui i test per verificare che falliscano**

Comando: `docker exec laravel-camminiditalia php artisan test --filter=LayerLogoFieldValidationTest wm-package/tests/Feature/Nova/LayerLogoFieldValidationTest.php`

Atteso: FAIL — il campo `Images` con attributo `logo` non esiste ancora nella risorsa Nova `Layer` (`RuntimeException: Images field 'logo' not found`).

- [ ] **Step 3: Aggiungi il campo Nova**

In `src/Nova/Layer.php`, individua la riga esistente (circa riga 91):

```php
            Images::make(__('Image'), 'default'),
```

Aggiungi subito dopo:

```php
            Images::make(__('Image'), 'default'),
            Images::make(__('Logo'), 'logo')
                ->singleMediaRules(['mimes:png,webp', 'dimensions:ratio=1/1']),
```

- [ ] **Step 4: Esegui i test per verificare che passino**

Comando: `docker exec laravel-camminiditalia php artisan test --filter=LayerLogoFieldValidationTest wm-package/tests/Feature/Nova/LayerLogoFieldValidationTest.php`

Atteso: PASS (tutti e 3 i test)

- [ ] **Step 5: Commit**

```bash
git -C /Users/bongiu/Documents/camminiditalia/wm-package add src/Nova/Layer.php tests/Feature/Nova/LayerLogoFieldValidationTest.php
git -C /Users/bongiu/Documents/camminiditalia/wm-package commit -m "feat(oc:8272): add Logo Nova field with png/svg and square ratio validation"
```

---

### Task 4: Esecuzione suite completa e verifica finale

**Files:**
- Nessuna modifica — solo verifica

**Interfaces:**
- Consumes: tutti i test creati nei Task 1-3

- [ ] **Step 1: Esegui l'intera suite dei nuovi test insieme**

Comando: `docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/LayerLogoMediaTest.php wm-package/tests/Feature/Nova/LayerLogoFieldValidationTest.php`

Atteso: PASS — tutti i test verdi (collection registrata, accessor esposto, validazione mimetype + ratio 1:1 attiva)

- [ ] **Step 2: Esegui l'intera suite del package per verificare nessuna regressione**

Comando: `docker exec laravel-camminiditalia sh -c "cd wm-package && composer test"` (verifica lo script esatto in `wm-package/composer.json`; se non presente, usa `vendor/bin/phpunit` dalla root del package)

Atteso: PASS — nessuna regressione sui test esistenti di `Layer`/`App`/Nova

- [ ] **Step 3: Commit finale (se necessario un aggiustamento post-verifica)**

Solo se il passo 2 ha richiesto fix minori non coperti dai commit precedenti:

```bash
git -C /Users/bongiu/Documents/camminiditalia/wm-package add -A
git -C /Users/bongiu/Documents/camminiditalia/wm-package commit -m "fix(oc:8272): address regressions from full test suite run"
```
