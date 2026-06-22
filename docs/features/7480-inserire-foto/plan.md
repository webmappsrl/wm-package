> Ticket: oc:7480

# Piano implementativo — [wm-package][app] inserire foto

Tutti i file sono in **wm-package**. Nessuna modifica a camminiditalia (eredita automaticamente).
Commit convention: `feat(oc:7480): ...`

---

## Step 1 — App model: registra le media collection

**File:** `src/Models/App.php`

In `registerMediaCollections()` aggiungere dopo `$this->addMediaCollection('splash')`:

```php
$this->addMediaCollection('my_paths');
$this->addMediaCollection('my_downloads');
```

---

## Step 2 — Nova: aggiungi i campi Images nella tab Release

**File:** `src/Nova/App.php`

In `app_release_data_tab()` aggiungere dopo il campo `icon_small`:

```php
Images::make(__('My paths image'), 'my_paths')
    ->help(__('Required size is :widthx:heightpx', ['width' => 2214, 'height' => 1013]))
    ->hideFromIndex(),
Images::make(__('My downloads image'), 'my_downloads')
    ->help(__('Required size is :widthx:heightpx', ['width' => 2214, 'height' => 1013]))
    ->hideFromIndex(),
```

---

## Step 3 — Controller: aggiungi i metodi myPaths() e myDownloads()

**File:** `src/Http/Controllers/Api/AppController.php`

Aggiungere dopo il metodo `logoHomepage()`:

```php
public function myPaths(App $app)
{
    return $this->getOrDownloadIcon($app, 'my_paths');
}

public function myDownloads(App $app)
{
    return $this->getOrDownloadIcon($app, 'my_downloads');
}
```

**Aggiornare `getOrDownloadIcon()`** — sostituire il check `isset` con uno basato su Spatie e aggiungere null-check sul media item:

```php
protected function getOrDownloadIcon(App $app, $type = 'icon')
{
    $mediaItem = $app->getMedia($type)->first();

    if (! $mediaItem) {
        return response()->json(['code' => 404, 'error' => 'Not Found'], 404);
    }

    $disk = StorageService::make()->getMediaDisk();
    $path = $mediaItem->getPath();

    if ($disk->getConfig()['driver'] === 'local') {
        $diskRoot = rtrim($disk->path(''), '/');
        $relativePath = str_replace($diskRoot, '', $path);
        $relativePath = ltrim($relativePath, '/');
        $relativePath = preg_replace('/\/+/', '/', $relativePath);
        $path = $relativePath;
    }

    $file = $disk->readStream($path);

    if (! $file) {
        return response()->json(['code' => 404, 'error' => 'File not found'], 404);
    }

    return response()->stream(function () use ($file) {
        fpassthru($file);
    }, 200, [
        'Content-Type' => $mediaItem->mime_type,
        'Content-Disposition' => 'attachment; filename="'.$mediaItem->file_name.'"',
        'Content-Length' => $mediaItem->size,
    ]);
}
```

Note:
- Rimosso `isset($app->$type)` — non funziona per media collection Spatie
- Sostituito `getCustomProperty('mime-type')` con `$mediaItem->mime_type` (attributo nativo Spatie)
- Aggiunto null-check esplicito su `$mediaItem`

---

## Step 4 — Route: aggiungi le route nei tre gruppi

**File:** `routes/api.php`

**Gruppo `elbrus`** — aggiungere dopo la route `feature_image`:
```php
Route::get('/{app}/resources/my_paths.png', [AppController::class, 'myPaths'])->name('my_paths');
Route::get('/{app}/resources/my_downloads.png', [AppController::class, 'myDownloads'])->name('my_downloads');
```

**Gruppo `webmapp`** — aggiungere dopo la route `logo_homepage`:
```php
Route::get('/{app}/resources/my_paths.png', [AppController::class, 'myPaths'])->name('my_paths');
Route::get('/{app}/resources/my_downloads.png', [AppController::class, 'myDownloads'])->name('my_downloads');
```

**Gruppo `v2/app/webmapp`** — aggiungere dopo la route `logo_homepage`:
```php
Route::get('/{id}/resources/my_paths.png', [AppController::class, 'myPaths'])->name('my_paths');
Route::get('/{id}/resources/my_downloads.png', [AppController::class, 'myDownloads'])->name('my_downloads');
```

---

## Step 5 — AppConfigService: includi le URL in config.json

**File:** `src/Services/Models/App/AppConfigService.php`

In `config_section_app()` aggiungere prima del `return $data`:

```php
if ($this->app->getMedia('my_paths')->isNotEmpty()) {
    $data['APP']['my_paths'] = $this->app->getFirstMediaUrl('my_paths');
}
if ($this->app->getMedia('my_downloads')->isNotEmpty()) {
    $data['APP']['my_downloads'] = $this->app->getFirstMediaUrl('my_downloads');
}
```

Nota: si usa `getFirstMediaUrl()` di Spatie (URL diretta al file su S3/local) invece di `route()` — evita il conflitto di naming tra i due gruppi `webmapp` che condividono `->name('webmapp.')`.

---

## Step 6 — Traduzioni

**File:** `resources/lang/en.json` e `resources/lang/it.json`

Le chiavi da aggiungere in `en.json`:
```json
"My paths image": "My paths image",
"My downloads image": "My downloads image"
```

Le chiavi da aggiungere in `it.json`:
```json
"My paths image": "Immagine i miei percorsi",
"My downloads image": "Immagine i miei download"
```

Nota: `"Required size is :widthx:heightpx"` esiste già in entrambi i file — non va duplicata.

---

## Step 7 — Test: route 200/404 e config.json

**File:** da creare in `tests/Feature/` — es. `AppMyPathsMyDownloadsTest.php`

Test da coprire:
1. `GET /{app}/resources/my_paths.png` → 404 se nessuna immagine caricata
2. `GET /{app}/resources/my_paths.png` → 200 con `Content-Type` corretto se immagine caricata
3. `GET /{app}/resources/my_downloads.png` → 404 / 200 analogo
4. `config.json` NON contiene `APP.my_paths` se nessuna immagine caricata
5. `config.json` contiene `APP.my_paths` con URL assoluta se immagine caricata
6. `config.json` contiene `APP.my_downloads` con URL assoluta se immagine caricata

Pattern di riferimento: guardare i test esistenti per `splash` e `icon` in `tests/Feature/`.

---

## Step 8 — Commit

```
feat(oc:7480): add my_paths and my_downloads image fields to App release data
```

Includere in un unico commit tutti i file modificati (model, Nova, controller, routes, service, traduzioni, tests).
