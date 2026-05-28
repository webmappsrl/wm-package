# wm-package — Note per Claude

## HasPackageFactory — trappola nelle classi figlio

Il trait `HasPackageFactory` (usato da tutti i modelli del package) risolve la factory tramite `get_called_class()`:

```php
$package = Str::before(get_called_class(), 'Models\\');  // es. "App\"
$path = $package.'Database\\Factories\\UgcPoiFactory';   // cerca "App\Database\Factories\UgcPoiFactory"
```

Se una classe figlia in un altro namespace (es. `App\Models\UgcPoi`) eredita questo trait, **`::factory()` non funziona** perché cerca una factory nel namespace della figlia, non del package.

**Soluzione:** sovrascrivere `newFactory()` nel modello figlio:

```php
protected static function newFactory(): Factory
{
    return \Wm\WmPackage\Database\Factories\UgcPoiFactory::new();
}
```

## Documentazione

La documentazione delle feature va in `docs/resources/`.

Esempi:
- `docs/resources/Analytics.md` — sistema PostHog analytics in Nova
- `docs/resources/TaxonomyWhere.md` — resource TaxonomyWhere
