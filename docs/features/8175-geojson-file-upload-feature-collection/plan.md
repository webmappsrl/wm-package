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
