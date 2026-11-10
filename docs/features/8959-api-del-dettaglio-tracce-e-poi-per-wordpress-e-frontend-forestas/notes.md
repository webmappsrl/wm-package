> Ticket: oc:8959

# Notes — `properties.taxonomies` delle tracce

## Divergenze dal piano, task per task

### Task 2 — dove si costruisce taxonomies

Il piano la aggiungeva in `GeoJsonService::getModelAsGeojson()` per i modelli con
`geojsonTaxonomies()`. Superato dopo la seconda review: `getGeojson()` gira anche nei job interni,
che pagavano le query. Ora la costruisce `EcTrack::getDetailGeojson()`, usata da `EcTrackResource`
ed `EcTrackController` (file su AWS, `/api/ec/track/{id}`, `multiple`), e la logica sta in
`DetailTaxonomiesService` (Task 6). La costante del tab di default è
`DetailTaxonomiesService::DEFAULT_TAB`, non `EcTrack::DEFAULT_TAXONOMY_TAB`.

### Task 3 — rigenerazione dall'observer

Il job si accoda dopo il commit, una volta sola per traccia e per transazione (prima review), e non
subito a ogni legame come diceva il piano.

### Task 5 — etichette nel config.json

Nomi scelti col dev: sezioni `TAXONOMY_LABELS` e `TAXONOMY_TAB_LABELS` (con le sole traduzioni),
metodo `config_section_taxonomy_labels()`, test `AppConfigTaxonomyLabelsTest.php`. Le storiche e i
loro helper sono in `DetailTaxonomiesService` (`LEGACY_TAXONOMIES` con quattro voci, `where`
compresa; `legacyTaxonomyTab()`, `legacyTaxonomyLabel()`, `taxonomyTab()`), non in `EcTrack`.
`TAXONOMY_LABELS` contiene tutte le tassonomie accese nell'app, non solo quelle per le EcTrack.

### Task 6 — POI

`EcTrack::geojsonTaxonomies()` ed `EcPoi::geojsonTaxonomies()` restano, ma le uscite chiamano il
servizio direttamente. Al toggle `ReindexAppContentsJob` accoda `RegenerateTaxonomyWhereOutputsJob`
(review), non `BuildAppPoisGeojsonJob`. Il test «POI globale di un'altra app» non c'è: nel file
entrano solo POI dell'app (`globalEcPois()` parte da `ecPois()`). La chiave è `poi-type`.

## Deviazioni dal piano

- **Comando dei test.** Il piano diceva di lanciarli dalla root di forestas
  (`vendor/bin/pest wm-package/tests/...`). I test del package che non dichiarano
  `uses(Tests\TestCase::class)` da lì falliscono con `BindingResolutionException`; si lanciano
  dalla cartella del package, con la sua suite e il suo DB `wm_package`:
  `docker exec -w /var/www/html/forestas/wm-package php-forestas vendor/bin/pest tests/...`.
  I test in `tests/Feature/TaxonomyWhere` usano invece `Tests\TestCase` e girano solo dalla root
  di forestas.
- **Fake del job AWS nel `Pest.php` globale.** Con la coda `sync` dei test, l'observer fa girare
  davvero `UpdateEcTrackAwsJob` in ogni test che attacca un termine a una traccia (24 test del
  package fallivano su `StorageService::getShardName()`). `UpdateEcTrackAwsJob` è stato aggiunto
  al `Bus::fake([...])` globale di `tests/Pest.php` e a quello di `Feature/TrailRegistry`. I test
  che lo verificano usano già un `Bus::fake` proprio.
- **Test di `geojsonTaxonomies()`.** Le tassonomie storiche si creano con `withoutEvents()` e il
  disco `wmfe` è finto: il formato vecchio delle attività legge le icone da S3.
- **PHPStan.** `$term->identifier` sulle storiche è letto con `getAttribute()`: `Abstracts\Taxonomy`
  non dichiara la proprietà.

- **Task 5 aggiunto dopo l'approvazione del piano** (richiesta del dev, a implementazione finita):
  etichette di tassonomie e tab nel `config.json` (`TAXONOMY_LABELS`, `TAXONOMY_TAB_LABELS`), con
  la costante delle storiche condivisa fra dettaglio e config (oggi
  `DetailTaxonomiesService::LEGACY_TAXONOMIES`). Il test sulla
  rigenerazione verifica `$job->afterCommit === true`, perché `Bus::fake()` non onora
  `afterCommit()`.

- **Campi in più per ogni termine** (richiesta del dev dopo la prova su MinIO locale): la forma
  ridotta a `identifier`/`name`/`tab` perdeva l'`admin_level` delle zone. Ora ogni termine porta
  anche i campi utili della sua fonte, solo se valorizzati (`DetailTaxonomiesService::entry()`).

## Bug trovati

- **`forestas_testing` non ha `taxonomy_terms`**: le migration di oc:8910 non sono state lanciate su
  quel DB, e 30 test di `wm-package/tests/Feature/TaxonomyWhere` lanciati da forestas falliscono su
  una query che non è di questo lavoro (`… where "is_system" = 1`). Preesistente.
- **Cinque test `AppConfigService*`** (`Theme`, `Translations`, `Overlays`, `MinAppVersion`,
  `MapFeatureCollectionColor`) non partono nella suite del package: dichiarano
  `uses(TestCase::class)` mentre `tests/Pest.php` lo applica già a tutta la cartella. Preesistente.
- **`LayerServiceAssignPoisByTermTest`** fallisce su `StorageService::getShardName()` dentro
  `BuildAppPoisGeojsonJob`, il ramo dei POI dell'observer, che esce prima del codice nuovo.
  Preesistente.

## Review finale

- **Job prima del commit e un job per legame (Important, corretto).** `TaxonomyTermableObserver`
  ora prenota `UpdateEcTrackAwsJob` con `DB::afterCommit()`, una volta sola per traccia e per
  transazione (WeakMap sul record della transazione, che con un rollback sparisce). Test:
  «accoda UpdateEcTrackAwsJob dopo il commit, una volta sola per traccia» e «dopo un rollback la
  traccia si rigenera di nuovo al legame successivo», rossi prima e verdi dopo.
- **Guard delle migration (Minor, corretto)**: aggiunto `Schema::hasTable('app_taxonomy_term')`.
- **`@deprecated` su `applyTaxonomyWhereDisplay` (Minor, corretto)**: era un requisito.
- **Rimandati**: `Schema::hasTable` e l'elenco delle tassonomie accese non memoizzati (~6 query
  in più per traccia); `name` senza traduzioni esce come `[]`; tassonomie già in DB con un
  identifier riservato non controllate; un consumer che spegne il sync solo sulla propria
  sottoclasse accoda un job per legame.

## Seconda review (generalizzazione e contratto)

- **Corretto**: accendere o spegnere una tassonomia dall'App ora rigenera `config.json` e dettagli
  delle tracce (`ReindexAppContentsJob`); `TAXONOMY_LABELS` con tutte le tassonomie accese, anche
  solo per i POI; dispatch della cancellazione spostato in `deleted`; docblock di `apps()` rimesso
  a posto; `name` sempre oggetto; termini storici per id e zone per `admin_level`; `taxonomies`
  costruita in `EcTrack::getDetailGeojson()` e non più in `GeoJsonService` (niente query nei job
  interni), con la verifica delle tabelle memorizzata per processo.
- **Non adottato, deciso col dev**: il catalogo `TAXONOMIES` nel `config.json` (versione, tab e
  tassonomie come liste ordinate, `single_choice`, `depends_on`, `models`, termini possibili). Al
  frontend di oggi non serve: i filtri li costruisce da Elasticsearch e da `MAP.filters`. Si può
  aggiungere accanto, senza rompere niente, quando un frontend costruirà i filtri da qui.
- **`poi_type` sulle tracce**: non è un problema, esce solo se una traccia ha davvero un tipo di
  POI (0 su forestas); in `TAXONOMY_LABELS` resta perché servirà ai POI.
- **Rimandati**: storiche lette da `legacy_taxonomies` invece che dalla costante (con `models`,
  `hidden`, chiavi di `depends_on`); allineamento dell'indice ES `terms`;
  regola per gli shard con più app (il dettaglio filtra con l'app proprietaria della traccia);
  traduzione dei tab nuovi solo da file di lingua.

## POI (Task 6)

- Costruzione spostata da `EcTrack` a `src/Services/DetailTaxonomiesService.php`; tracce e POI la
  usano con `EcTrack` / `EcPoi`. I test delle tracce sono rimasti verdi senza cambiare aspettative.
- `pois.geojson` dell'app 1 in locale (DB di dev): 976 feature, tutte con `taxonomies`; 707 con
  `poi_type`, 974 con `where`, nessuna con termini di `taxonomy_terms` (nessuna tassonomia accesa
  per gli EcPoi su dev). Costruzione in 5,5 s.
- `EcPoiMapDisplayTest` fallisce già prima: `EcPoiFactory` usa `App::first()->id`, `null` su DB vuoto.
- **Al bump in forestas**, oltre ai due test già annotati: un test che accende tassonomie su un'App
  fa ora girare (coda `sync`) anche `UpdateAppConfigJob` e `BuildAppPoisGeojsonJob` da
  `ReindexAppContentsJob`; `PackageResourceSaveTest` del package da forestas passa comunque.

## wm-review-ticket (2026-11-10) — correzioni fatte

- **1** `ReindexAppContentsJob` non rifà più il giro delle uscite: accoda `UpdateAppConfigJob` e
  `RegenerateTaxonomyWhereOutputsJob`, che già rigenera dettagli su AWS, indice e `pois.geojson`
  ed è unico finché non parte (niente job doppi se nello stesso salvataggio cambiano tassonomie e
  «Località mostrate»). Il controllo «modello senza Scout» del reindex non c'è più: lo stesso job
  chiama già `searchable()` senza controllarlo.
- **3** `UpdateAppConfigJob` è `ShouldBeUniqueUntilProcessing` con `WithoutOverlapping` per app
  (`releaseAfter(10)`): un dispatch arrivato mentre gira non viene più scartato, e due esecuzioni
  non scrivono insieme. **Job condiviso da tutti gli shard**: un'esecuzione che trova il lock si
  rilascia e consuma un tentativo.
- **7** Chiavi definitive in kebab-case: `poi_type` → `poi-type` in `taxonomies` e
  `TAXONOMY_LABELS`. `RESERVED_TAXONOMY_IDENTIFIERS` = chiavi di `LEGACY_TAXONOMIES` (un test lo
  verifica). Regola: una storica che passa in `taxonomy_terms` prende la sua chiave come identifier.
- **9** `TAXONOMY_LABELS`: nome vuoto → `{}`.
- Non fatti, scelta del dev: gli altri cleanup della review; la documentazione (13) al passo
  successivo.

## Decisioni

- **Attività nascoste in Nova (rilievo Important della review, lasciato com'è dal dev).** Su
  forestas l'import attacca a ogni traccia le attività tecniche `sardegnasentieri:type:sentiero`,
  `…:itinerario` e `sardegnasentieri:cammino`, che la Resource Nova di forestas nasconde
  (`app/Nova/EcTrack.php`, `legacyTaxonomies()`), mentre la config `legacy_taxonomies` del package
  ha `'hidden' => []`. In `taxonomies.activity` escono: la traccia 326 di dev ha «Sentiero» sia in
  `route-type` sia in `activity`. Il dev valuterà dopo se toglierle; la strada pronta è filtrare
  `hidden` dalla config e spostarci la lista di forestas.

- Tag di Orchestrator e stima saltati su richiesta del dev.
- Il branch del package parte da `origin/develop`, non dal commit fissato da forestas.

## Follow-up

- **Al bump del submodule in forestas**: due test di forestas attaccano termini a tracce fuori da
  `withoutSyncingToSearch()` e, con la coda `sync`, fanno girare il job verso S3:
  `tests/Feature/Nova/TaxonomyTermFiltersTest.php` («sul dettaglio di un termine elenca i
  contenuti collegati…») e `tests/Feature/Taxonomy/TaxonomiesPerAppTest.php` («l'indice
  Elasticsearch non porta i termini di una tassonomia spenta…»). Va aggiunto
  `Bus::fake([UpdateEcTrackAwsJob::class])` in quei test, o un fake globale nel `Pest.php` di
  forestas, nello stesso commit del bump.
- Rigenerare i file su AWS delle tracce di dev e il `config.json` dell'app dopo il deploy.
- Avvisare Rubens: nomi di tassonomie e tab in `TAXONOMY_LABELS` / `TAXONOMY_TAB_LABELS` del
  `config.json`.
- Pezzi successivi di oc:8959: gli altri dati del dettaglio dall'analisi, roadbook.
