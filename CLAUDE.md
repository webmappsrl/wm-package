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

### Fix import POI taxonomy type (oc:8041)
- `processDependencies()` usa `$model->properties['geohub_id']` come ID autoritativo per `getTaxonomyMorphableRecords()` — `$this->entityId` può divergere in scenari di re-import
- `taxonomy_poi_types` aggiunto a `default_dependencies.app` in `wm-geohub-import.php` — era assente a differenza di `taxonomy_activity` e `taxonomy_theme`, rendendo il fix ID ininfluente nel flusso standard
- Timing del dispatch (taxonomy prima degli EcPoi) è un rischio noto ma lasciato fuori scope — da riaprire se il bug persiste dopo questo fix

### Inserire foto — my_paths e my_downloads (oc:7480)
- `getOrDownloadIcon()` usava `isset($app->$type)` che restituisce sempre `false` per le media collection Spatie (non sono attributi Eloquent) — sostituito con `$app->getMedia($type)->first()` + null-check esplicito
- `$mediaItem->mime_type` (attributo nativo Spatie) al posto di `getCustomProperty('mime-type')` che può restituire `null`
- `($disk->getConfig()['driver'] ?? 'local')`: il fake disk nei test non ha la chiave `driver` nel config — il default `local` è corretto semanticamente
- URL in `config.json` via `getFirstMediaUrl()` invece di `route()`: evita il conflitto di naming tra i due gruppi `webmapp` che condividono `->name('webmapp.')` in `routes/api.php`
- **Naming chiave config.json in camelCase**: nel `config.json` le chiavi sono `APP.myPaths`/`APP.myDownloads` (contratto col frontend), mentre media collection Spatie (`getMedia('my_paths')`), route API (`/resources/my_paths.png`, `->name('my_paths')`) e attributi campi Nova restano in snake_case
- `icon_notify` e `logo_homepage` hanno route e metodi controller ma **non** hanno `registerMediaCollections()` né campi Nova — non usarli come pattern di riferimento

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
| BulkEditAction: bulk edit dinamico da Nova Resource | oc:8133 | `src/Nova/Actions/BulkEditAction.php`, `tests/Unit/Nova/Actions/BulkEditActionTest.php`, `tests/Feature/Nova/Actions/BulkEditActionFeatureTest.php` | Action parametrica: `new BulkEditAction(Resource::class, ['field'])` — filtra campi da Resource, appiattisce Panel/Tab, `saveQuietly()` in `DB::transaction()` |
| Analytics Layer: selezione range temporale | oc:7648 | `src/Services/PostHog/AnalyticsService.php`, `src/Http/Controllers/Nova/AnalyticsController.php`, `src/Nova/Cards/LayerAnalytics/` | Dropdown 30/90/365gg + mesi da created_at; tabella download per traccia |
| Inserire foto (my_paths, my_downloads) | oc:7480 | `src/Models/App.php`, `src/Nova/App.php`, `src/Http/Controllers/Api/AppController.php`, `routes/api.php`, `src/Services/Models/App/AppConfigService.php` | Media collection + Nova fields + route nei 3 gruppi + URL in APP section del config.json; fix getOrDownloadIcon() (isset→getMedia, mime_type, driver null-safe) |
| EC POI map icon display | oc:7645 | `src/Models/EcPoi.php`, `src/Nova/EcPoi.php`, `src/Http/Resources/RelatedEcPoiResource.php` | `show_image_on_map` in `feature_image` dei related_pois dell'EcTrack; checkbox readonly se il POI non ha immagini |
| Fix mappa layer bounding box | oc:8093 | `src/Nova/Fields/FeatureCollectionMap/dist/js/field.js` | Dist ricompilato: rimossa prop `inline-geojson:field.geojson` introdotta per errore in `fb3c0555` (oc:7756) |
| Fix import Excel POI: nomi mancanti in pois.geojson | oc:8063 | `src/Imports/Processors/EcPoiRowProcessor.php`, `tests/Unit/Imports/Processors/EcPoiRowProcessorTest.php` | Sync `properties['name']` da `getTranslations('name')` in `apply()` — replica logica observer bypassata da `saveQuietly()` |
| ImportTaxonomyThemeJob | oc:8014 | `src/Jobs/Import/ImportTaxonomyThemeJob.php`, `src/Services/Import/GeohubImportService.php`, `config/wm-geohub-import.php` | Aggiunge il job mancante per importare TaxonomyTheme da GeoHub; registra taxonomy_theme in MODEL_IMPORT_ORDER e default_dependencies |
| Dipendenza visiva auth → geolocalizzazione in Nova | oc:7852 | `src/Nova/App.php`, `resources/lang/it.json` | HTML field `onlyOnDetail()` con valore calcolato; `mobileAuthDependent()` mantiene grayed-out in edit; Boolean `onlyOnForms()` evita duplicazione in detail |
| Fix import POI taxonomy type | oc:8041 | `src/Jobs/Import/ImportTaxonomyJob.php` | `processDependencies()` usa `$model->properties['geohub_id']` invece di `$this->entityId` per chiamare `getTaxonomyMorphableRecords()` |
| Fix getTaxonomyMorphableRecords | oc:8013 | `src/Jobs/Import/ImportTaxonomyJob.php` | Corregge il parametro passato a getTaxonomyMorphableRecords: entityId (GeoHub) invece di model->id (Maphub) |
| Refactor SuperAdminService | oc:8006 | `src/Services/RolesAndPermissionsService.php`, `src/Support/SuperAdminService.php` (rimosso), `src/Nova/App.php`, `src/Nova/Actions/GenerateAppIconsAction.php`, `src/Nova/Actions/BuildAppPoisGeojsonAction.php`, `src/Policies/AppPolicy.php` | Sposta i check super-admin email-based in RolesAndPermissionsService; rimuove SuperAdminService |
| Fix esposizione assets API | oc:7913 | `src/Http/Controllers/Api/AppController.php` | `getOrDownloadIcon` usa `getMedia()->first()` invece di `isset($app->$type)` — fix 404 su app con media in Spatie e colonne null |
| Modifica ruolo utente in Nova | oc:8072 | `src/Nova/AbstractUserResource.php`, `tests/Feature/Nova/AbstractUserResourceRoleGuardTest.php` | Guard `RolesAndPermissionsService::allowsUser()` su ruoli/permessi; fillUsing server-side; anti-self-demotion |

## Decisioni architetturali

### BulkEditAction (oc:8133)
- Contratto: `new BulkEditAction(Resource::class, $fields = [], $exclude = ['name', 'geometry', 'description'])` — `$exclude` default protegge da bulk edit accidentale di nome, geometria e descrizione; override con `[]` per disabilitare
- `BelongsToMany`/`MorphToMany` implementano `ListableField`, non `RelatableField` — il filtro deve controllare entrambe le interfacce per escludere tutti i campi relazionali
- Readonly dinamico (closure): azzerare con `->readonly(false)` prima di restituire il campo — evalutare la closure su `::newModel()` (modello vuoto) darebbe sempre `true` per field tipo `getMedia()->isEmpty()`
- `showOnUpdate === false` come criterio di esclusione: corregge il mismatch tra `ActionRequest` e `UpdateRequest` in `fields()` 
- Attributi piatti: `forceFill([$attribute => $value])`. Path arrow notation (`properties->*`): merge esplicito — legge l'array corrente della colonna, `Arr::set()` sul solo path, riassegna l'intero array così i sibling non modificati restano invariati
- Nova serializza i campi `properties->*` come oggetto annidato sotto `properties` in `ActionFields` (il `Fluent::forceFill()` di Nova converte `->` in path dot e fa `Arr::set()`), NON come chiavi letterali `properties->*`. `handle()` non deve iterare le chiavi top-level di `ActionFields` (tratterebbe `properties` come unico valore e sovrascriverebbe il JSON). Usare `resolveChanges()`: cicla sui campi di `fields()` e legge ognuno con `array_key_exists` (flat) o `data_get()` con notazione dot (annidato)
- Test wm-package che girano con `php artisan test` di camminiditalia devono usare `Tests\TestCase` (non `Wm\WmPackage\Tests\TestCase`) — quest'ultima non è in `autoload-dev` di camminiditalia

### Fix esposizione assets API (oc:7913)
- `getOrDownloadIcon` usa `getMedia($type)->first()` come unica fonte di verità — `isset($app->$type)` controllava la colonna DB che su Maphub è sempre null (upload via Spatie Media Library)
- Nessun fallback sulla colonna: app che hanno solo la colonna valorizzata (senza media in Spatie) ricevono 404 — comportamento atteso e diagnosticabile
- `getCustomProperty('mime-type')` può restituire null (custom property non salvata al caricamento); il campo nativo corretto è `$mediaItem->mime_type` — fix separato tracciato in oc:8122

### Modifica ruolo utente in Nova (oc:8072)
- `RoleBooleanGroup` e `PermissionBooleanGroup` in `AbstractUserResource` usano `RolesAndPermissionsService::allowsUser()` come guard — nessuna email hardcodata
- `fillUsing()` server-side su entrambi i campi: blocca la persistenza se `allowsUser()` restituisce `false` — il guard non è solo visivo
- Anti-self-demotion: `fillUsing` di `RoleBooleanGroup` forza il ruolo Administrator nell'utente corrente se sta modificando se stesso
- Il package NON gestisce `hideFromIndex()` — ogni progetto deve farlo tramite override `fields()` in `User.php`
- `PermissionBooleanGroup` usa `dependsOn('roles')` per mostrare solo i permessi rilevanti (es. `validate %` per Validator, `manage roles and permissions` per Administrator)

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

### Dipendenza visiva auth → geolocalizzazione (oc:7852)
- In detail view: `Text::make(..., fn() => ...)->asHtml()->onlyOnDetail()` mostra icona verde se `auth_show_at_startup && geolocation_record_enable`, rossa altrimenti — heroicons 24/solid `w-6 h-6`, stesso rendering dei Boolean nativi Nova
- In edit/create: `mobileAuthDependent()` mantiene il grayed-out (`->readonly(true)`) quando `auth_show_at_startup=false`; Boolean con `->onlyOnForms()` (non `hideFromIndex()`) per evitare duplicazione in detail
- `webappAuthDependent()` non creato: rimandato a quando esisterà un campo dipendente reale nel tab Webapp
- `AppConfigService` non modificato: il config generato riflette sempre il valore reale nel DB

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
