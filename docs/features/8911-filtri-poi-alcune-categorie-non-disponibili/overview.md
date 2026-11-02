> Ticket: oc:8911

# Filtri POI: alcune categorie non disponibili

## Cosa cambia

Il filtro "tipo di POI" della config dell'app (`MAP.filters.poi_types`, e il gemello legacy
`MAP.filters.poi_type`) elenca i tipi degli EcPoi **dell'App** (`app_id`) **con `global = true`**:
lo stesso criterio con cui `App::getAllPoisGeojson()` sceglie i POI mostrati sulla mappa
(`src/Models/App.php:254-265`). Oggi elenca invece i tipi degli EcPoi il cui `user_id` è il
proprietario dell'App (`AppConfigService.php:635`).

Il criterio "POI mostrati sulla mappa" esce da `getAllPoisGeojson()` e diventa un metodo di `App`
(`globalEcPois()`), usato sia dal file dei POI sia dal filtro: una regola sola, così mappa e
filtro non possono più divergere.

La config si rigenera quando si rigenera il file dei POI: `UpdateAppConfigJob` va in coda, con
`->afterCommit()`, accanto a `BuildAppPoisGeojsonJob` in `TaxonomyPoiTypeablesObserver`
(collegamento o rimozione di un tipo) e in `EcPoiObserver::saved`/`deleted` (che coprono anche
il cambio del flag `global`).

Il frontend non cambia: le voci del pannello "Filtri" sono le `options` di
`MAP.filters.poi_types`, mostrate solo se sulla mappa hanno almeno un POI (`wm-core`,
`select-filter.component.html`, `isPoiOptionVisible()`).

## Perché

Su Cammini d'Italia gli EcPoi passano al gestore del cammino (trasferimento di ownership al
gestore del layer, oc:8080 nel repo camminiditalia): un tipo entra nel filtro solo se il
proprietario dell'App (l'Administrator, utente 2) possiede ancora per caso almeno un POI di quel
tipo, in qualunque cammino.

Il cliente lo vede sulla Rotta dei Due Mari (layer 50): sulla mappa ci sono "Area tende"
(`camping`, 4 POI), "Struttura ricettiva" (`accomodation`, 114), "Punto timbro credenziali"
(`vf-credentials-point`, 78) e "Ristorazione" (`catering`, 28), ma nel filtro solo le ultime due,
perché l'Administrator ne possiede un POI ciascuno altrove. Su tutta la piattaforma il filtro
elenca 60 tipi su 87; con il criterio della mappa sarebbero 85 (dati di produzione, 2026-11-02).

Il criterio `user_id` risale a quando, su Geohub, un'App coincideva con il suo utente. La mappa
usa `app_id` in ogni shard, quindi la correzione vale per tutti i consumer di `wm-package`: negli
shard in cui i POI sono ancora tutti del proprietario dell'App il filtro non cambia.

## Requisiti

- [ ] `MAP.filters.poi_types` e `MAP.filters.poi_type` elencano i tipi distinti degli EcPoi con
      `app_id` = id dell'App e `global = true`, indipendentemente dal loro `user_id`.
- [ ] Il criterio dei POI mostrati sulla mappa sta in un metodo solo, `App::globalEcPois()`
      (`ecPois()` + `where global = true` se la colonna esiste, guardia `Schema::hasColumn`),
      usato da `getAllPoisGeojson()` e dal filtro. `getAllPoisGeojson()` restituisce gli stessi
      POI di prima.
- [ ] Il filtro ricava i tipi distinti da quella query (Eloquent, nessun SQL scritto a mano né
      valori interpolati nella stringa).
- [ ] Ordine (per nome), struttura delle opzioni (`identifier` = `poi_type_<identifier>`, `name`,
      `id`, `icon_name`) e nomi delle chiavi restano identici.
- [ ] `UpdateAppConfigJob($appId)` va in coda con `->afterCommit()` in
      `TaxonomyPoiTypeablesObserver` (created/deleted del pivot) e in `EcPoiObserver::saved` e
      `deleted`, accanto a `BuildAppPoisGeojsonJob`. Con `after_commit = false` sulla coda Redis,
      senza `afterCommit()` il job può leggere i dati prima del commit della transazione Nova e
      scrivere una config senza il tipo appena collegato. L'unicità già presente nel job
      (`ShouldBeUnique`, lock Redis, 600 s) evita riscritture ripetute.
- [ ] Test Pest in `wm-package/tests/Feature`:
  - un EcPoi `global` dell'App ma di un utente diverso dal proprietario porta il suo tipo nel filtro;
  - un EcPoi non `global` non lo porta;
  - un EcPoi di un'altra App non lo porta;
  - `getAllPoisGeojson()` restituisce gli stessi POI di prima (solo `global` dell'App);
  - collegare un tipo a un EcPoi, salvarlo o cancellarlo mette in coda `UpdateAppConfigJob`
    con `afterCommit`.
- [ ] La suite di camminiditalia passa (`QUEUE_CONNECTION=sync`: il job ora gira in più test).
- [ ] Dopo il deploy su Cammini d'Italia: `UpdateAppConfigJob` lanciato una volta per l'App 1; il
      `config.json` su S3 contiene 85 tipi, fra cui `accomodation` e `camping`; nell'app, sulla
      Rotta dei Due Mari, compaiono "Area tende" e "Struttura ricettiva".

## Rischi

- **Config ferma fino alla rigenerazione:** la config è un file su S3 letto direttamente dal
  frontend; senza rigenerazione la correzione non si vede. Mitigato dal passo post-deploy e,
  d'ora in poi, dalla rigenerazione al cambio dei tipi.
- **Altri shard:** il filtro può solo acquistare tipi che sulla mappa hanno POI; nessun tipo
  visibile sulla mappa può sparire.
- **Job eseguito prima del commit** (challenge): la coda Redis ha `after_commit = false`
  (`config/queue.php` di camminiditalia); stesso problema già risolto con `afterCommit()` in
  `RegenerateTaxonomyWhereOutputsJob`. Mitigato con `->afterCommit()` su ogni dispatch.
- **Flag `global` cambiato da Nova** (challenge): ora conta per il filtro. Mitigato rigenerando
  la config da `EcPoiObserver::saved`/`deleted`.
- **Criterio della mappa duplicato** (challenge): mitigato estraendo `App::globalEcPois()`,
  unica fonte per mappa e filtro.

## Out of scope

- L'endpoint `GET /api/app/webapp/{app}/taxonomies/poi_type/{term_id}`
  (`AppController::getFeaturesByAppAndTerm`, `AppController.php:489`) usa lo stesso criterio
  `user_id`. Nessun frontend lo chiama (verificato su `webmapp-app`, `wm-webapp`, `wm-elements`,
  `wm-layer-map`), ma non si può escludere che lo usi qualche backend: resta com'è e va annotato
  come debito noto nella pagina di conoscenza.
- La modifica in blocco di `global` (`BulkEditAction`, oc:8133) salva con `saveQuietly()`
  (`BulkEditAction.php:128`): non rigenera né la config né il `pois.geojson`. Riguarda anche la
  mappa: debito noto da annotare nella pagina di conoscenza.

## Moduli toccati

Repo `wm-package`:
- `src/Models/App.php` — nuovo `globalEcPois()`, usato da `getAllPoisGeojson()`
- `src/Services/Models/App/AppConfigService.php` — query del filtro poi type
- `src/Services/AppPoiOutputsService.php` (nuovo, dalla review) — rigenerazione di
  `pois.geojson` e config in un punto solo, con `afterCommit()` e controllo sull'import
- `src/Observers/TaxonomyPoiTypeablesObserver.php` e `src/Observers/EcPoiObserver.php` —
  chiamano il servizio
- `tests/Feature/AppConfigServicePoiTypesFilterTest.php` e
  `tests/Feature/EcPoiAppConfigRegenerationTest.php` (nuovi)
- `docs/knowledge/configurazione-app-e-config-json.md` — criterio del filtro e debito
  dell'endpoint

Repo `camminiditalia`:
- gitlink `wm-package` e riga nel `CLAUDE.md`
