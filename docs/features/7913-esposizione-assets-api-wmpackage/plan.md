> Ticket: oc:7913

# Plan — Esposizione assets API wm-package

## Task 1 — Crea branch in wm-package

```bash
cd wm-package
git checkout -b oc_7913
```

## Task 2 — Fix `getOrDownloadIcon` in `AppController`

File: `src/Http/Controllers/Api/AppController.php`

Sostituire il corpo del metodo `getOrDownloadIcon` da:

```php
protected function getOrDownloadIcon(App $app, $type = 'icon')
{
    if (! isset($app->$type)) {
        return response()->json(['code' => 404, 'error' => 'Not Found'], 404);
    }

    $mediaItem = $app->getMedia($type)->first();
    ...
```

a:

```php
protected function getOrDownloadIcon(App $app, $type = 'icon')
{
    $mediaItem = $app->getMedia($type)->first();

    if (! $mediaItem) {
        return response()->json(['code' => 404, 'error' => 'Not Found'], 404);
    }

    ...
```

Il resto del metodo (disk, path, readStream, response()->stream()) rimane invariato.

## Task 3 — Verifica PHPStan

```bash
cd wm-package
vendor/bin/phpstan analyse src/Http/Controllers/Api/AppController.php
```

✅ Eseguito: 13 errori trovati, tutti pre-esistenti su righe non modificate (93, 293, 365+). Nessun errore introdotto dal fix.

## Task 4 — Commit in wm-package

```bash
cd wm-package
git add src/Http/Controllers/Api/AppController.php
git commit -m "fix(oc:7913): use getMedia() instead of isset() in getOrDownloadIcon"
```
