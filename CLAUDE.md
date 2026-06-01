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
| EC POI map icon display | oc:7645 | `src/Models/EcPoi.php`, `src/Nova/EcPoi.php`, `src/Http/Resources/RelatedEcPoiResource.php` | `show_image_on_map` in `feature_image` dei related_pois dell'EcTrack; checkbox readonly se il POI non ha immagini |

## Decisioni architetturali

### EC POI map icon display (oc:7645)
- `show_image_on_map` salvato in `properties` JSON di `EcPoi` — nessuna migration, il modello ha già il campo `properties` (jsonb)
- Nessun fallback sulla categoria POI (TaxonomyPoiType) — non esiste un caso d'uso reale per il default a livello categoria
- Il campo viene esposto solo in `RelatedEcPoiResource` (non in `EcPoiResource`) — API standalone EcPoi invariata
- `show_image_on_map` aggiunto dentro `feature_image` solo se `getMedia()->isNotEmpty()` — se l'EC non ha immagini, `feature_image` rimane null
- Nova field `->readonly()` quando il POI non ha media — evita che l'admin attivi un campo senza effetto
- Non chiamare `->toArray($request)` esplicitamente su `RelatedEcPoiResource` in `getRelatedPois()` — causa crash su `MediaResource(null)`. Mantenere il pattern `->toArray()` sulla collection

## Documentazione

La documentazione delle feature va in `docs/resources/`.

Esempi:
- `docs/resources/Analytics.md` — sistema PostHog analytics in Nova
- `docs/resources/TaxonomyWhere.md` — resource TaxonomyWhere
