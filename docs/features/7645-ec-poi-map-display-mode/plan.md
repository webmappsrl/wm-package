> Ticket: oc:7645

# EC POI map_display_mode Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permettere all'utente admin di scegliere esplicitamente se mostrare icona o immagine sulla mappa per ogni EC POI, tramite `use_image_as_icon` sulla categoria e un override opzionale sul singolo EC.

**Architecture:** Il valore `use_image_as_icon` (boolean) viene salvato nel campo `properties` (jsonb) già esistente su `TaxonomyPoiType` e `EcPoi`, senza nuove migration. Il backend risolve server-side (override EC → default categoria → fallback `false`) e scrive `feature_image.use_image_as_icon` solo nel GeoJSON dei related_pois dell'EcTrack tramite un `RelatedEcPoiResource` dedicato. L'API standalone di EcPoi non cambia.

**Tech Stack:** Laravel 10, wm-package, Nova 5, Pest, Orchestra Testbench

---

## File coinvolti

| File | Operazione | Responsabilità |
|---|---|---|
| `src/Models/Abstracts/Taxonomy.php` | Modify | Accessor `getUseImageAsIcon()` |
| `src/Models/EcPoi.php` | Modify | Accessor `getUseImageAsIcon()` + `resolveUseImageAsIcon()` |
| `src/Nova/TaxonomyPoiType.php` | Modify | Nova Select per `properties->use_image_as_icon` |
| `src/Nova/EcPoi.php` | Modify | Nova Select per `properties->use_image_as_icon` (nullable) |
| `src/Http/Resources/RelatedEcPoiResource.php` | Create | Estende `EcPoiResource`, aggiunge `feature_image.use_image_as_icon` |
| `src/Http/Resources/EcTrackResource.php` | Modify | Usa `RelatedEcPoiResource` in `getRelatedPois()` |
| `tests/Feature/EcPoiMapDisplayTest.php` | Create | Test logica risoluzione + integrazione EcTrack |

---

### Task 1: Accessor su Taxonomy e EcPoi

**Files:**
- Modify: `src/Models/Abstracts/Taxonomy.php`
- Modify: `src/Models/EcPoi.php`
- Create: `tests/Feature/EcPoiMapDisplayTest.php`

- [ ] **Step 1: Scrivi i test fallenti per `getUseImageAsIcon()`**

```php
// tests/Feature/EcPoiMapDisplayTest.php
declare(strict_types=1);

use Wm\WmPackage\Models\TaxonomyPoiType;
use Wm\WmPackage\Models\EcPoi;

it('returns use_image_as_icon from properties when set on taxonomy', function () {
    $taxonomy = TaxonomyPoiType::factory()->create([
        'properties' => ['use_image_as_icon' => true],
    ]);

    expect($taxonomy->getUseImageAsIcon())->toBeTrue();
});

it('returns false as default when use_image_as_icon is not set on taxonomy', function () {
    $taxonomy = TaxonomyPoiType::factory()->create([
        'properties' => [],
    ]);

    expect($taxonomy->getUseImageAsIcon())->toBeFalse();
});

it('returns use_image_as_icon from properties when set on ec poi', function () {
    $poi = EcPoi::factory()->create([
        'properties' => ['use_image_as_icon' => true],
    ]);

    expect($poi->getUseImageAsIcon())->toBeTrue();
});

it('returns null when use_image_as_icon is not set on ec poi', function () {
    $poi = EcPoi::factory()->create([
        'properties' => [],
    ]);

    expect($poi->getUseImageAsIcon())->toBeNull();
});
```

- [ ] **Step 2: Esegui i test per verificare che falliscano**

```bash
docker exec laravel-camminiditalia php artisan test tests/Feature/EcPoiMapDisplayTest.php
```

Atteso: FAIL con `Call to undefined method`

- [ ] **Step 3: Aggiungi `getUseImageAsIcon()` a `Taxonomy`**

In `src/Models/Abstracts/Taxonomy.php`, aggiungi dopo il metodo `getJson()`:

```php
public function getUseImageAsIcon(): bool
{
    return (bool) ($this->properties['use_image_as_icon'] ?? false);
}
```

- [ ] **Step 4: Aggiungi `getUseImageAsIcon()` a `EcPoi`**

In `src/Models/EcPoi.php`, aggiungi dopo le relazioni (intorno a riga 120):

```php
public function getUseImageAsIcon(): ?bool
{
    if (! array_key_exists('use_image_as_icon', $this->properties ?? [])) {
        return null;
    }

    return (bool) $this->properties['use_image_as_icon'];
}
```

- [ ] **Step 5: Esegui i test e verifica che passino**

```bash
docker exec laravel-camminiditalia php artisan test tests/Feature/EcPoiMapDisplayTest.php
```

Atteso: 4 PASS

- [ ] **Step 6: Commit**

```bash
git add src/Models/Abstracts/Taxonomy.php src/Models/EcPoi.php tests/Feature/EcPoiMapDisplayTest.php
git commit -m "feat(oc:7645): add getUseImageAsIcon accessor to Taxonomy and EcPoi"
```

---

### Task 2: `resolveUseImageAsIcon()` su EcPoi

**Files:**
- Modify: `src/Models/EcPoi.php`
- Modify: `tests/Feature/EcPoiMapDisplayTest.php`

- [ ] **Step 1: Scrivi i test fallenti per `resolveUseImageAsIcon()`**

Aggiungi a `tests/Feature/EcPoiMapDisplayTest.php`:

```php
it('resolves to ec poi override when set to true', function () {
    $taxonomy = TaxonomyPoiType::factory()->create([
        'properties' => ['use_image_as_icon' => false],
    ]);
    $poi = EcPoi::factory()->create([
        'properties' => ['use_image_as_icon' => true],
    ]);
    $poi->taxonomyPoiTypes()->attach($taxonomy);

    expect($poi->resolveUseImageAsIcon())->toBeTrue();
});

it('resolves to ec poi override when set to false', function () {
    $taxonomy = TaxonomyPoiType::factory()->create([
        'properties' => ['use_image_as_icon' => true],
    ]);
    $poi = EcPoi::factory()->create([
        'properties' => ['use_image_as_icon' => false],
    ]);
    $poi->taxonomyPoiTypes()->attach($taxonomy);

    expect($poi->resolveUseImageAsIcon())->toBeFalse();
});

it('resolves to category default when ec poi has no override', function () {
    $taxonomy = TaxonomyPoiType::factory()->create([
        'properties' => ['use_image_as_icon' => true],
    ]);
    $poi = EcPoi::factory()->create([
        'properties' => [],
    ]);
    $poi->taxonomyPoiTypes()->attach($taxonomy);

    expect($poi->resolveUseImageAsIcon())->toBeTrue();
});

it('resolves to false fallback when no category is associated', function () {
    $poi = EcPoi::factory()->create([
        'properties' => [],
    ]);

    expect($poi->resolveUseImageAsIcon())->toBeFalse();
});

it('resolves using first category by id asc when multiple categories exist', function () {
    $firstTaxonomy = TaxonomyPoiType::factory()->create([
        'properties' => ['use_image_as_icon' => true],
    ]);
    $secondTaxonomy = TaxonomyPoiType::factory()->create([
        'properties' => ['use_image_as_icon' => false],
    ]);
    $poi = EcPoi::factory()->create([
        'properties' => [],
    ]);
    $poi->taxonomyPoiTypes()->attach([$firstTaxonomy->id, $secondTaxonomy->id]);

    expect($poi->resolveUseImageAsIcon())->toBeTrue();
});
```

- [ ] **Step 2: Esegui i test per verificare che falliscano**

```bash
docker exec laravel-camminiditalia php artisan test --filter="resolves"
```

Atteso: FAIL con `Call to undefined method`

- [ ] **Step 3: Aggiungi `resolveUseImageAsIcon()` a `EcPoi`**

In `src/Models/EcPoi.php`, aggiungi dopo `getUseImageAsIcon()`:

```php
public function resolveUseImageAsIcon(): bool
{
    $override = $this->getUseImageAsIcon();
    if ($override !== null) {
        return $override;
    }

    $category = $this->taxonomyPoiTypes()->orderBy('id', 'asc')->first();
    if ($category !== null) {
        return $category->getUseImageAsIcon();
    }

    return false;
}
```

- [ ] **Step 4: Esegui tutti i test del file**

```bash
docker exec laravel-camminiditalia php artisan test tests/Feature/EcPoiMapDisplayTest.php
```

Atteso: 9 PASS

- [ ] **Step 5: Commit**

```bash
git add src/Models/EcPoi.php tests/Feature/EcPoiMapDisplayTest.php
git commit -m "feat(oc:7645): add resolveUseImageAsIcon() to EcPoi with override/category/fallback logic"
```

---

### Task 3: `RelatedEcPoiResource` con `feature_image.use_image_as_icon`

**Files:**
- Create: `src/Http/Resources/RelatedEcPoiResource.php`
- Modify: `src/Http/Resources/EcTrackResource.php`
- Modify: `tests/Feature/EcPoiMapDisplayTest.php`

- [ ] **Step 1: Scrivi i test fallenti**

Aggiungi a `tests/Feature/EcPoiMapDisplayTest.php`:

```php
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Http\Resources\EcTrackResource;
use Illuminate\Http\Request;

it('exposes use_image_as_icon true inside feature_image in related_pois when category has it enabled', function () {
    $taxonomy = TaxonomyPoiType::factory()->create([
        'properties' => ['use_image_as_icon' => true],
    ]);
    $poi = EcPoi::factory()->create(['properties' => []]);
    $poi->taxonomyPoiTypes()->attach($taxonomy);

    $track = EcTrack::factory()->create();
    $track->ecPois()->attach($poi);

    $resource = EcTrackResource::make($track->load('ecPois'))->toArray(Request::create('/'));

    $relatedPoi = collect($resource['properties']['related_pois'])
        ->first(fn($p) => $p['properties']['id'] === $poi->id);

    expect($relatedPoi['properties']['feature_image']['use_image_as_icon'])->toBeTrue();
});

it('exposes use_image_as_icon false inside feature_image in related_pois when no category set', function () {
    $poi = EcPoi::factory()->create(['properties' => []]);

    $track = EcTrack::factory()->create();
    $track->ecPois()->attach($poi);

    $resource = EcTrackResource::make($track->load('ecPois'))->toArray(Request::create('/'));

    $relatedPoi = collect($resource['properties']['related_pois'])
        ->first(fn($p) => $p['properties']['id'] === $poi->id);

    expect($relatedPoi['properties']['feature_image']['use_image_as_icon'])->toBeFalse();
});
```

- [ ] **Step 2: Esegui i test per verificare che falliscano**

```bash
docker exec laravel-camminiditalia php artisan test --filter="exposes use_image_as_icon"
```

Atteso: FAIL — `use_image_as_icon` non presente

- [ ] **Step 3: Crea `RelatedEcPoiResource`**

```php
// src/Http/Resources/RelatedEcPoiResource.php
<?php

namespace Wm\WmPackage\Http\Resources;

use Illuminate\Http\Request;

class RelatedEcPoiResource extends EcPoiResource
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        $featureImage = $data['properties']['feature_image'] ?? [];
        if ($featureImage instanceof \Illuminate\Http\Resources\Json\JsonResource) {
            $featureImage = $featureImage->toArray($request);
        }

        $data['properties']['feature_image'] = array_merge(
            is_array($featureImage) ? $featureImage : [],
            ['use_image_as_icon' => $this->resolveUseImageAsIcon()]
        );

        return $data;
    }
}
```

- [ ] **Step 4: Aggiorna `EcTrackResource::getRelatedPois()`**

In `src/Http/Resources/EcTrackResource.php`, sostituisci `EcPoiResource::make($ecPoi)` con `RelatedEcPoiResource::make($ecPoi)` e aggiungi l'import:

```php
use Wm\WmPackage\Http\Resources\RelatedEcPoiResource;
```

```php
private function getRelatedPois()
{
    try {
        return $this->ecPois
            ->whereNull('osmfeatures_id')
            ->map(function (EcPoi $ecPoi) {
                return RelatedEcPoiResource::make($ecPoi);
            })
            ->toArray();
    } catch (\Exception $e) {
        return [];
    }
}
```

- [ ] **Step 5: Esegui tutti i test del file**

```bash
docker exec laravel-camminiditalia php artisan test tests/Feature/EcPoiMapDisplayTest.php
```

Atteso: 11 PASS

- [ ] **Step 6: Commit**

```bash
git add src/Http/Resources/RelatedEcPoiResource.php src/Http/Resources/EcTrackResource.php tests/Feature/EcPoiMapDisplayTest.php
git commit -m "feat(oc:7645): add RelatedEcPoiResource with feature_image.use_image_as_icon"
```

---

### Task 4: Nova field su `TaxonomyPoiType`

**Files:**
- Modify: `src/Nova/TaxonomyPoiType.php`

- [ ] **Step 1: Aggiungi il campo Select**

```php
<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;

class TaxonomyPoiType extends AbstractTaxonomyResource
{
    public static $model = \Wm\WmPackage\Models\TaxonomyPoiType::class;

    public static $title = 'name';

    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
            Select::make(__('Map display'), 'properties->use_image_as_icon')
                ->options([
                    '0' => __('Use category icon (default)'),
                    '1' => __('Use image as icon'),
                ])
                ->default('0')
                ->displayUsingLabels()
                ->help(__('Defines what to show on the map for all POIs of this type.')),
            MorphToMany::make('POI Associati', 'ecPois', EcPoi::class)
                ->display('name')
                ->help('Punti di interesse associati a questa tassonomia'),
        ];
    }
}
```

- [ ] **Step 2: Verifica che i test esistenti non siano rotti**

```bash
docker exec laravel-camminiditalia php artisan test tests/Feature/EcPoiMapDisplayTest.php
```

Atteso: 11 PASS

- [ ] **Step 3: Commit**

```bash
git add src/Nova/TaxonomyPoiType.php
git commit -m "feat(oc:7645): add use_image_as_icon Nova field to TaxonomyPoiType"
```

---

### Task 5: Nova field su `EcPoi`

**Files:**
- Modify: `src/Nova/EcPoi.php`

- [ ] **Step 1: Aggiungi `Select` agli import di `EcPoi` Nova**

In `src/Nova/EcPoi.php`, aggiungi tra gli import esistenti:

```php
use Laravel\Nova\Fields\Select;
```

- [ ] **Step 2: Aggiungi il campo Select nel metodo `fields()`**

Aggiungi dopo `Boolean::make(__('Global'), 'global')`:

```php
Select::make(__('Map display'), 'properties->use_image_as_icon')
    ->options([
        '' => __('Inherit from category'),
        '0' => __('Use category icon'),
        '1' => __('Use image as icon'),
    ])
    ->nullable()
    ->displayUsingLabels()
    ->help(__('Override the category default. Leave empty to inherit.')),
```

- [ ] **Step 3: Verifica che i test esistenti non siano rotti**

```bash
docker exec laravel-camminiditalia php artisan test tests/Feature/EcPoiMapDisplayTest.php
```

Atteso: 11 PASS

- [ ] **Step 4: Commit**

```bash
git add src/Nova/EcPoi.php
git commit -m "feat(oc:7645): add use_image_as_icon Nova field to EcPoi"
```
