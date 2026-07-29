> Ticket: oc:8181

# Box Informativi (Cammini Builder) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a generic Nova `Flexible` builder field (`properties->config_detail`) to `Layer`, `EcTrack` and `EcPoi` in wm-package, with one registered layout today (`info_box`: a repeatable list of translated title+WYSIWYG rows), designed so a future layout can be added with just one more `addLayout()` call.

**Architecture:** Two-level Whitecube Flexible + Nova Repeater composition, mirroring the existing `config_home` → "Horizontal Scroll" pattern in `wm-package/src/Nova/App.php`. Level 1 (`ConfigDetailResolver`, a custom `ResolverInterface`) reads/writes `properties['config_detail']` (already array-cast, no manual JSON decode needed) and dispatches per `Layout::name()`. Level 2 (`info_box` layout) contains only a `Repeater::make('Items', 'items')->repeatables([InfoBoxItemRepeatable::make()])` using Nova's **default** JSON preset (no custom preset class needed — verified against vendor source, see Task 3 notes) — each repeater row has one `Text`/`Trix` pair per configured locale.

**Tech Stack:** Laravel Nova 5, `whitecube/nova-flexible-content` ^2.0.1 (already a dependency), `ezyang/htmlpurifier` (already present transitively in `camminiditalia/composer.lock`, to be declared as a direct wm-package dependency in Task 2), Pest tests via `Tests\TestCase` (camminiditalia's own, real Nova install — matches the existing pattern in `wm-package/tests/Feature/Nova/LayerLogoFieldValidationTest.php` and `AbstractUserResourceImpersonateTest.php`, both of which live physically in `wm-package/tests/` but run through camminiditalia's `php artisan test`).

## Global Constraints

- No migration: `properties` is already an `array`-cast JSON(B) column on `Layer`, `EcTrack`, `EcPoi`.
- No role-specific logic (`hasRole('Validator')` or similar) anywhere in wm-package code for this feature — the field must rely exclusively on the resource's existing `authorizedToUpdate()` gate.
- Locales for per-row fields must be read dynamically from `config('wm-tab-translatable.locales')` (currently `it, en, fr, es, de` in `wm-package/config/wm-tab-translatable.php`), never hardcoded.
- `EcPoi::factory()->create()` requires an explicit `'properties' => []` argument in tests (known trap — `AbstractObserver` throws a `TypeError` otherwise).
- Commit convention: `feat(oc:8181): <description>`. Commits are **instructions for the developer to run manually** — do not execute `git commit`/`git push`/`git checkout -b` automatically while working through this plan.
- Test namespace/base class: use `Tests\TestCase` (camminiditalia's), Pest style (`uses(TestCase::class, DatabaseTransactions::class)`), placed under `wm-package/tests/Feature/Nova/`. Do **not** use `Wm\WmPackage\Tests\TestCase` for these tests (that base class is only for the package's own isolated `vendor/bin/pest` suite and cannot resolve the real Nova resources registered by the host app).
- Nova resource `uriKey`s (no local overrides found, so Nova's default kebab-plural naming applies): `layers`, `ec-tracks`, `ec-pois`.

---

### Task 1: `ConfigDetailResolver` — generic get/set with sibling preservation

**Files:**
- Create: `wm-package/src/Nova/Flexible/Resolvers/ConfigDetailResolver.php`
- Test: `wm-package/tests/Feature/Nova/ConfigDetailResolverTest.php`

**Interfaces:**
- Produces: `Wm\WmPackage\Nova\Flexible\Resolvers\ConfigDetailResolver implements Whitecube\NovaFlexibleContent\Value\ResolverInterface` with `get($resource, $attribute, $layouts): \Illuminate\Support\Collection` and `set($resource, $attribute, $groups)`. `set()` dispatches each `Layout` via a `protected function buildElement(Layout $layout): array` method — Task 3 adds the `info_box` case to this same method (do not create a second dispatch mechanism).

- [ ] **Step 1: Write the failing test**

This test does NOT use `info_box`/Repeater yet — it proves the resolver's core mechanics (round-trip + sibling preservation) with a single plain `Text` field layout defined inline, exactly the same way the field will later be wired with `info_box`.

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\TestCase;
use Whitecube\NovaFlexibleContent\Flexible;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Nova\Flexible\Resolvers\ConfigDetailResolver;

uses(TestCase::class, DatabaseTransactions::class);

function probeConfigDetailField(): Flexible
{
    return Flexible::make('Config Detail', 'properties->config_detail')
        ->resolver(ConfigDetailResolver::class)
        ->addLayout('Probe', 'probe', [
            Text::make('Label', 'label'),
        ]);
}

function configDetailFillRequest(int $layerId, array $groups): NovaRequest
{
    $symfonyRequest = HttpRequest::create("/nova-api/layers/{$layerId}", 'PUT', [
        'properties->config_detail' => $groups,
    ]);

    return NovaRequest::createFrom($symfonyRequest, new NovaRequest);
}

it('round-trips a probe group and preserves sibling properties keys', function () {
    App::factory()->createQuietly();
    $layer = Layer::factory()->createQuietly([
        'properties' => ['title' => ['it' => 'Titolo esistente'], 'description' => ['it' => 'Descrizione esistente']],
    ]);

    $field = probeConfigDetailField();
    $request = configDetailFillRequest($layer->id, [
        ['layout' => 'probe', 'key' => 'new-group-key-1', 'attributes' => ['label' => 'Ciao']],
    ]);

    $callback = $field->fill($request, $layer);
    if (is_callable($callback)) {
        $callback();
    }
    $layer->save();

    $fresh = $layer->fresh();
    expect($fresh->properties['config_detail'])->toHaveCount(1);
    expect($fresh->properties['config_detail'][0]['type'])->toBe('probe');
    expect($fresh->properties['config_detail'][0]['label'])->toBe('Ciao');
    expect($fresh->properties['title'])->toBe(['it' => 'Titolo esistente']);
    expect($fresh->properties['description'])->toBe(['it' => 'Descrizione esistente']);
});

it('resolves a previously saved probe group back into the field value', function () {
    App::factory()->createQuietly();
    $layer = Layer::factory()->createQuietly([
        'properties' => [
            'title' => ['it' => 'Titolo'],
            'config_detail' => [
                ['type' => 'probe', 'label' => 'Valore salvato'],
            ],
        ],
    ]);

    $field = probeConfigDetailField();
    $field->resolve($layer);

    expect($field->value)->toHaveCount(1);
    expect($field->value[0]['attributes']['label'])->toBe('Valore salvato');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec laravel-camminiditalia php artisan test --filter=ConfigDetailResolverTest`
Expected: FAIL — `Class "Wm\WmPackage\Nova\Flexible\Resolvers\ConfigDetailResolver" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace Wm\WmPackage\Nova\Flexible\Resolvers;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Whitecube\NovaFlexibleContent\Layouts\Layout;
use Whitecube\NovaFlexibleContent\Value\ResolverInterface;

class ConfigDetailResolver implements ResolverInterface
{
    public function get($resource, $attribute, $layouts): Collection
    {
        if (! is_object($resource)) {
            return collect();
        }

        $properties = $resource->properties ?? [];
        $groups = is_array($properties) ? ($properties['config_detail'] ?? []) : [];

        if (! is_array($groups)) {
            return collect();
        }

        $result = collect();

        foreach ($groups as $group) {
            if (! is_array($group) || ! isset($group['type'])) {
                continue;
            }

            $layout = $layouts->find($group['type']);

            if (! $layout) {
                continue;
            }

            $attributes = Arr::except($group, ['type']);
            $result->push($layout->duplicateAndHydrate(uniqid('', true), $attributes));
        }

        return $result;
    }

    public function set($resource, $attribute, $groups)
    {
        $data = $groups->map(fn (Layout $layout) => $this->buildElement($layout))->values()->all();

        // $attribute is 'properties->config_detail'. Eloquent's own setAttribute()
        // special-cases keys containing '->' via fillJsonAttribute(), which reads
        // the current 'properties' array, merges only the 'config_detail' path,
        // and re-encodes the whole column — sibling keys (title, description,
        // form, ugc, layers, layer_id...) are preserved automatically. No manual
        // Arr::set()/reassignment needed (verified against
        // Illuminate\Database\Eloquent\Concerns\HasAttributes::setAttribute()).
        $resource->{$attribute} = $data;

        return $resource;
    }

    protected function buildElement(Layout $layout): array
    {
        return match ($layout->name()) {
            default => ['type' => $layout->name()] + $layout->getAttributes(),
        };
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec laravel-camminiditalia php artisan test --filter=ConfigDetailResolverTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
git add wm-package/src/Nova/Flexible/Resolvers/ConfigDetailResolver.php wm-package/tests/Feature/Nova/ConfigDetailResolverTest.php
git commit -m "feat(oc:8181): add generic ConfigDetailResolver with sibling-preserving properties merge"
```

---

### Task 2: `InfoBoxItemRepeatable` — per-locale title/content row with HTML sanitization

**Files:**
- Modify: `wm-package/composer.json` (add `ezyang/htmlpurifier` to `require`)
- Create: `wm-package/src/Nova/Flexible/ConfigDetail/InfoBoxItemRepeatable.php`
- Test: `wm-package/tests/Feature/Nova/InfoBoxItemRepeatableTest.php`

**Interfaces:**
- Consumes: `config('wm-tab-translatable.locales')` (array of locale codes, e.g. `['it','en','fr','es','de']`).
- Produces: `Wm\WmPackage\Nova\Flexible\ConfigDetail\InfoBoxItemRepeatable extends Laravel\Nova\Fields\Repeater\Repeatable`, static `key(): string` returning `'info-box-item'`, `fields(NovaRequest $request): array` returning one `Text::make(..., "titolo_{$locale}")` and one `Trix::make(..., "contenuto_{$locale}")` per configured locale. Task 3 registers this class via `->repeatables([InfoBoxItemRepeatable::make()])`.

- [ ] **Step 1: Add the HTMLPurifier dependency**

Edit `wm-package/composer.json`, inside `"require"` (alongside `"php": ">8.1"`), add:

```json
        "ezyang/htmlpurifier": "^4.19",
```

Run: `docker exec laravel-camminiditalia composer update ezyang/htmlpurifier --working-dir=/var/www/html/camminiditalia`
Expected: no version change (already installed transitively at `v4.19.0`), `composer.lock` now shows it under wm-package's own direct requirements.

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\TestCase;
use Wm\WmPackage\Nova\Flexible\ConfigDetail\InfoBoxItemRepeatable;

uses(TestCase::class, DatabaseTransactions::class);

it('exposes one titolo and one contenuto field per configured locale', function () {
    $locales = config('wm-tab-translatable.locales');
    $fields = InfoBoxItemRepeatable::make()->fields(NovaRequest::create('/'));
    $attributes = collect($fields)->map(fn ($f) => $f->attribute)->all();

    foreach ($locales as $locale) {
        expect($attributes)->toContain("titolo_{$locale}");
        expect($attributes)->toContain("contenuto_{$locale}");
    }
});

it('sanitizes a malicious contenuto payload on fill while keeping safe formatting', function () {
    $fields = InfoBoxItemRepeatable::make()->fields(NovaRequest::create('/'));
    $contenutoIt = collect($fields)->first(fn ($f) => $f->attribute === 'contenuto_it');

    $model = new stdClass;
    $request = NovaRequest::create('/', 'PUT', [
        'contenuto_it' => '<p onclick="alert(1)">Testo <script>alert(2)</script><b>sicuro</b></p>',
    ]);

    $callback = $contenutoIt->fill($request, $model);
    if (is_callable($callback)) {
        $callback();
    }

    expect($model->contenuto_it)->toContain('<b>sicuro</b>');
    expect($model->contenuto_it)->not->toContain('onclick');
    expect($model->contenuto_it)->not->toContain('<script>');
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `docker exec laravel-camminiditalia php artisan test --filter=InfoBoxItemRepeatableTest`
Expected: FAIL — `Class "Wm\WmPackage\Nova\Flexible\ConfigDetail\InfoBoxItemRepeatable" not found`.

- [ ] **Step 4: Write minimal implementation**

```php
<?php

namespace Wm\WmPackage\Nova\Flexible\ConfigDetail;

use Illuminate\Support\Facades\Config;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Repeater\Repeatable;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Trix;
use Laravel\Nova\Http\Requests\NovaRequest;

class InfoBoxItemRepeatable extends Repeatable
{
    public static function key(): string
    {
        return 'info-box-item';
    }

    /**
     * @return array<int, Field>
     */
    public function fields(NovaRequest $request): array
    {
        $locales = Config::get('wm-tab-translatable.locales', []);
        $fields = [];

        foreach ($locales as $locale) {
            $fields[] = Text::make(__('Titolo').' ('.$locale.')', "titolo_{$locale}")->nullable();
        }

        foreach ($locales as $locale) {
            $fields[] = Trix::make(__('Contenuto').' ('.$locale.')', "contenuto_{$locale}")
                ->nullable()
                ->fillUsing(function (NovaRequest $request, $model, $attribute, $requestAttribute) {
                    $value = $request->input($requestAttribute);

                    if (! is_string($value) || $value === '') {
                        $model->{$attribute} = $value;

                        return;
                    }

                    $config = \HTMLPurifier_Config::createDefault();
                    $config->set('HTML.Allowed', 'p,br,b,strong,i,em,u,ul,ol,li,h2,h3,h4,blockquote,a[href]');
                    $purifier = new \HTMLPurifier($config);

                    $model->{$attribute} = $purifier->purify($value);
                });
        }

        return $fields;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker exec laravel-camminiditalia php artisan test --filter=InfoBoxItemRepeatableTest`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add wm-package/composer.json wm-package/composer.lock wm-package/src/Nova/Flexible/ConfigDetail/InfoBoxItemRepeatable.php wm-package/tests/Feature/Nova/InfoBoxItemRepeatableTest.php
git commit -m "feat(oc:8181): add InfoBoxItemRepeatable with dynamic per-locale fields and HTML sanitization"
```

---

### Task 3: Wire `info_box` into `ConfigDetailResolver` and register the field on `Layer`

**Files:**
- Modify: `wm-package/src/Nova/Flexible/Resolvers/ConfigDetailResolver.php` (add the `info_box` case to `buildElement()`)
- Modify: `wm-package/src/Nova/Layer.php`
- Test: `wm-package/tests/Feature/Nova/LayerConfigDetailInfoBoxTest.php`

**Interfaces:**
- Consumes: `ConfigDetailResolver` (Task 1), `InfoBoxItemRepeatable` (Task 2).
- Produces: `properties['config_detail']` shape `[{ type: 'info_box', items: [{ type: 'info-box-item', fields: { titolo_it, contenuto_it, ... } }, ...] }, ...]` — the `items` shape is Nova's own default JSON-preset repeater block format (`{type, fields}`), reused as-is with **no custom preset class**: verified against `Laravel\Nova\Fields\Repeater\Presets\JSON::get()/set()` (vendor), which already round-trips this exact shape through `Layout::getAttributes()`/`duplicateAndHydrate()` without any adaptation — `Repeater::getPreset()` defaults to `new JSON` when `->preset()` is never called.

- [ ] **Step 1: Write the failing test**

Replicates the mockup exactly: 2 `info_box` groups on the same Layer, 9 items in the first, 5 in the second, each item with `titolo_it`/`contenuto_it` filled (other locales left empty, allowed per requirements).

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Nova\Fields\Repeater\Repeater;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;
use Tests\TestCase;
use Whitecube\NovaFlexibleContent\Flexible;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Nova\Layer as LayerResource;

uses(TestCase::class, DatabaseTransactions::class);

function layerConfigDetailField(Layer $model): Flexible
{
    $request = NovaRequest::create('/');
    $resource = new LayerResource($model);

    foreach ($resource->fields($request) as $item) {
        $fields = $item instanceof Panel ? collect($item->data) : collect([$item]);
        $found = $fields->first(fn ($f) => $f instanceof Flexible && $f->attribute === 'properties->config_detail');
        if ($found) {
            return $found;
        }
    }

    throw new RuntimeException('config_detail field not found on Layer resource');
}

function infoBoxItem(string $titoloIt, string $contenutoIt): array
{
    return ['type' => 'info-box-item', 'fields' => ['titolo_it' => $titoloIt, 'contenuto_it' => "<p>{$contenutoIt}</p>"]];
}

it('stores two info_box groups with 9 and 5 items each, matching the mockup', function () {
    App::factory()->createQuietly();
    $layer = Layer::factory()->createQuietly(['properties' => []]);

    $generalTitles = ['Storia', 'Acqua', 'Servizi', 'Segnaletica', 'Logistica e trasporti', 'In tenda', 'Credenziale e testimonium', 'Fondo stradale', 'Contatti'];
    $stageTitles = ['Tappa 01: Iglesias – Nebida', 'Tappa 02: Nebida – Masua', 'Tappa 03: Masua – Buggerru', 'Tappa 04: Buggerru – Portixeddu', 'Tappa 05: Portixeddu – Piscinas'];

    $groups = [
        [
            'layout' => 'info_box',
            'key' => 'new-group-1',
            'attributes' => ['items' => array_map(fn ($t) => infoBoxItem($t, "Contenuto {$t}"), $generalTitles)],
        ],
        [
            'layout' => 'info_box',
            'key' => 'new-group-2',
            'attributes' => ['items' => array_map(fn ($t) => infoBoxItem($t, "Contenuto {$t}"), $stageTitles)],
        ],
    ];

    $field = layerConfigDetailField($layer);
    $symfonyRequest = HttpRequest::create("/nova-api/layers/{$layer->id}", 'PUT', ['properties->config_detail' => $groups]);
    $request = NovaRequest::createFrom($symfonyRequest, new NovaRequest);

    $callback = $field->fill($request, $layer);
    if (is_callable($callback)) {
        $callback();
    }
    $layer->save();

    $stored = $layer->fresh()->properties['config_detail'];

    expect($stored)->toHaveCount(2);
    expect($stored[0]['type'])->toBe('info_box');
    expect($stored[0]['items'])->toHaveCount(9);
    expect($stored[1]['items'])->toHaveCount(5);
    expect($stored[0]['items'][0]['fields']['titolo_it'])->toBe('Storia');
    expect($stored[1]['items'][4]['fields']['titolo_it'])->toBe('Tappa 05: Portixeddu – Piscinas');
});

it('resolves stored info_box groups back through the Repeater without a custom preset', function () {
    App::factory()->createQuietly();
    $layer = Layer::factory()->createQuietly([
        'properties' => [
            'config_detail' => [
                ['type' => 'info_box', 'items' => [infoBoxItem('Storia', 'Testo storia')]],
            ],
        ],
    ]);

    $field = layerConfigDetailField($layer);
    $field->resolve($layer);

    $group = collect($field->value)->first();
    $itemsField = collect($group['attributes'])->first(fn ($f) => $f instanceof Repeater);

    expect($itemsField)->not->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec laravel-camminiditalia php artisan test --filter=LayerConfigDetailInfoBoxTest`
Expected: FAIL — `config_detail field not found on Layer resource`.

- [ ] **Step 3: Add the `info_box` case to `ConfigDetailResolver::buildElement()`**

```php
    protected function buildElement(Layout $layout): array
    {
        return match ($layout->name()) {
            'info_box' => $this->buildInfoBoxElement($layout),
            default => ['type' => $layout->name()] + $layout->getAttributes(),
        };
    }

    protected function buildInfoBoxElement(Layout $layout): array
    {
        $items = $layout->getAttributes()['items'] ?? [];

        if (is_string($items)) {
            $decoded = json_decode($items, true);
            $items = is_array($decoded) ? $decoded : [];
        }

        return [
            'type' => 'info_box',
            'items' => array_values(is_array($items) ? $items : []),
        ];
    }
```

- [ ] **Step 4: Register the field on `wm-package/src/Nova/Layer.php`**

Add the import (alongside the existing `use Wm\WmPackage\Nova\Fields\PropertiesPanel;` line):

```php
use Whitecube\NovaFlexibleContent\Flexible;
use Wm\WmPackage\Nova\Flexible\ConfigDetail\InfoBoxItemRepeatable;
use Wm\WmPackage\Nova\Flexible\Resolvers\ConfigDetailResolver;
```

Insert into `fields()`, right after the existing `Panel::make('Ec Pois', [...])` block (`wm-package/src/Nova/Layer.php:106-110`):

```php
            Panel::make(__('Box Informativi'), [
                Flexible::make(__('Box Informativi'), 'properties->config_detail')
                    ->resolver(ConfigDetailResolver::class)
                    ->addLayout(__('Box Informativo'), 'info_box', [
                        Repeater::make(__('Items'), 'items')
                            ->repeatables([InfoBoxItemRepeatable::make()])
                            ->rules('required', 'array'),
                    ])
                    ->button(__('Aggiungi Box Informativo'))
                    ->confirmRemove(__('Sei sicuro di voler eliminare questo box?'), __('Elimina'), __('Annulla')),
            ])->collapsible(),
```

Add `use Laravel\Nova\Fields\Repeater\Repeater;` to the imports too.

- [ ] **Step 5: Run test to verify it passes**

Run: `docker exec laravel-camminiditalia php artisan test --filter=LayerConfigDetailInfoBoxTest`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add wm-package/src/Nova/Flexible/Resolvers/ConfigDetailResolver.php wm-package/src/Nova/Layer.php wm-package/tests/Feature/Nova/LayerConfigDetailInfoBoxTest.php
git commit -m "feat(oc:8181): register info_box layout and Box Informativi panel on Layer"
```

---

### Task 4: Register the same field on `EcTrack` and `EcPoi`

**Files:**
- Modify: `wm-package/src/Nova/EcTrack.php`
- Modify: `wm-package/src/Nova/EcPoi.php`
- Test: `wm-package/tests/Feature/Nova/EcTrackEcPoiConfigDetailTest.php`

**Interfaces:**
- Consumes: `ConfigDetailResolver` (Task 1/3), `InfoBoxItemRepeatable` (Task 2). Same `properties->config_detail` attribute and `info_box` layout as Task 3 — no new classes.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;
use Tests\TestCase;
use Whitecube\NovaFlexibleContent\Flexible;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Nova\EcPoi as EcPoiResource;
use Wm\WmPackage\Nova\EcTrack as EcTrackResource;

uses(TestCase::class, DatabaseTransactions::class);

function findConfigDetailField(object $resource, NovaRequest $request): Flexible
{
    foreach ($resource->fields($request) as $item) {
        $fields = $item instanceof Panel ? collect($item->data) : collect([$item]);
        $found = $fields->first(fn ($f) => $f instanceof Flexible && $f->attribute === 'properties->config_detail');
        if ($found) {
            return $found;
        }
    }

    throw new RuntimeException('config_detail field not found');
}

function fillOneInfoBoxGroup(Flexible $field, string $uriKey, $model): void
{
    $groups = [[
        'layout' => 'info_box',
        'key' => 'new-group-1',
        'attributes' => ['items' => [
            ['type' => 'info-box-item', 'fields' => ['titolo_it' => 'Nota', 'contenuto_it' => '<p>Testo</p>']],
        ]],
    ]];

    $symfonyRequest = HttpRequest::create("/nova-api/{$uriKey}/{$model->id}", 'PUT', ['properties->config_detail' => $groups]);
    $request = NovaRequest::createFrom($symfonyRequest, new NovaRequest);

    $callback = $field->fill($request, $model);
    if (is_callable($callback)) {
        $callback();
    }
    $model->save();
}

it('stores an info_box group on EcTrack', function () {
    App::factory()->createQuietly();
    $track = EcTrack::factory()->create(['properties' => []]);

    $field = findConfigDetailField(new EcTrackResource($track), NovaRequest::create('/'));
    fillOneInfoBoxGroup($field, 'ec-tracks', $track);

    expect($track->fresh()->properties['config_detail'][0]['items'][0]['fields']['titolo_it'])->toBe('Nota');
});

it('stores an info_box group on EcPoi', function () {
    App::factory()->createQuietly();
    $poi = EcPoi::factory()->create(['properties' => []]);

    $field = findConfigDetailField(new EcPoiResource($poi), NovaRequest::create('/'));
    fillOneInfoBoxGroup($field, 'ec-pois', $poi);

    expect($poi->fresh()->properties['config_detail'][0]['items'][0]['fields']['titolo_it'])->toBe('Nota');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec laravel-camminiditalia php artisan test --filter=EcTrackEcPoiConfigDetailTest`
Expected: FAIL — `config_detail field not found` (both tests).

- [ ] **Step 3: Register the field on `wm-package/src/Nova/EcTrack.php`**

Add imports:

```php
use Laravel\Nova\Fields\Repeater\Repeater;
use Laravel\Nova\Panel;
use Whitecube\NovaFlexibleContent\Flexible;
use Wm\WmPackage\Nova\Flexible\ConfigDetail\InfoBoxItemRepeatable;
use Wm\WmPackage\Nova\Flexible\Resolvers\ConfigDetailResolver;
```

Append to the array returned by `fields()` (`wm-package/src/Nova/EcTrack.php:53-69`), after the `MorphToMany::make('Activities', ...)` entry:

```php
            Panel::make(__('Box Informativi'), [
                Flexible::make(__('Box Informativi'), 'properties->config_detail')
                    ->resolver(ConfigDetailResolver::class)
                    ->addLayout(__('Box Informativo'), 'info_box', [
                        Repeater::make(__('Items'), 'items')
                            ->repeatables([InfoBoxItemRepeatable::make()])
                            ->rules('required', 'array'),
                    ])
                    ->button(__('Aggiungi Box Informativo'))
                    ->confirmRemove(__('Sei sicuro di voler eliminare questo box?'), __('Elimina'), __('Annulla')),
            ])->collapsible(),
```

- [ ] **Step 4: Register the field on `wm-package/src/Nova/EcPoi.php`**

Same imports as Task 3/step above, added to `wm-package/src/Nova/EcPoi.php`. Append the identical `Panel::make(__('Box Informativi'), [...])` block to the array returned by `fields()` (`wm-package/src/Nova/EcPoi.php:47-69`), after the `Tab::group(__('Details'), ...)` entry.

- [ ] **Step 5: Run test to verify it passes**

Run: `docker exec laravel-camminiditalia php artisan test --filter=EcTrackEcPoiConfigDetailTest`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add wm-package/src/Nova/EcTrack.php wm-package/src/Nova/EcPoi.php wm-package/tests/Feature/Nova/EcTrackEcPoiConfigDetailTest.php
git commit -m "feat(oc:8181): register Box Informativi panel on EcTrack and EcPoi"
```

---

### Task 5: Authorization inheritance check (no role logic added by this feature)

**Files:**
- Test: `wm-package/tests/Feature/Nova/ConfigDetailAuthorizationInheritanceTest.php`

**Interfaces:**
- Consumes: the real, already-registered `App\Policies\EcPoiPolicy` (camminiditalia) — `update()` already returns `false` for any user with the `Validator` role (oc:8120), unrelated to this feature. No fake/stub policy is created; this test proves the new field adds no new allowance or restriction beyond what that existing policy already enforces on the whole resource.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Services\RolesAndPermissionsService;
use App\Models\User;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
});

it('blocks a Validator from persisting a config_detail box on EcPoi via the real Nova update endpoint, same as any other field', function () {
    App::factory()->createQuietly();
    $poi = EcPoi::factory()->create(['properties' => []]);

    $validator = User::factory()->create();
    $validator->assignRole('Validator');

    $groups = [[
        'layout' => 'info_box',
        'key' => 'new-group-1',
        'attributes' => ['items' => [
            ['type' => 'info-box-item', 'fields' => ['titolo_it' => 'Non autorizzato', 'contenuto_it' => '<p>x</p>']],
        ]],
    ]];

    $response = $this->actingAs($validator)
        ->putJson("/nova-api/ec-pois/{$poi->id}", ['properties->config_detail' => $groups]);

    expect($response->status())->toBeIn([403, 404, 422]);
    expect($poi->fresh()->properties['config_detail'] ?? null)->toBeNull();
});

it('allows an Administrator to persist a config_detail box on EcPoi via the real Nova update endpoint', function () {
    App::factory()->createQuietly();
    $poi = EcPoi::factory()->create(['properties' => []]);

    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    $groups = [[
        'layout' => 'info_box',
        'key' => 'new-group-1',
        'attributes' => ['items' => [
            ['type' => 'info-box-item', 'fields' => ['titolo_it' => 'Autorizzato', 'contenuto_it' => '<p>x</p>']],
        ]],
    ]];

    $response = $this->actingAs($admin)
        ->putJson("/nova-api/ec-pois/{$poi->id}", ['properties->config_detail' => $groups]);

    $response->assertOk();
    expect($poi->fresh()->properties['config_detail'][0]['items'][0]['fields']['titolo_it'])->toBe('Autorizzato');
});
```

- [ ] **Step 2: Run test to verify it fails or passes for the wrong reason**

Run: `docker exec laravel-camminiditalia php artisan test --filter=ConfigDetailAuthorizationInheritanceTest`
Expected: if Tasks 1-4 are complete, the Administrator case should already PASS (field inherits authorization automatically — Nova blocks/allows the whole update request before any individual field is filled) and the Validator case should already PASS too (EcPoiPolicy already existed before this feature). This task's purpose is to make that inherited behavior an explicit, permanent regression test — not to change any code.

- [ ] **Step 3: No implementation changes expected**

If either test fails, investigate: a failure here means the new field is being reached before Nova's `authorizedToUpdate()` gate — i.e., a canSee/canRun override was accidentally added to the Flexible field or Panel in Task 3/4. Remove any such override; do not add `canSee`/`canRun` to satisfy this test — the point is to have none.

- [ ] **Step 4: Run tests to confirm final state**

Run: `docker exec laravel-camminiditalia php artisan test --filter=ConfigDetailAuthorizationInheritanceTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
git add wm-package/tests/Feature/Nova/ConfigDetailAuthorizationInheritanceTest.php
git commit -m "test(oc:8181): lock in that Box Informativi inherits existing resource authorization"
```

---

## Self-Review

**Spec coverage** (against `overview.md` Requisiti):
- Flexible field + dedicated collapsible Panel on Layer/EcTrack/EcPoi → Tasks 3, 4
- Single `info_box` layout, generic dispatch for future layouts → Tasks 1, 3
- Per-locale dynamic titolo/contenuto fields, no `KeyValue` → Task 2
- No per-locale required validation → not enforced anywhere in Tasks 1-5 (no `->rules('required')` added to per-locale fields) — consistent with the requirement
- `properties.config_detail` storage shape → Tasks 1, 3 tests assert the exact shape
- `ConfigDetailResolver` not a naive mirror, sibling-preserving, no role logic → Task 1, verified via native Eloquent `fillJsonAttribute()` (documented in Task 1's code) rather than manual `Arr::set()` — functionally identical to what was agreed, simpler
- HTML sanitization on `contenuto` → Task 2
- Authorization inherited, no package-level role logic → Task 5
- No migration → confirmed, no task creates one

**Placeholder scan:** no TBD/TODO/"add validation"-style steps; every step has runnable code and an explicit command + expected result.

**Type consistency:** `ConfigDetailResolver::buildElement()` signature and the `info_box` dispatch key introduced in Task 1 are extended (not renamed) in Task 3; `InfoBoxItemRepeatable::key()` (`'info-box-item'`) from Task 2 is referenced identically in Task 3/4 test fixtures; attribute string `'properties->config_detail'` is identical across all tasks.
