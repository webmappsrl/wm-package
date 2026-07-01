> Ticket: oc:8175

# Plan — Add GeoJSON file upload for FeatureCollection upload mode

## Task 1 — Aggiungere il campo File a `FeatureCollection.php` ✅

**File:** `src/Nova/FeatureCollection.php`

Import aggiunto: `File`, `StorageService`.

Campo aggiunto nel panel `Source`:

```php
File::make(__('GeoJSON File'), 'file_path')
    ->disk('wmfe')
    ->acceptedTypes('.geojson,.json')
    ->rules('max:20480')
    ->hideFromIndex()
    ->nullable()
    ->help(__('Only used in upload mode'))
    ->store(function ($request, $model, $attribute, $requestAttribute) {
        if ($request->input('mode') !== 'upload') {
            return null;
        }
        $file = $request->file($requestAttribute);
        if ($file === null || $model->id === null) {
            return null;
        }
        $path = app(StorageService::class)->storeFeatureCollection(
            $model->app_id,
            $model->id,
            $file->get()
        );
        return $path ?: null;
    }),
```

Metodo `afterCreate` aggiunto per gestire l'upload in Create (quando `$model->id` è null nel callback):

```php
public static function afterCreate(NovaRequest $request, Model $model): void
{
    if ($request->input('mode') !== 'upload' || ! $request->hasFile('file_path')) {
        return;
    }
    $path = app(StorageService::class)->storeFeatureCollection(
        $model->app_id, $model->id, $request->file('file_path')->get()
    );
    if ($path) {
        $model->update(['file_path' => $path]);
    }
}
```

**Commit:** `feat(oc:8175): add GeoJSON file upload field to FeatureCollection Nova resource`

---

## Task 2 — php.ini upload limit ✅

**File:** `docker/configs/phpfpm/php.ini` (repo forestas)

Aggiunto `upload_max_filesize = 20M` per supportare GeoJSON fino a 20MB.

**Commit:** `feat(oc:8175): increase upload_max_filesize to 20M for GeoJSON uploads`

---

## Task 3 — Verifica manuale in Nova ✅

1. Edit su FC esistente con `mode: upload` → campo GeoJSON File visibile → upload → `file_path` impostato su MinIO ✓
2. Create nuova FC con `mode: upload` e file allegato → `afterCreate` processa il file → `file_path` impostato ✓
3. Download dal detail view → funziona tramite `->disk('wmfe')` ✓
4. Upload con `mode !== 'upload'` → guardia server-side ignora il file ✓

---

## Ciclo 2 — Fix PR#238

### Task 4 — Fix `->store()` callback: return `true` nei guard ✅

**File:** `src/Nova/FeatureCollection.php`

Il callback ritornava `null` nei casi di guard, causando la sovrascrittura di `file_path` con NULL su update.
Tutti e tre i `return null` sostituiti con `return true` (Nova non tocca l'attributo):

- `mode !== 'upload'` → `return true`
- `file === null || model->id === null` → `return true`
- `storeFeatureCollection() === false` → `return true`

**Commit:** `fix(oc:8175): return true in store guard cases to preserve existing file_path`

---

### Task 5 — Test coverage ✅

**File:** `tests/Feature/Nova/FeatureCollectionUploadTest.php`

Quattro test con `DatabaseTransactions` + `Storage::fake('wmfe')`:

1. `store callback returns true when mode is not upload` — guard mode
2. `store callback returns true when mode is upload but no file is attached` — guard no-file
3. `store callback stores the file and returns its path when mode is upload with a file` — happy path update
4. `afterCreate stores the file and updates file_path in the database` — create flow

`storageCallback` è `public` su `Laravel\Nova\Fields\File` → accesso diretto, reflection non necessaria.

**Commit:** `test(oc:8175): add upload behavior tests for FeatureCollection Nova resource`

---

## Ciclo 3 — Cleanup review PR#238 (seconda iterazione)

### Task 6 — Log::warning in store callback su update failure ✅

**File:** `src/Nova/FeatureCollection.php`

Storage failure silenzioso in update: `return $path ?: true` non lasciava nessuna traccia diagnosticabile. Aggiunto `Log::warning('FeatureCollection GeoJSON upload failed', ['fc_id' => ...])` prima del `return true` nel caso negativo. Il callback ora ritorna esplicitamente `$path` nel caso positivo per maggiore chiarezza.

### Task 7 — Cleanup test file ✅

**File:** `tests/Feature/Nova/FeatureCollectionUploadTest.php`

- Helper `makeApp()` estratto per `App::factory()->createQuietly(['overlays_label' => 'Layers'])` (era ripetuto 4 volte)
- Path hardcoded `99` sostituiti con `$fc->id` nei test 1 e 2 (creazione FC separata da impostazione `file_path`)
- Aggiunto 5° test: `afterCreate throws RuntimeException when storage fails` — usa `Mockery` per simulare `storeFeatureCollection()` che ritorna `false`
