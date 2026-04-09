# Design: Generic File Upload su S3

**Data:** 2026-04-09  
**Scope:** Pattern generico per upload di file da Nova su S3 (`wmfe`), applicato inizialmente al PDF di accessibilità su EcPoi/EcTrack.

---

## Problema

Il commit `c9778c92` ha introdotto `File::make()` per il PDF di accessibilità usando il disk `public` locale. L'obiettivo è:

1. Spostare il file su S3 (`wmfe`) seguendo il pattern esistente del wm-package
2. Definire un pattern **generico e riusabile** per qualsiasi upload di file futuro legato a un modello

---

## Decisioni

- **Approccio:** `StorageService::storeFile()` + `File::make()` con `fillUsing()` in Nova
- **Storage:** disk `wmfe` (S3/MinIO), stesso di PBF, feature-collection, tracks
- **Valore salvato in `properties`:** URL pubblico diretto (non path relativo)
- **Filename:** fisso, passato esplicitamente dal chiamante — non derivato dal nome originale del file uploadato
- **Estensione:** presa dal file uploadato
- **Rimpiazzo:** atomico dentro `storeFile()` — legge il valore precedente da `properties`, cancella il vecchio da S3, carica il nuovo
- **Cancellazione esplicita:** `->deletable()` su `File::make()` + `StorageService::deleteFile()`

---

## Path S3

```
/{shard}/{appId}/files/{model-type}/{id}/{filename}.{ext}
```

**Esempi:**
- `accessibility.pdf` su EcPoi 42, app 1: `/webmapp/1/files/ec-poi/42/accessibility.pdf`
- `accessibility.pdf` su EcTrack 99, app 1: `/webmapp/1/files/ec-track/99/accessibility.pdf`

**Parametri:**
- `{shard}` — da `config('wm-package.shard_name')`, default `webmapp`
- `{appId}` — da `$model->app_id`
- `{model-type}` — `Str::kebab(class_basename($model))` (es. `ec-poi`, `ec-track`)
- `{id}` — `$model->getKey()`
- `{filename}` — passato esplicitamente (es. `'accessibility'`)
- `{ext}` — estensione del file uploadato

Tutti i file futuri dello stesso modello finiscono in `/{shard}/{appId}/files/{model-type}/{id}/` — cartella per modello.

---

## `StorageService` — nuovi metodi

### `storeFile(Model $model, string $filename, UploadedFile $file): string`

1. Costruisce il path: `getShardBasePath($model->app_id) . "files/{type}/{id}/{filename}.{ext}"`
2. Legge il valore corrente di `properties->{filename}` dal modello
3. Se esiste un file precedente su `wmfe`, lo cancella
4. Carica il nuovo file su `wmfe`
5. Ritorna l'URL pubblico: `Storage::disk('wmfe')->url($path)`

### `deleteFile(Model $model, string $filename): void`

1. Costruisce il path con gli stessi parametri di `storeFile()`
2. Cancella il file da `wmfe` se esiste

---

## Nova — `AbstractEcResource`

Il `File::make()` esistente viene aggiornato:

```php
File::make(__('Accessibility PDF'), 'properties->accessibility_pdf')
    ->disk('wmfe')
    ->acceptedTypes('.pdf')
    ->rules('mimes:pdf')
    ->hideFromIndex()
    ->deletable()
    ->fillUsing(function ($request, $model, $attribute, $requestAttribute) {
        $file = $request->file($requestAttribute);
        if ($file === null) {
            return;
        }
        $url = app(StorageService::class)->storeFile($model, 'accessibility', $file);
        $props = $model->properties ?? [];
        $props['accessibility_pdf'] = $url;
        $model->properties = $props;
    })
    ->delete(function ($request, $model) {
        app(StorageService::class)->deleteFile($model, 'accessibility');
        $props = $model->properties ?? [];
        unset($props['accessibility_pdf']);
        $model->properties = $props;
    });
```

Il `->disk('wmfe')` sul `File::make()` è necessario affinché Nova sappia da quale disk leggere il file per mostrarlo nel form (download/preview link).

---

## API GeoJSON

Nessuna modifica a `GeoJsonService`. Il campo `accessibility_pdf` è già esposto flat in `properties` tramite `...$properties` nel commit `c9778c92`. Il valore è l'URL diretto pronto all'uso.

---

## Pattern per file futuri

Per aggiungere un nuovo file upload su qualsiasi modello EC:

```php
File::make(__('Nome Campo'), 'properties->nome_campo')
    ->disk('wmfe')
    ->acceptedTypes('.ext')
    ->rules('mimes:ext')
    ->hideFromIndex()
    ->deletable()
    ->fillUsing(function ($request, $model, $attribute, $requestAttribute) {
        $file = $request->file($requestAttribute);
        if ($file === null) return;
        $url = app(StorageService::class)->storeFile($model, 'nome-campo', $file);
        $props = $model->properties ?? [];
        $props['nome_campo'] = $url;
        $model->properties = $props;
    })
    ->delete(function ($request, $model) {
        app(StorageService::class)->deleteFile($model, 'nome-campo');
        $props = $model->properties ?? [];
        unset($props['nome_campo']);
        $model->properties = $props;
    });
```

---

## Cancellazione del modello

Quando un modello EC viene eliminato, tutti i file nella sua cartella S3 devono essere cancellati.

`StorageService` espone un metodo:

```php
public function deleteModelFiles(Model $model): void
```

Che cancella ricorsivamente `/{shard}/{appId}/files/{model-type}/{id}/` da `wmfe`.

Questo metodo viene chiamato nell'observer del modello (`deleting` event) — già esistente su EcPoi e EcTrack nel wm-package.

---

## Scope

- Modifica `StorageService` (wm-package) — aggiunta `storeFile()` e `deleteFile()`
- Modifica `AbstractEcResource` (wm-package) — aggiornamento `File::make()` per accessibility PDF
- Nessuna migrazione DB
- Nessuna modifica a `GeoJsonService`
