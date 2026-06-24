> Ticket: oc:8041

# Plan — Import POI: associazione taxonomy type non funzionante

## Task

### 1. Crea branch

```bash
# in wm-package/
git checkout -b feature/oc-8041-import-poi-associazione-taxonomy-type-non-funzionante
```

### 2. Applica il fix

**File:** `src/Jobs/Import/ImportTaxonomyJob.php` — riga 16

Cambia:
```php
$recordsToImport = $this->geohubImportService->getTaxonomyMorphableRecords($this->getModelKey(), $this->entityId);
```

In:
```php
$recordsToImport = $this->geohubImportService->getTaxonomyMorphableRecords($this->getModelKey(), $model->properties['geohub_id']);
```

### 3. Verifica manuale

Avvia un import per APP ID 60 o 63 e controlla che gli EcPoi abbiano le taxonomy type associate al termine del job:

```bash
php artisan tinker --execute="
\$poi = \Wm\WmPackage\Models\EcPoi::first();
dd(\$poi->taxonomyPoiTypes()->pluck('name'));
"
```

### 4. Commit

```
fix(oc:8041): use geohub_id from model properties in processDependencies
```

### 5. PR verso `develop`

Apri PR da `feature/oc-8041-import-poi-associazione-taxonomy-type-non-funzionante` → `develop` nel repo `wm-package`.
