# wm-package — CLAUDE.md

## Feature disponibili

| Feature | Ticket | Moduli toccati | Note |
|---|---|---|---|
| Fix import Excel POI: nomi mancanti in pois.geojson | oc:8063 | `src/Imports/Processors/EcPoiRowProcessor.php`, `tests/Unit/Imports/Processors/EcPoiRowProcessorTest.php` | Sync `properties['name']` da `getTranslations('name')` in `apply()` — replica logica observer bypassata da `saveQuietly()` |
| Fix getTaxonomyMorphableRecords | oc:8013 | `src/Jobs/Import/ImportTaxonomyJob.php` | Corregge il parametro passato a getTaxonomyMorphableRecords: entityId (GeoHub) invece di model->id (Maphub) |
| Refactor SuperAdminService | oc:8006 | `src/Services/RolesAndPermissionsService.php`, `src/Support/SuperAdminService.php` (rimosso), `src/Nova/App.php`, `src/Nova/Actions/GenerateAppIconsAction.php`, `src/Nova/Actions/BuildAppPoisGeojsonAction.php`, `src/Policies/AppPolicy.php` | Sposta i check super-admin email-based in RolesAndPermissionsService; rimuove SuperAdminService |

## Decisioni architetturali

### Fix import Excel POI nomi mancanti (oc:8063)
- `EcPoiRowProcessor::apply()` deve sincronizzare `properties['name']` da `getTranslations('name')` dopo il loop — `saveQuietly()` bypassa `AbstractObserver::saving()` che normalmente fa questa sync
- La logica è duplicata tra observer e processor: tech debt noto, accettato per mantenere il fix minimale
- In update mode il merge delle traduzioni è implicito: il modello caricato dal DB porta già tutte le lingue, `setTranslation` aggiorna solo la locale fornita
- `EcTrackRowProcessor` non è affetto: non usa `setTranslation` per il nome

### Fix getTaxonomyMorphableRecords (oc:8013)
- `processDependencies()` deve passare `$this->entityId` (ID GeoHub) a `getTaxonomyMorphableRecords()`, non `$model->id` (ID Maphub locale) — i due ID sono diversi e il metodo interroga il DB GeoHub



### Refactor SuperAdminService (oc:8006)

- I metodi `allows()`, `allowsUser()`, `allowsEmail()` vivono in `RolesAndPermissionsService` — punto unico per logica di autorizzazione nel package
- `SuperAdminService` è stata rimossa senza alias deprecato: breaking change da comunicare nel changelog prima di ogni rilascio
- I metodi sono statici (non DI) per coerenza con il pattern preesistente; la logica legge solo `config('wm-package.super_admin_emails')` senza stato interno
