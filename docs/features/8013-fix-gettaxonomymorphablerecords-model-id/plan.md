> Ticket: oc:8013

# Plan — Fix: getTaxonomyMorphableRecords usa model->id invece di entityId

## Repo coinvolto

`wm-package` (submodule) — tutto il codice va qui. Nessuna modifica al repo principale.

## Branch

```bash
git checkout -b feature/oc-8013-fix-gettaxonomymorphablerecords-model-id
```

Da eseguire dentro `wm-package/`.

## Step 1 — Applica il fix

**File:** `wm-package/src/Jobs/Import/ImportTaxonomyJob.php`

Sostituire riga 14:

```php
// Prima
$recordsToImport = $this->geohubImportService->getTaxonomyMorphableRecords($this->getModelKey(), $model->id);

// Dopo
$recordsToImport = $this->geohubImportService->getTaxonomyMorphableRecords($this->getModelKey(), $this->entityId);
```

Nessuna altra modifica al file.

## Step 2 — Verifica manuale

Con Tinker, confronta i risultati prima e dopo su una taxonomy con relazioni note:

```php
$t = \Wm\WmPackage\Models\TaxonomyActivity::first();
$service = app(\Wm\WmPackage\Services\Import\GeohubImportService::class);

// Deve essere 0 (bug)
echo $service->getTaxonomyMorphableRecords('taxonomy_activity', $t->id)->count();

// Deve essere > 0 (fix)
echo $service->getTaxonomyMorphableRecords('taxonomy_activity', $t->geohub_id)->count();
```

Prerequisito: IP whitelistato su GeoHub.

## Step 3 — Commit

```
fix(oc:8013): use entityId instead of model->id in getTaxonomyMorphableRecords call
```

## Step 4 — PR

Aprire PR verso `develop` nel repo `wm-package`.

## Note

- `ImportTaxonomyActivityJob` e `ImportTaxonomyPoiTypeJob` ereditano `processDependencies()` senza sovrascriverla — il fix si propaga automaticamente a entrambi.
- Dead code (`$syncData`) e log che usano `$model->id` rimangono invariati (out of scope).
- Eccezione silenziosa in `ImportTaxonomyActivityJob::handle()` → ticket separato da aprire.
