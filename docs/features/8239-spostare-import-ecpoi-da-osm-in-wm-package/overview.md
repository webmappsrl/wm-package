> Ticket: oc:8239

# Spostare import EcPoi da OSM in wm-package

## Cosa cambia

Tutta la logica di import EcPoi da OpenStreetMap tramite ID nodo OSM (oggi disponibile solo su Maphub) viene spostata in wm-package, così da essere disponibile in tutti i progetti Webmapp che usano il package. Nello specifico:

- **Nova Action** `ImportEcPoiFromOsm` spostata in `Wm\WmPackage\Nova\Actions` e **registrata di default** in `Wm\WmPackage\Nova\EcPoi::actions()` — ogni consumer del package la ottiene automaticamente, senza override applicativo.
- **Servizi** `OsmPoiImporter`, `OsmTaxonomyPoiTypeResolver`, `ImportReport`, `OsmImportReportPresenter`, `OsmImportReportStore` spostati in `Wm\WmPackage\Services\Osm`. `findExistingEcPoiByOsmid()` viene corretta per filtrare anche per `app_id` (bug preesistente in Maphub: la query cercava per `osmid` su tutta la tabella `ec_pois` senza scoping per app — rischio di cross-contaminazione dati tra App diverse che condividono lo stesso DB, amplificato dal fatto che il codice diventa condiviso tra più progetti).
- **DTO** `OsmNodePoiData` e `OsmEcPoiPropertiesData` spostati in `Wm\WmPackage\Dto`; `OsmEcPoiPropertiesData` continua ad estendere `Wm\WmPackage\Dto\EcPoiPropertiesData` (già presente nel package, nessuna creazione necessaria — il ticket originale lo dava per mancante ma non lo è più).
- **Comando CLI** rinominato da `maphub:import-ec-pois-from-osm` a `wm-package:import-ec-pois-from-osm`, registrato in `WmPackageServiceProvider::configurePackage()`.
- **Controller + route + view** del report di import spostati in wm-package (route su `nova-vendor`, stesso middleware `web,auth,can:access-nova`).
- **Config** rinominato da `osm-import.php` a `wm-osm-import.php`, aggiunto a `hasConfigFile()` in `WmPackageServiceProvider`. Env var rinominate in `WM_OSM_IMPORT_REQUEST_DELAY_MS` e `WM_OSM_IMPORT_MAX_IDS_PER_RUN`.
- **Email super-admin hardcodata** (`team@webmapp.it`) sostituita con `$user->hasRole('Administrator') || Wm\WmPackage\Services\RolesAndPermissionsService::allowsUser($user)` in `visibleAppsFor()` — **preserva esattamente il comportamento attuale** (il solo `RolesAndPermissionsService::allowsUser()` escluderebbe gli Administrator non presenti in `WM_SUPER_ADMIN_EMAILS`, causando una regressione di permessi silenziosa individuata in Fase: challenge).
- **Traduzioni**: solo le chiavi già presenti in `wm-package/resources/lang/{en,it}.json` vengono estese con le stringhe OSM (nessuna aggiunta di fr/es/de al package).
- **Test**: spostati integralmente in `wm-package/tests/` con `Wm\WmPackage\Tests\TestCase`.

## Perché

Il team vuole rendere l'importazione POI da OSM riutilizzabile in tutti i progetti Webmapp che usano wm-package, evitando di reimplementare la stessa logica in ogni shard che ne ha bisogno (customer_request oc:8239).

## Requisiti

- [ ] `Wm\WmPackage\Nova\Actions\ImportEcPoiFromOsm` funzionalmente identica all'originale, con `visibleAppsFor()` basato su `$user->hasRole('Administrator') || RolesAndPermissionsService::allowsUser($user)` (preserva entrambi i rami della condizione originale, non solo l'email)
- [ ] Test esplicito: un utente con ruolo Administrator ma email non in `WM_SUPER_ADMIN_EMAILS` vede tutte le app in `visibleAppsFor()`
- [ ] Action registrata di default in `Wm\WmPackage\Nova\EcPoi::actions()` (nessun override necessario nei progetti consumer)
- [ ] `Wm\WmPackage\Services\Osm\OsmPoiImporter` e classi di supporto (`ImportReport`, `OsmTaxonomyPoiTypeResolver`, `OsmImportReportPresenter`, `OsmImportReportStore`) spostate con logica invariata
- [ ] `Wm\WmPackage\Dto\OsmNodePoiData` e `Wm\WmPackage\Dto\OsmEcPoiPropertiesData` spostati, `OsmEcPoiPropertiesData` continua ad estendere `Wm\WmPackage\Dto\EcPoiPropertiesData` esistente
- [ ] Comando CLI `wm-package:import-ec-pois-from-osm` registrato in `WmPackageServiceProvider`
- [ ] Route `nova-vendor` + `OsmImportReportController` + view `osm-import-report.blade.php` spostati in wm-package, stesso gate `can:access-nova`
- [ ] Config `wm-osm-import.php` con env var `WM_OSM_IMPORT_REQUEST_DELAY_MS` / `WM_OSM_IMPORT_MAX_IDS_PER_RUN`, aggiunto a `hasConfigFile()`
- [ ] Traduzioni OSM aggiunte a `wm-package/resources/lang/{en,it}.json` (solo le 2 lingue già presenti nel package)
- [ ] Test spostati in `wm-package/tests/Unit/` e `wm-package/tests/Feature/` con `Wm\WmPackage\Tests\TestCase`, verdi dopo lo spostamento
- [ ] Nessuna regressione funzionale rispetto al comportamento attuale su Maphub (stesso dry-run, stesso throttling, stessa classificazione errori)
- [ ] `findExistingEcPoiByOsmid()` filtra anche per `app_id` (fix del bug di isolamento multi-app individuato in Fase: challenge, incluso in questo ciclo)
- [ ] Test: due App diverse con lo stesso `osmid` importato non si sovrascrivono a vicenda

## Rischi

Emersi dalla Fase: challenge (due revisori adversariali indipendenti, uno per repo).

- **[Mitigato] `visibleAppsFor()` perdeva il ramo `hasRole('Administrator')`** sostituendo la costante email hardcodata con il solo `RolesAndPermissionsService::allowsUser()` — un Administrator non in `WM_SUPER_ADMIN_EMAILS` avrebbe perso la visibilità su tutte le app. Fix: `$user->hasRole('Administrator') || RolesAndPermissionsService::allowsUser($user)`, con test esplicito di copertura (vedi Requisiti).
- **[Mitigato] `findExistingEcPoiByOsmid()` non filtrava per `app_id`** — bug preesistente in Maphub (query su tutta la tabella `ec_pois` per `osmid`), che centralizzando il codice diventa un rischio di cross-contaminazione dati tra App diverse sullo stesso DB condiviso da più progetti Webmapp. Fix incluso in questo ciclo: scoping esplicito per `app_id` + test di non-sovrascrittura tra App diverse.
- **[Accettato, fuori scope] Nessun `User-Agent` custom su `OsmClient`** — le richieste verso `api.openstreetmap.org` non hanno header identificativo. Centralizzando l'importer nel package, un blocco/rate-limit diventa un incidente condiviso da tutti i consumer invece che isolato a Maphub. `OsmClient` non fa parte dei file spostati da questo ticket (già nel package, usato anche altrove) — una modifica lì ha un raggio d'azione più ampio di questa migrazione. Documentato come rischio noto; follow-up ticket consigliato per aggiungere uno User-Agent condiviso a `OsmClient`.
- **[Accettato] Traduzioni fr/es/de non migrate** — decisione esplicita: il package resta a `{en,it}` per queste chiavi, le 3 lingue vengono rimosse anche da Maphub. Gli utenti Nova con locale fr/es/de vedranno questa action e il report in inglese (oggi tradotti). Trade-off accettato per contenere lo scope della migrazione.
- **[Accettato] Rate limiting locale al processo, non coordinato tra consumer** — `usleep()` sequenziale in `OsmPoiImporter`, nessun lock/contatore condiviso. Con più progetti Webmapp che eseguono import in parallelo, il traffico aggregato verso l'endpoint OSM cresce senza un meccanismo di coordinamento. Comportamento invariato rispetto a oggi (era già assente), fuori scope per questo lift-and-shift.
- **[Accettato] Copertura test ridotta lato Maphub** — la suite dettagliata (`OsmPoiImportTest`, `OsmImportReportRouteTest`) si sposta interamente nel package; Maphub mantiene solo uno smoke test sull'integrazione (decisione presa in Fase: reverse-interaction per evitare doppia manutenzione).
- **[Nota tecnica] Sequenza di release tra i due repo è vincolante**: wm-package deve essere mergiato e taggato **prima** che Maphub rimuova il codice locale e aggiorni `composer.json`/submodule pointer. Se l'ordine si inverte, l'azione Nova sparisce dal menu senza errore esplicito. Mitigazione: sequenza esplicita in `plan.md`, nessun task Maphub di rimozione codice eseguito prima della conferma che il package è rilasciato.
- **[Nota tecnica] Env var rinominate (`OSM_IMPORT_*` → `WM_OSM_IMPORT_*`) non si aggiornano da sole in produzione** — se il `.env` di prod non viene aggiornato manualmente al deploy, si torna silenziosamente ai valori di default (350ms/500 id) invece di eventuali valori tarati. Mitigazione: task esplicito in `plan.md`/checklist di deploy per verificare i valori attuali in prod prima del rename e aggiornare il `.env` server in fase di deploy.
- **[Nota tecnica, minore] `Nova::url('/resources/ec-pois')` hardcoded** nel controller del report — se un consumer futuro rinomina l'`uriKey()` della resource EcPoi, il link "torna alla lista" punta a un URL errato. Non impatta Maphub (URI coincide), documentato come limitazione nota per consumer futuri.

## Out of scope

- Sync media OSM (`image`, `wikimedia_commons`) verso Spatie Media Library — TODO già presente nel codice originale, non implementato in questo ciclo
- Supporto import da `way`/`relation` OSM — resta limitato a `node`, invariato rispetto ad oggi
- Traduzioni fr/es/de per questa feature — esplicitamente escluse (vedi Requisiti)
- Modifiche ad altre Nova Action o al comportamento generale di `Wm\WmPackage\Nova\EcPoi`

## Moduli toccati

**Nuovi file in wm-package:**
- `src/Nova/Actions/ImportEcPoiFromOsm.php`
- `src/Services/Osm/OsmPoiImporter.php`
- `src/Services/Osm/ImportReport.php`
- `src/Services/Osm/OsmTaxonomyPoiTypeResolver.php`
- `src/Services/Osm/OsmImportReportPresenter.php`
- `src/Services/Osm/OsmImportReportStore.php`
- `src/Dto/OsmNodePoiData.php`
- `src/Dto/OsmEcPoiPropertiesData.php`
- `src/Console/Commands/ImportEcPoiFromOsmCommand.php`
- `src/Http/Controllers/OsmImportReportController.php`
- `resources/views/nova-vendor/osm-import-report.blade.php` (o path equivalente per view package)
- `config/wm-osm-import.php`
- `tests/Unit/OsmPoiImportTest.php`
- `tests/Feature/OsmImportReportRouteTest.php`

**File modificati in wm-package:**
- `src/Nova/EcPoi.php` — aggiunta `ImportEcPoiFromOsm` a `actions()`
- `src/WmPackageServiceProvider.php` — registrazione config `wm-osm-import` e comando CLI, route del report
- `resources/lang/en.json`, `resources/lang/it.json` — chiavi OSM aggiunte
- `routes/` (file route package, da individuare in Fase: write-plan) — route `osm.import.report`
