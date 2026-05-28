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

## Feature disponibili

| Feature | Ticket | Moduli toccati | Note |
|---|---|---|---|
| EC POI map icon display | oc:7645 | `src/Models/Abstracts/Taxonomy.php`, `src/Models/EcPoi.php`, `src/Nova/TaxonomyPoiType.php`, `src/Nova/EcPoi.php`, `src/Http/Resources/RelatedEcPoiResource.php` | `use_image_as_icon` in `feature_image` dei related_pois dell'EcTrack |

## Decisioni architetturali

### EC POI map icon display (oc:7645)
- `use_image_as_icon` salvato in `properties` JSON di `TaxonomyPoiType` e `EcPoi` — nessuna migration, entrambi i modelli hanno già il campo `properties` (jsonb)
- Il campo viene esposto solo in `RelatedEcPoiResource` (non in `EcPoiResource`) — API standalone EcPoi invariata
- `use_image_as_icon` aggiunto dentro `feature_image` solo se `getMedia()->isNotEmpty()` — se l'EC non ha immagini, `feature_image` rimane null e il frontend usa l'icona di default
- Non chiamare `->toArray($request)` esplicitamente su `RelatedEcPoiResource` in `getRelatedPois()` — causa crash su `MediaResource(null)`. Mantenere il pattern `->toArray()` sulla collection

## Documentazione

La documentazione delle feature va in `docs/resources/`.

Esempi:
- `docs/resources/Analytics.md` — sistema PostHog analytics in Nova
- `docs/resources/TaxonomyWhere.md` — resource TaxonomyWhere
