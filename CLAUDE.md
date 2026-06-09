# wm-package — CLAUDE.md

## Feature disponibili

| Feature | Ticket | Moduli toccati | Note |
|---|---|---|---|
| Fix import Excel POI: nomi mancanti in pois.geojson | oc:8063 | `src/Imports/Processors/EcPoiRowProcessor.php`, `tests/Unit/Imports/Processors/EcPoiRowProcessorTest.php` | Sync `properties['name']` da `getTranslations('name')` in `apply()` — replica logica observer bypassata da `saveQuietly()` |
| ImportTaxonomyThemeJob | oc:8014 | `src/Jobs/Import/ImportTaxonomyThemeJob.php`, `src/Services/Import/GeohubImportService.php`, `config/wm-geohub-import.php` | Aggiunge il job mancante per importare TaxonomyTheme da GeoHub; registra taxonomy_theme in MODEL_IMPORT_ORDER e default_dependencies |
| Fix getTaxonomyMorphableRecords | oc:8013 | `src/Jobs/Import/ImportTaxonomyJob.php` | Corregge il parametro passato a getTaxonomyMorphableRecords: entityId (GeoHub) invece di model->id (Maphub) |
| ImportTaxonomyThemeJob | oc:8014 | `src/Jobs/Import/ImportTaxonomyThemeJob.php`, `src/Services/Import/GeohubImportService.php`, `config/wm-geohub-import.php` | Aggiunge il job mancante per importare TaxonomyTheme da GeoHub; registra taxonomy_theme in MODEL_IMPORT_ORDER e default_dependencies |
| Refactor SuperAdminService | oc:8006 | `src/Services/RolesAndPermissionsService.php`, `src/Support/SuperAdminService.php` (rimosso), `src/Nova/App.php`, `src/Nova/Actions/GenerateAppIconsAction.php`, `src/Nova/Actions/BuildAppPoisGeojsonAction.php`, `src/Policies/AppPolicy.php` | Sposta i check super-admin email-based in RolesAndPermissionsService; rimuove SuperAdminService |

## Decisioni architetturali

### Fix import Excel POI nomi mancanti (oc:8063)
- `EcPoiRowProcessor::apply()` deve sincronizzare `properties['name']` da `getTranslations('name')` dopo il loop — `saveQuietly()` bypassa `AbstractObserver::saving()` che normalmente fa questa sync
- La logica è duplicata tra observer e processor: tech debt noto, accettato per mantenere il fix minimale
- In update mode il merge delle traduzioni è implicito: il modello caricato dal DB porta già tutte le lingue, `setTranslation` aggiorna solo la locale fornita
- `EcTrackRowProcessor` non è affetto: non usa `setTranslation` per il nome

### ImportTaxonomyThemeJob (oc:8014)
- `Log::error()` aggiunto nel catch di `handle()` rispetto al pattern originale di `ImportTaxonomyActivityJob` — senza di esso i fallimenti appaiono come "completed" in Horizon e sono impossibili da diagnosticare
- Campo `icon` commentato nel config `wm-geohub-import.php` con TODO: verificare con il capo se GeoHub ha la colonna `icon` in `taxonomy_themes` prima di attivarlo
- Dopo merge: i progetti con config già pubblicato devono aggiornare manualmente `default_dependencies['app']` in `wm-geohub-import.php` oppure ri-pubblicare con `--force`
- `taxonomy_when` e `taxonomy_target` hanno lo stesso problema (`'job' => ''`) — esclusi da questo ticket, servono ticket separati

### Fix getTaxonomyMorphableRecords (oc:8013)
- `processDependencies()` deve passare `$this->entityId` (ID GeoHub) a `getTaxonomyMorphableRecords()`, non `$model->id` (ID Maphub locale) — i due ID sono diversi e il metodo interroga il DB GeoHub

### ImportTaxonomyThemeJob (oc:8014)
- `Log::error()` aggiunto nel catch di `handle()` rispetto al pattern originale di `ImportTaxonomyActivityJob` — senza di esso i fallimenti appaiono come "completed" in Horizon e sono impossibili da diagnosticare
- Campo `icon` commentato nel config `wm-geohub-import.php` con TODO: verificare con il capo se GeoHub ha la colonna `icon` in `taxonomy_themes` prima di attivarlo
- Dopo merge: i progetti con config già pubblicato devono aggiornare manualmente `default_dependencies['app']` in `wm-geohub-import.php` oppure ri-pubblicare con `--force`
- `taxonomy_when` e `taxonomy_target` hanno lo stesso problema (`'job' => ''`) — esclusi da questo ticket, servono ticket separati

### Refactor SuperAdminService (oc:8006)

- I metodi `allows()`, `allowsUser()`, `allowsEmail()` vivono in `RolesAndPermissionsService` — punto unico per logica di autorizzazione nel package
- `SuperAdminService` è stata rimossa senza alias deprecato: breaking change da comunicare nel changelog prima di ogni rilascio
- I metodi sono statici (non DI) per coerenza con il pattern preesistente; la logica legge solo `config('wm-package.super_admin_emails')` senza stato interno
