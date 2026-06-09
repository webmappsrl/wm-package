> Ticket: oc:8014

# Plan — ImportTaxonomyThemeJob

## Repo coinvolto

Tutto il codice va in **`wm-package`**. Il repo principale `maphub` non viene toccato.

---

## Step 1 — Crea il branch

```bash
cd wm-package
git checkout -b feature/oc-8014-import-taxonomy-theme-job
```

---

## Step 2 — Crea `ImportTaxonomyThemeJob`

**File:** `wm-package/src/Jobs/Import/ImportTaxonomyThemeJob.php`

Modella il file su `ImportTaxonomyActivityJob`. Differenze rispetto al pattern:
- `getModelKey()` → `'taxonomy_theme'`
- `getForeignKey()` → `'taxonomy_theme_id'`
- `getRelationshipName()` → `'taxonomyThemes'`
- Nel catch di `handle()` aggiungere `Log::error()` per rendere i fallimenti visibili nei log senza far fallire il job (mitigazione rilevata in Challenge)

```php
<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Services\Import\GeohubImportService;

class ImportTaxonomyThemeJob extends ImportTaxonomyJob
{
    public $timeout = 300;

    public function getModelKey(): string
    {
        return parent::getModelKey().'theme';
    }

    protected function getForeignKey(): string
    {
        return 'taxonomy_theme_id';
    }

    protected function getRelationshipName(): string
    {
        return 'taxonomyThemes';
    }

    public function handle(GeohubImportService $importService): void
    {
        try {
            parent::handle($importService);
        } catch (\Exception $e) {
            Log::error('ImportTaxonomyThemeJob failed: '.$e->getMessage(), [
                'entity_id' => $this->entityId ?? null,
            ]);
        }
    }
}
```

---

## Step 3 — Aggiorna `GeohubImportService`

**File:** `wm-package/src/Services/Import/GeohubImportService.php`

Aggiungere `'taxonomy_theme'` in `MODEL_IMPORT_ORDER` dopo `taxonomy_activity` e prima di `ec_poi`:

```php
protected const MODEL_IMPORT_ORDER = [
    'app',
    'taxonomy_activity',
    'taxonomy_theme',   // ← aggiunto
    'taxonomy_poi_types',
    'ec_poi',
    'ec_track',
    'layer',
    'ec_media',
];
```

---

## Step 4 — Aggiorna `wm-geohub-import.php`

**File:** `wm-package/config/wm-geohub-import.php`

### 4a — Aggiunge `ImportTaxonomyThemeJob` all'use in cima al file

Verificare che l'import della classe sia presente tra gli `use` in testa al file (stesso pattern degli altri job).

### 4b — Popola `'job'` per `taxonomy_theme`

```php
'taxonomy_theme' => [
    // ...
    'job' => ImportTaxonomyThemeJob::class,
    // ...
```

### 4c — Popola `'fields'` per `taxonomy_theme`

```php
'fields' => [
    'name'        => ['field' => 'name',        'transformer' => [DataTransformer::class, 'jsonToArray']],
    'description' => ['field' => 'description', 'transformer' => [DataTransformer::class, 'jsonToArray']],
    'excerpt'     => ['field' => 'excerpt',     'transformer' => [DataTransformer::class, 'jsonToArray']],
    'identifier'  => 'identifier',
    'created_at'  => 'created_at',
    'updated_at'  => 'updated_at',
    // 'icon' => ['field' => 'icon', 'transformer' => [DataTransformer::class, 'svgIconToNameIcon']],
    // ↑ DA DECIDERE: verificare con il capo se GeoHub ha la colonna `icon` in taxonomy_themes
    //   e se il formato è SVG (come taxonomy_activity) prima di decommentare.
],
```

### 4d — Aggiunge `taxonomy_theme` a `default_dependencies`

```php
'default_dependencies' => [
    'app' => ['ec_poi', 'ec_track', 'taxonomy_activity', 'taxonomy_theme', 'layer', 'ec_media'],
],
```

---

## Step 5 — Verifica pre-test

Prima di testare su un ambiente con GeoHub accessibile:

- [ ] Verificare che la migrazione per `taxonomy_themes` sia pubblicata ed eseguita nel progetto target (`php artisan migrate:status | grep taxonomy_theme`)
- [ ] Assicurarsi che il proprio IP sia nella whitelist del firewall GeoHub
- [ ] Dopo il merge nel package, i progetti che hanno già pubblicato `wm-geohub-import.php` devono aggiornare manualmente `default_dependencies` oppure ri-pubblicare il config con `php artisan vendor:publish --tag=wm-package-config --force`

---

## Step 6 — Commit

```
feat(oc:8014): add ImportTaxonomyThemeJob and register in import order
```

Include tutti e tre i file modificati: job, service, config.

---

## Step 5b — Crea `TaxonomyTheme` Nova resource in wm-package

**File:** `wm-package/src/Nova/TaxonomyTheme.php`

Segue il pattern di `TaxonomyActivity`:

```php
<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Http\Requests\NovaRequest;

class TaxonomyTheme extends AbstractTaxonomyResource
{
    public static $model = \Wm\WmPackage\Models\TaxonomyTheme::class;

    public static $title = 'name';

    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
            MorphToMany::make('Tracks Associate', 'ecTracks', EcTrack::class)
                ->display('name'),
        ];
    }
}
```

---

## Step 5c — Crea stub `TaxonomyTheme` in maphub

**File:** `maphub/app/Nova/TaxonomyTheme.php`

```php
<?php

namespace App\Nova;

use Wm\WmPackage\Nova\TaxonomyTheme as WmTaxonomyTheme;

class TaxonomyTheme extends WmTaxonomyTheme {}
```

---

## Step 5d — Registra `TaxonomyTheme` nel menu Nova di maphub

**File:** `maphub/app/Providers/NovaServiceProvider.php`

Aggiungere `use App\Nova\TaxonomyTheme;` tra gli import e `MenuItem::resource(TaxonomyTheme::class)` nella sezione `Taxonomies`.

---

## Step 7 — PR

Apri PR da `feature/oc-8014-import-taxonomy-theme-job` verso `develop` in `wm-package`.
Apri PR da `feature/oc-8014-import-taxonomy-theme-job` verso `develop` in `maphub`.

Descrizione PR: richiama i rischi noti (icon da decidere, config pubblicato da aggiornare nei progetti).
