> Ticket: oc:8487

# Generalizzare Sincronizza Taxonomy Where ed estenderla agli EcPoi

## Cosa cambia

- `GeometryComputationService::syncTracksTaxonomyWhere()` viene generalizzato in un unico metodo parametrico che accetta sia `EcTrack` (`MultiLineString`) sia `EcPoi` (`Point`), in due varianti:
  - **bulk** (comportamento attuale, nessun filtro per riga — usata dall'azione Nova manuale);
  - **scoped per singolo record** (nuovo, filtro `AND id = ?` — usata dal path automatico di salvataggio).
- Il formato JSON di `properties.taxonomy_where` si **unifica come conseguenza diretta**, non come task di codice separato: dopo questo ticket nessun call site EC (`EcTrack`/`EcPoi`) usa più il vecchio job via-API — automatico, azione inline, azione bulk e import passano tutti dal solo metodo SQL. Un solo scrittore per EC significa un solo formato per le nuove scritture; i record EC già esistenti nel vecchio formato vengono riportati al formato SQL al primo ricalcolo (bulk o scoped), non serve alcuna conversione di codice dedicata.
- Il path automatico che scatta al salvataggio di un contenuto (`EcPoiService::updateDataChain()`, `EcTrackService::createDataChain()`/`updateDataChain()`) smette di usare il vecchio job `UpdateModelWithGeometryTaxonomyWhere` (chiamata API OSMFeatures) e usa il nuovo metodo SQL scoped-per-record.
- **Il flusso di import GeoHub viene agganciato esplicitamente al nuovo metodo SQL**: `GeohubImportService::persistQuietly()` disabilita gli observer, quindi `updateDataChain()`/`createDataChain()` non scattano mai durante un import — senza un aggancio dedicato, la causa radice #1 del ticket (POI/track importati senza `taxonomy_where`) non verrebbe risolta, solo spostata. Il sync viene agganciato al completamento del batch di import (stesso pattern già usato in oc:8488, `->finally()` sul batch), non al singolo save.
- **Generalizzato anche il meccanismo esistente "nuove where → risincronizza i contenuti esistenti"**: esiste già `SyncTaxonomyWhereTracksJob` (job in coda, con retry) e il trait `HasTaxonomyWhereImportHelpers` (`finalizeWithTracksSync()` sincrono, usato da `ImportTaxonomyWhere`; `executeGeohubImport()` asincrono, usato dal flusso di import where da GeoHub) — tutti e tre oggi hardcoded solo su `EcTrack`. Vengono estesi per includere anche `EcPoi`: è il punto di aggancio più diretto per il caso descritto nel ticket (nuove where da oc:8486 che rendono finalmente coperti contenuti già esistenti). Complementare, non sovrapposto, al punto precedente: quello copre "contenuto nuovo verso where vecchie", questo copre "contenuto vecchio verso where nuove".
- L'azione Nova standalone `SyncTracksTaxonomyWhereAction` (su `TaxonomyWhere::actions()`) viene **sostituita** da un'unica nuova azione "Sincronizza Taxonomy Where su EC Features", che ricalcola in bulk sia `EcTrack` sia `EcPoi` in un solo click, **dispatchata in coda** (non più sincrona dentro la request HTTP di Nova) per restare resiliente anche se in futuro i volumi di dati crescono.
- Aggiunto un indice **GIST** su `taxonomy_wheres.geometry` (migration difensiva, costo trascurabile ai numeri attuali — 4 righe in tabella oggi — ma protezione naturale se la copertura geografica dovesse crescere molto in futuro).
- L'azione inline "Regenerate Taxonomy Where" già attiva su `EcTrack.php` (per riga selezionata) viene migrata allo stesso nuovo metodo SQL scoped, per coerenza con il path automatico.
- Le due classi Nova Action dead code (mai registrate in nessuna `actions()`) vengono rimosse: `RegenerateEcPoiTaxonomyWhere.php` (segnalata nel ticket) e `RegenerateTaxonomyWhere.php` (trovata durante l'analisi, stesso pattern di codice morto).
- **UGC (`UgcPoi`/`UgcTrack`) resta esplicitamente invariato**: continua a usare il vecchio job basato su API OSMFeatures (`UgcController.php`, `WmSyncUgcTaxonomyWhereCommand.php`). Sarà oggetto di un ticket separato futuro.

## Perché

Il meccanismo attuale sui POI è vecchio e non funziona per due cause verificate nel ticket: (1) durante l'import, `GeohubImportService::persistQuietly()` disabilita gli observer, quindi il job che calcola le where non viene mai dispatchato; (2) anche quando gira, l'API OSMFeatures copre solo dati italiani, lasciando vuoti i contenuti esteri (Francia, Corsica). Con oc:8486 le where GeoHub (con copertura estera) arrivano nella tabella `taxonomy_wheres`, ma sono sfruttabili solo dal calcolo interno via SQL (`ST_Intersects`), già esistente per i track e da generalizzare ai POI.

In fase di reverse-interaction è emerso un secondo problema, non esplicitato nel ticket originale ma verificato nel codice: `GeoJsonService::getModelAsGeojson()` (usato per generare `pois.geojson` e le feature EcTrack esportate) fa uno spread diretto della colonna `properties` **senza normalizzazione** — le due forme diverse di `taxonomy_where` (via API: `{"R42611": {"it": "...", "_admin_level": 4}}`; via SQL: `{"R275098": {"name": {"it": "..."}, "admin_level": 6, "source": "..."}}`) finiscono così **grezze** nei file pubblici esportati su S3/MinIO. La normalizzazione esiste solo lato PHP interno (`getOrderedTaxonomyWheres()`, usata per Elasticsearch e per l'array feature EcTrack), non nei file esportati. Per questo l'unificazione del formato è stata inclusa nello scope di questo ticket e non trattata come semplice pulizia rimandabile.

## Requisiti

- [ ] `GeometryComputationService` espone un metodo generalizzato che sincronizza `taxonomy_where` sia per `EcTrack` sia per `EcPoi`, con parametro opzionale per scoping a un singolo `id`
- [ ] Zero call site EC residui sul vecchio job via-API dopo il ticket (verificato: automatico, azione inline, azione bulk, import) — l'unificazione del formato per EC è una conseguenza diretta, non un task di codice a parte
- [ ] `getOrderedTaxonomyWheres()`/`getValidName()` continuano a leggere correttamente il nuovo formato unificato, nessuna regressione sulla stringa searchable Elasticsearch né sull'array feature `EcTrack:738`
- [ ] `EcPoiService::updateDataChain()` usa il nuovo metodo SQL scoped-per-record al posto di `UpdateModelWithGeometryTaxonomyWhere`
- [ ] `EcTrackService::createDataChain()` e `updateDataChain()` usano il nuovo metodo SQL scoped-per-record al posto di `UpdateModelWithGeometryTaxonomyWhere`
- [ ] Il flusso di import GeoHub (EcTrack/EcPoi) invoca il nuovo metodo SQL al completamento del batch — non solo il path manuale/automatico da Nova — altrimenti i contenuti importati restano senza `taxonomy_where` esattamente come oggi
- [ ] `SyncTaxonomyWhereTracksJob` e `HasTaxonomyWhereImportHelpers::finalizeWithTracksSync()`/`executeGeohubImport()` (oggi hardcoded su `EcTrack`) generalizzati per includere anche `EcPoi`, senza cambiare il comportamento verso `ImportTaxonomyWhere`/il flusso GeoHub-where esistenti
- [ ] Migration per indice GIST su `taxonomy_wheres.geometry` (difensiva, nel package core, non nel dominio opzionale Trail Registry)
- [ ] Nuova Nova Action unica "Sincronizza Taxonomy Where su EC Features" (bulk, EcTrack + EcPoi), dispatchata in coda, sostituisce `SyncTracksTaxonomyWhereAction` su `TaxonomyWhere::actions()`
- [ ] Azione inline "Regenerate Taxonomy Where" su `EcTrack.php` migrata al nuovo metodo SQL scoped
- [ ] Rimozione di `RegenerateEcPoiTaxonomyWhere.php` e `RegenerateTaxonomyWhere.php` (dead code, mai registrate)
- [ ] Test Pest Feature con geometrie PostGIS reali che coprano: sync bulk EcTrack, sync bulk EcPoi, sync scoped-per-id EcTrack, sync scoped-per-id EcPoi, formato JSON unificato risultante, aggancio al flusso di import
- [ ] UGC (`UgcPoi`, `UgcTrack`, `UgcController`, `WmSyncUgcTaxonomyWhereCommand`) esplicitamente non toccato in questo ciclo

## Rischi

- **Riscrittura formato su dato di produzione**: unificare il formato tocca una colonna JSON già consumata da più punti (Elasticsearch searchable, geojson feature, file esportati). Mitigato da: `getOrderedTaxonomyWheres()`/`getValidName()` già tollerano più forme oggi, e continueranno a tollerarle anche dopo — quindi anche un ipotetico rollback del codice a una versione precedente a questo ticket leggerebbe comunque correttamente i dati nel nuovo formato unificato (nessun invariante nuovo da introdurre, verificato sul codice attuale).
- **Record esistenti nel vecchio formato non vengono retroattivamente aggiornati** da questo ticket: restano nella vecchia forma finché non passano di nuovo dal calcolo (bulk o automatico). Mitigato con procedura operativa post-deploy: lanciare manualmente la nuova azione bulk una volta (fa già `UPDATE` incondizionato su tutti i record con geometria, non solo quelli senza `taxonomy_where`). Nessuno snapshot del valore precedente viene conservato: un rollback selettivo di colonna non è possibile (solo restore completo del DB), accettato perché l'operazione è idempotente e ricalcolabile.
- **CRITICO — il sync bulk (ora automatico ad ogni import, Task 8) azzera `taxonomy_where` sui contenuti fuori dalla copertura locale di `taxonomy_wheres`**, non solo li converte di formato. Misurato in review finale sul DB di sviluppo Maphub: con solo 4 poligoni locali (nessuno sull'Italia continentale), 93 `EcPoi` su 93 con dati oggi popolati (OSMFeatures, regione+comune, 5 lingue) verrebbero ridotti a `{}`. **Decisione presa (2026-09-16): sequencing manuale, nessuna modifica di codice.** Prima di lanciare (o lasciare scattare automaticamente) il sync bulk su un'app, importare sempre prima una copertura `taxonomy_wheres` sufficiente per la sua area geografica (percorso oc:8486). Dettaglio completo e checklist di deploy in `notes.md`.
- **Stato "congelato" per contenuti fuori copertura**: il trigger automatico (`updateDataChain()`) scatta solo quando `taxonomy_where` è `null` (mai calcolato). Un EcPoi fuori da ogni `taxonomy_where` viene scritto come `{}` (vuoto, non più `null`) — se in futuro nuove `taxonomy_wheres` arrivano a coprirlo (es. un nuovo import), il trigger automatico non lo riprende mai da solo. Limite noto, accettato: il recupero resta demandato al rilancio periodico/manuale della bulk action (che sovrascrive incondizionatamente), non a un meccanismo di retry automatico.
- **Prima generalizzazione di questo meccanismo SQL a un modello Point** (`EcPoi`): nessun precedente locale nel codebase per questo pattern specifico su Point invece che su MultiLineString — rischio di edge case geometrici non anticipabili solo da lettura statica del codice (es. un punto esattamente su un confine condiviso da più `taxonomy_wheres`, con match `ST_Intersects` su più poligoni contemporaneamente).
- **Frontend/wm-core non verificabile da questo ambiente**: non è possibile confermare se un consumer esterno legga `properties.taxonomy_where` raw dai file esportati — resta un'incognita nota, non un rischio azzerato dall'unificazione formato (che comunque lo riduce, rendendo la struttura consistente anche se il consumer non fa branching sulle due forme).
- **Copertura test pregressa parziale, non assente come inizialmente creduto**: `tests/Feature/Jobs/SyncTaxonomyWhereTracksJobTest.php` copre già il path bulk per `EcTrack` — corretto rispetto a una prima valutazione errata in fase di challenge ("zero test"). Resta comunque necessario estendere la copertura a `EcPoi` e al path scoped-per-id, inclusi nei requisiti.
- **Job `UpdateModelWithGeometryTaxonomyWhere` già in coda al momento del deploy**: un worker Horizon già in esecuzione non risente delle modifiche a una classe Job già caricata in memoria (regola nota del progetto). Se al deploy sono in coda job generati da salvataggi appena precedenti sui call site EC che questo ticket modifica, possono fallire silenziosamente. Mitigazione operativa (non di codice): riavviare il worker Horizon subito dopo il deploy, idealmente dopo aver atteso lo svuotamento della coda `geometric-computations`.

## Out of scope

- UGC (`UgcPoi`, `UgcTrack`) — resta sul vecchio meccanismo via API OSMFeatures; verrà gestito in un ticket separato futuro.
- Migration/backfill Artisan dedicata per normalizzare i record già esistenti nel vecchio formato: si usa il lancio manuale della nuova azione bulk come procedura operativa post-deploy, non codice di migrazione usa-e-getta.
- Verifica lato frontend (`wm-core`/webapp) di come viene consumato `properties.taxonomy_where` nei file esportati: repo non disponibile in questo ambiente, resta un'incognita segnalata ma non risolta qui.

## Moduli toccati

Tutti i file sono nel submodule **wm-package** (nessuna modifica lato Maphub: `app/Nova/EcTrack.php` e `app/Nova/EcPoi.php` sono stub vuoti che ereditano tutto dal package).

- `src/Services/GeometryComputationService.php` — generalizzazione del metodo di sync (bulk + scoped-per-id, EcTrack + EcPoi)
- `src/Nova/Actions/SyncTracksTaxonomyWhereAction.php` — sostituita dalla nuova azione unica
- `src/Nova/Actions/RegenerateEcPoiTaxonomyWhere.php` — rimossa (dead code)
- `src/Nova/Actions/RegenerateTaxonomyWhere.php` — rimossa (dead code)
- `src/Nova/TaxonomyWhere.php` — registrazione della nuova azione unica in `actions()`
- `src/Nova/EcTrack.php` — azione inline "Regenerate Taxonomy Where" migrata al nuovo metodo
- `src/Services/Models/EcPoiService.php` — `updateDataChain()` usa il nuovo metodo scoped
- `src/Services/Models/EcTrackService.php` — `createDataChain()`/`updateDataChain()` usano il nuovo metodo scoped
- `src/Jobs/Import/ImportAppJob.php` — nuovo `->finally()` su `CONFIG_DEPENDENT_BATCHES` (`ec_poi`/`ec_track`, già esistenti) per agganciare il sync al completamento del batch di import EC
- `src/Jobs/TaxonomyWhere/SyncTaxonomyWhereTracksJob.php` — generalizzato per includere anche `EcPoi` (probabile rename, es. `SyncTaxonomyWhereJob`)
- `src/Nova/Actions/Concerns/HasTaxonomyWhereImportHelpers.php` — `finalizeWithTracksSync()`/`executeGeohubImport()` estesi a `EcPoi`
- `src/Jobs/` — nuovo job scoped-per-record (sostituisce `UpdateModelWithGeometryTaxonomyWhere` nei soli call site EC) + eventuale job per il dispatch in coda della nuova azione bulk unica
- `src/Models/Abstracts/GeometryModel.php` — eventuale adeguamento di `getOrderedTaxonomyWheres()`/`getValidName()` al formato unificato (da verificare in fase di implementazione se serve modifica o se la tolleranza esistente basta)
- `database/migrations/` (package core, non dominio opzionale) — nuovo stub migration per indice GIST su `taxonomy_wheres.geometry`
- `tests/Feature/Jobs/SyncTaxonomyWhereTracksJobTest.php` — esteso a `EcPoi` e al path scoped-per-id
- `tests/Feature/` — nuovi test Pest per il formato unificato e per l'aggancio al flusso di import EC
