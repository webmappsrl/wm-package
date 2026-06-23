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

## Decisioni architetturali

### Fix mappa layer bounding box (oc:8093)
- Il dist `field.js` di `FeatureCollectionMap` va sempre ricompilato dalla sorgente verificata — il commit `fb3c0555` conteneva `"inline-geojson":A.field.geojson||null` nel template del `DetailFeatureCollectionMap` (non presente in `DetailField.vue`) perché compilato da una working copy locale non committata
- Prima di ogni `npm run prod` verificare che la sorgente `DetailField.vue` non abbia prop spurie; dopo la compilazione, grep `inline-geojson.*field.geojson` nel dist per controllo

## Decisioni architetturali

### Analytics Layer: selezione range temporale (oc:7648)
- WHERE per mesi usa `timestamp >= 'YYYY-MM-01' AND timestamp < 'YYYY-MM+1-01'` — `toYYYYMM`/`toUInt32` non supportati da PostHog HogQL
- `getTranslation('name', locale)` può restituire stringa vuota (non null) su EcTrack — usare cascade `['it', 'en', locale]` con `empty()` check
- `Collection->get($key)` invece di `$collection[$key]` per evitare `ErrorException` su chiavi assenti
- `webpack` pinnato a `^5.75.0` in `LayerAnalytics/package.json` per compatibilità con `laravel-mix@6`
- `LayerAnalyticsCard.php`: `created_at` arriva come stringa, non Carbon — usare `Carbon::parse()` invece di `?->format()`

## Feature disponibili

| Feature | Ticket | Moduli toccati | Note |
|---|---|---|---|
| Analytics Layer: selezione range temporale | oc:7648 | `src/Services/PostHog/AnalyticsService.php`, `src/Http/Controllers/Nova/AnalyticsController.php`, `src/Nova/Cards/LayerAnalytics/` | Dropdown 30/90/365gg + mesi da created_at; tabella download per traccia |
| EC POI map icon display | oc:7645 | `src/Models/EcPoi.php`, `src/Nova/EcPoi.php`, `src/Http/Resources/RelatedEcPoiResource.php` | `show_image_on_map` in `feature_image` dei related_pois dell'EcTrack; checkbox readonly se il POI non ha immagini |
| Fix mappa layer bounding box | oc:8093 | `src/Nova/Fields/FeatureCollectionMap/dist/js/field.js` | Dist ricompilato: rimossa prop `inline-geojson:field.geojson` introdotta per errore in `fb3c0555` (oc:7756) |
| Fix import Excel POI: nomi mancanti in pois.geojson | oc:8063 | `src/Imports/Processors/EcPoiRowProcessor.php`, `tests/Unit/Imports/Processors/EcPoiRowProcessorTest.php` | Sync `properties['name']` da `getTranslations('name')` in `apply()` — replica logica observer bypassata da `saveQuietly()` |
| ImportTaxonomyThemeJob | oc:8014 | `src/Jobs/Import/ImportTaxonomyThemeJob.php`, `src/Services/Import/GeohubImportService.php`, `config/wm-geohub-import.php` | Aggiunge il job mancante per importare TaxonomyTheme da GeoHub; registra taxonomy_theme in MODEL_IMPORT_ORDER e default_dependencies |
| Fix getTaxonomyMorphableRecords | oc:8013 | `src/Jobs/Import/ImportTaxonomyJob.php` | Corregge il parametro passato a getTaxonomyMorphableRecords: entityId (GeoHub) invece di model->id (Maphub) |
| Refactor SuperAdminService | oc:8006 | `src/Services/RolesAndPermissionsService.php`, `src/Support/SuperAdminService.php` (rimosso), `src/Nova/App.php`, `src/Nova/Actions/GenerateAppIconsAction.php`, `src/Nova/Actions/BuildAppPoisGeojsonAction.php`, `src/Policies/AppPolicy.php` | Sposta i check super-admin email-based in RolesAndPermissionsService; rimuove SuperAdminService |

## Decisioni architetturali

### EC POI map icon display (oc:7645)
- `show_image_on_map` salvato in `properties` JSON di `EcPoi` — nessuna migration, il modello ha già il campo `properties` (jsonb)
- Nessun fallback sulla categoria POI (TaxonomyPoiType) — non esiste un caso d'uso reale per il default a livello categoria
- Il campo viene esposto solo in `RelatedEcPoiResource` (non in `EcPoiResource`) — API standalone EcPoi invariata
- `show_image_on_map` aggiunto dentro `feature_image` solo se `getMedia()->isNotEmpty()` — se l'EC non ha immagini, `feature_image` rimane null
- Nova field `->readonly()` quando il POI non ha media — evita che l'admin attivi un campo senza effetto
- Non chiamare `->toArray($request)` esplicitamente su `RelatedEcPoiResource` in `getRelatedPois()` — causa crash su `MediaResource(null)`. Mantenere il pattern `->toArray()` sulla collection

### Fix import Excel POI nomi mancanti (oc:8063)
- `EcPoiRowProcessor::apply()` deve sincronizzare `properties['name']` da `getTranslations('name')` dopo il loop — `saveQuietly()` bypassa `AbstractObserver::saving()` che normalmente fa questa sync
- La logica è duplicata tra observer e processor: tech debt noto, accettato per mantenere il fix minimale
- In update mode il merge delle traduzioni è implicito: il modello caricato dal DB porta già tutte le lingue, `setTranslation` aggiorna solo la locale fornita
- `EcTrackRowProcessor` non è affetto: non usa `setTranslation` per il nome

### ImportTaxonomyThemeJob (oc:8014)
- `Log::error()` aggiunto nel catch di `handle()` rispetto al pattern originale di `ImportTaxonomyActivityJob` — senza di esso i fallimenti appaiono come "completed" in Horizon e sono impossibili da diagnosticare
- Campo `icon` **attivo** nel config `wm-geohub-import.php`: GeoHub ha la colonna `icon` in `taxonomy_themes` con formato SVG, identico a `taxonomy_activity`. Il transformer `svgIconToNameIcon` è corretto.
- `ImportTaxonomyJob::processDependencies()` usa `syncWithoutDetaching()` (non `sync()`): `sync()` azzerava tutte le associazioni del record lasciando solo l'ultimo tema importato — bug critico confermato su app 63.
- Dopo merge: i progetti con config già pubblicato devono aggiornare manualmente `default_dependencies['app']` in `wm-geohub-import.php` oppure ri-pubblicare con `--force`
- `taxonomy_when` e `taxonomy_target` hanno lo stesso problema (`'job' => ''`) — esclusi da questo ticket, servono ticket separati

### Fix getTaxonomyMorphableRecords (oc:8013)
- `processDependencies()` deve passare `$this->entityId` (ID GeoHub) a `getTaxonomyMorphableRecords()`, non `$model->id` (ID Maphub locale) — i due ID sono diversi e il metodo interroga il DB GeoHub

### Refactor SuperAdminService (oc:8006)
- I metodi `allows()`, `allowsUser()`, `allowsEmail()` vivono in `RolesAndPermissionsService` — punto unico per logica di autorizzazione nel package
- `SuperAdminService` è stata rimossa senza alias deprecato: breaking change da comunicare nel changelog prima di ogni rilascio
- I metodi sono statici (non DI) per coerenza con il pattern preesistente; la logica legge solo `config('wm-package.super_admin_emails')` senza stato interno

## Documentazione

La documentazione delle feature va in `docs/resources/`.

Esempi:
- `docs/resources/Analytics.md` — sistema PostHog analytics in Nova
- `docs/resources/TaxonomyWhere.md` — resource TaxonomyWhere
