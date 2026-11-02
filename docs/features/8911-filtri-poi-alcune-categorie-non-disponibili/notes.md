> Ticket: oc:8911

# Notes — Filtri POI: alcune categorie non disponibili

## Divergenze dal piano, task per task

### Task 3: rigenerazione della config dagli observer

Il piano prevedeva `UpdateAppConfigJob::dispatch($appId)->afterCommit()` scritto direttamente nei
tre observer. Dopo la review finale e `wm-review-ticket` la rigenerazione passa da un servizio,
`AppPoiOutputsService::dispatchRegeneration($appId, $withConfig)`, che:

- mette in coda `BuildAppPoisGeojsonJob` **e** `UpdateAppConfigJob`, entrambi con
  `afterCommit()`: prima solo la config lo aveva, e `pois.geojson` poteva restare indietro rispetto
  al filtro;
- non mette in coda la config finché `ImportAppJob::layerBatchIsPublishReady()` è falso
  (l'import Geohub collega i tipi con `syncWithoutDetaching`, `ImportEcPoiJob.php:65`, e gli
  eventi del pivot scattano: senza controllo il dispatch aggirava «mai un config con MAP.layers
  incompleto» di oc:8488);
- in `EcPoiObserver::saved` rigenera la config solo se il POI è nuovo o cambiano `global` o
  `app_id`, per non ricostruire la config a ogni salvataggio di un POI in tutti gli shard.

Il controllo sull'import sta nel servizio e non in `UpdateAppConfigJob`, che resta identico a
prima: così il job generico non dipende da `ImportAppJob`.

Test in più rispetto al piano: `getAllPoisGeojson()` contiene solo i POI globali dell'App;
`geojson` e config in coda con `afterCommit` anche alla creazione di un POI; solo `geojson` al
salvataggio di un POI che non cambia `global` né `app_id`; nessuna config durante l'import.
Il caso «EcPoi senza App» non è costruibile: `ec_pois.app_id` è NOT NULL
(`database/migrations/create_ec_pois_table.php.stub:21`).

## Verifiche

- Test del package (DB `wm_package`): `AppConfigServicePoiTypesFilterTest` 9 e
  `EcPoiAppConfigRegenerationTest` 7, tutti verdi; i file `AppConfig*` eseguibili verdi.
- Suite di camminiditalia: 814 passati, 1 fallito (`StageShareImageServiceTest`,
  `mkdir(): No space left on device`: disco della VM Docker pieno, non legato a questo lavoro).
  Dopo le correzioni della review: 134 test di camminiditalia che toccano EcPoi e tipi POI verdi.
- PHPStan di camminiditalia: 0 errori. PHPStan del package sui file toccati: nessun errore nuovo
  rispetto a `HEAD`. Pint: nessuna correzione.

## Bug trovati

- **Test esistenti non eseguibili** (preesistente, non toccato): fra quelli legati a questo lavoro
  non girano né dal package né da camminiditalia `AppGetAllPoisGeojsonGlobalTest` (vuole
  `App\Models\EcTrack` dello shard), `AppPoisGeojsonNameSearchableTest`,
  `TaxonomyWhereDisplayOutputTest`, `AppConfigServiceMinAppVersionTest`,
  `AppConfigServiceOverlaysTest`, `AppConfigServiceTranslationsTest`, `EcPoiSearchableStringTest`
  (`Tests\TestCase`), `AppConfigServiceThemeTest`, `AppConfigServiceMapFeatureCollectionColorTest`
  (`uses(TestCase)` duplicato rispetto a `Pest.php`). `EcPoiMapDisplayTest` fallisce perché
  `EcPoiFactory` legge `App::first()` e il DB `wm_package` non ha App.

## Decisioni

- **Criterio del filtro** (dialogo): i tipi dei POI mostrati sulla mappa, `app_id` + `global`,
  per tutti gli shard; nessuna chiave di configurazione.
- **Endpoint `taxonomies/poi_type/{term_id}`** (dialogo): stesso criterio `user_id`, lasciato
  com'è perché qualche backend potrebbe usarlo; annotato come debito nella pagina di conoscenza.
- **`App::globalEcPois()`** (challenge, nome scelto dal dev al posto di `mapEcPoisQuery`): un
  solo punto per il criterio di mappa e filtro. Il filtro sta in
  `AppConfigService::poiTypeFilterOptions()`, con il query builder senza modello perché `name`
  serve come JSON di tutte le traduzioni.
- **Lock di `ShouldBeUnique`**: il lock resta preso finché il job non ha finito; una modifica che
  arriva mentre il job sta già costruendo la config viene scartata, e la config si riallinea alla
  modifica successiva. È lo stesso limite accettato per `BuildAppPoisGeojsonJob`;
  `ShouldBeUniqueUntilProcessing` riaprirebbe le build concorrenti sulla stessa chiave S3 che
  oc:8488 ha chiuso. Su Laravel 11 (carg) un rollback dopo il dispatch non libera il lock
  (ipotetico: serve un rollback dopo il `saved` del POI).
- **Test**: helper come closure su `$this` (`makePoi`, `attachType`, `poiTypeOptions`…), non
  funzioni globali che in Pest possono collidere fra file; `$this->poiApp` perché `$this->app` è
  l'applicazione di Testbench; nel test del detach i lock si liberano con
  `app('cache')->forgetDriver('redis')`; i test di salvataggio usano `fresh()`, perché
  `wasRecentlyCreated` resta `true` sull'istanza creata nel test.
- **Tag Orchestrator**: il dev ha chiesto di non associare tag (né di ambiente né di contenuto).

## Follow-up

- Correggere il ramo `poi_type` di `AppController::getFeaturesByAppAndTerm` dopo aver verificato
  chi lo chiama.
- `BulkEditAction` con `saveQuietly()`: la modifica in blocco di `global` non rigenera né config
  né `pois.geojson`.
- Rilievi minori lasciati dalla review: `Schema::hasColumn` a ogni chiamata di `globalEcPois()`;
  `orderBy('name')` sul JSON grezzo (preesistente); nessun test che segua tutta la catena
  observer → job → config.
- Dopo il deploy su Cammini d'Italia: lanciare `UpdateAppConfigJob` per l'App 1 e verificare
  config e app (vedi fondo di `plan.md`).
