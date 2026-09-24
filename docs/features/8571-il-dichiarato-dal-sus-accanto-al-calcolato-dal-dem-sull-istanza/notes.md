> Ticket: oc:8571

# Notes — Il dichiarato dal SUS accanto al calcolato dal DEM, sull'istanza

## Deviazioni dal piano

Vedi [Divergenze dal piano, task per task](#divergenze-dal-piano-task-per-task), in fondo.

## Bug trovati

- `EcTrackService::updateManualData()` riparte da `$manualData = null` e ricostruisce
  `manual_data` dai campi al primo livello di `properties`: cancella i valori scritti dal tab DEM
  (che vanno in `properties->manual_data->*`) a ogni modifica della geometria di qualunque EcTrack,
  non solo all'approvazione di un'istanza. Correzione minima in questo ticket; l'eliminazione del
  primo livello è in oc:8642.
- `EcTrack::toSearchableArray()` legge `ascent` dal primo livello mentre `distance` e
  `duration_forward` passano da `classifyField()`: su Forestas il dislivello indicizzato è sempre
  0. Registrato in oc:8642.

## Decisioni

### Perimetro (24/09/2026)

- Il SUS e l'API di ricezione dei valori dichiarati sono fuori scope: il ticket copre il tab DEM
  sull'istanza e il calcolo DEM a ogni istanza.
- Aggiunta su richiesta del dev, dopo la prima stesura dell'overview: la mappa del registro codici
  nel dettaglio dell'istanza.

### Tag Orchestrator

- oc:8571: associati `forestas` (676) e `wm-package` (635). Tag di contenuto saltati su scelta del
  dev.
- oc:8641 (nuovo, import Drupal): associati `[CALL][FORESTAS][2026] excel registro sentieri` (678)
  e `forestas` (676).
- oc:8642 (nuovo, eliminazione del primo livello): associati `osm2cai2` (613) e `breaking-change`
  (686). Il tag `breaking-change` è stato creato in questa sessione, senza descrizione.

### Ticket separati nati da questo lavoro

- **oc:8641** — l'import da Sardegna Sentieri non scrive più lunghezza, dislivello e tempi. Decisione
  della call tecnica del 14/09/2026: i valori su Drupal erano stati popolati con la vecchia libreria
  Webmapp. Non figlio di oc:8571: altro repo, altro modello, altra decisione.
- **oc:8642** — eliminare i campi DEM al primo livello dei `properties` di EcTrack. Attraversa
  package e osm2cai2 (`PopulatePropertiesFromSourceCommand`, `CleanupSiHikingRoutesManualDataCommand`,
  `RoutesSheet`), quindi `breaking-change`.

### Dalla challenge

- **Z del DEM e file originale**: sull'istanza il DEM scrive sia `dem_data` sia la Z nella geometria,
  perché le quote dei file caricati vengono spesso da altri DEM. Il file caricato (GPX/GeoJSON) si
  conserva tale e quale in una collection media dedicata (`original_geometry`, `singleFile()`),
  scaricabile dal dettaglio e copiata sul sentiero da `copyMedia()` all'approvazione. Il ripristino
  della geometria originale è fuori scope.
- **Job dopo il commit**: il job DEM dell'istanza si accoda con `afterCommit()`, perché Nova crea
  l'istanza e chiama `afterCreate()` nella stessa transazione e la coda `redis` ha
  `after_commit => false`.
- **`updateManualData()`**: correzione minima, parte dal `manual_data` esistente invece che da `null`
  e non cancella mai un valore già presente. Il canale OSM/GeoHub del primo livello resta invariato.
- **Cache**: su Forestas `CACHE_STORE=database`, non Redis: il lock di `ShouldBeUnique` sta in
  `cache_locks`.
- **Scrittura del job**: il calcolo DEM si estrae da `updateDemData()` in un metodo senza
  salvataggio; il job dell'istanza scrive in SQL solo `dem_data` (`jsonb_set`) e la geometria. Il
  rischio di concorrenza con un salvataggio dell'operatore è ipotetico: la scelta viene dalla regola
  del package sulle geometrie.
- **Form di modifica**: `fieldsForUpdate()` con i soli nove Field `manual_data`, perché
  `getDemTabFields()` restituisce anche cinque Field su `dem_data` senza restrizioni di visibilità e
  la Resource non aveva `fieldsForUpdate()` (sarebbe stato modificabile anche `name`).
- **Validazione**: `nullable|numeric|min:0` e help con l'unità sui nove Field `manual_data`, dentro
  `getDemTabFields()`, quindi per tutti i consumer (approvato dal dev dopo aver chiarito che riguarda
  solo i valori manuali nel form).
- **Test**: la base dei test TrailRegistry disattiva per default la chiamata al DEM.
- **Mappa**: l'istanza delega alla mappa del proprio codice (attivo, altrimenti il più recente), che
  resta invariata; sentiero e istanza si distinguono già per colore, tooltip e legenda. Valutate e
  scartate due varianti: mappa dell'istanza senza il sentiero e mappa del registro senza l'istanza.
- **Scartati come ipotetici**: istanze vecchie senza DEM (produzione da zero, UAT azzerata ogni
  notte), rilanci ripetuti con il DEM giù (due operatori), dati personali verso il DEM (servizio
  nostro, usa solo geometria e `id`).

### Stima

- `wm-estimate` (cieco): Misurato 2,08h + Stimato 9,9h = 11,98h. Il dev ha sostituito la quota stimata
  con 1h: **Misurato 2,08h + Stimato 1h = Totale 3,08h**, scritto su Orchestrator.

## Follow-up

- oc:8641, oc:8642.
- Restrizioni per ruolo sul dominio TrailRegistry: oggi chiunque entri in Nova crea e approva
  istanze.
- I cinque Field di `getDemTabFields()` che scrivono in `dem_data` dal form del sentiero.
- Debito PHPStan del package: CI già rossa su develop (baseline con 3 voci, circa 996 errori), a partire
  dai 23 errori di `EcTrackService.php` (alcuni sono difetti veri, es. `convertDuration()`).
- Notebook NotebookLM `forestas tutte le call` creato durante la ricerca con un perimetro poi
  abbandonato: da proporre al dev per la cancellazione a fine lavoro.

## Divergenze dal piano, task per task

Annotate durante l'esecuzione (24/09/2026). Alcune voci sopra, sotto «Dalla challenge», sono state
superate da queste: vale quanto scritto qui.

### Task 3 — il job DEM dell'istanza

- **`uniqueVia()` su Redis**, non previsto dal piano: la regola del package
  `.claude/rules/job-e-import.md` (oc:8564) vieta un lock `ShouldBeUnique` su `CACHE_STORE=database`
  per un job accodato dentro una transazione Nova (errore `25P02`). Il lock quindi **non** sta in
  `cache_locks`, come dicevano l'overview e la voce «Cache» sopra. Il fake di default dei test del
  catasto isola anche Redis (`cache.stores.redis.driver = array`).
- Solo `onQueue('dem')`: `public $queue` è in conflitto con il trait `Queueable`.
- `CASE WHEN jsonb_typeof(properties) = 'object'` al posto di `COALESCE`: la factory crea
  `properties = []`, e `'[]'::jsonb || …` produce un array.
- Il job sta in `src/TrailRegistry/Jobs/`, non in `src/Jobs/` come indicava «Moduli toccati»:
  appartiene al dominio opzionale.
- Dopo la review finale il job **non aggiorna `updated_at`**: toccarlo faceva rifiutare da Nova
  (409) il salvataggio di un operatore che aveva aperto il form prima dell'arrivo del DEM.

### Task 4 — il modello

- Il test «non accoda il job se la creazione va in rollback» è stato sostituito da «accoda il job
  DEM dopo il commit», che verifica `$job->afterCommit === true`: sotto `Bus::fake()` il rinvio al
  commit non è osservabile. Lo scarto al rollback lo garantisce il dispatcher di Laravel.
- Il test «rifiutata» usa la firma reale `release($code, 'application_rejected', auth()->id())`.

### Task 5 — la Resource Nova

- Aggiornato il test preesistente di `TrailRegistryNovaResourcesTest` sul divieto di modifica, che
  ora vale solo fuori da `under_review`.
- Dopo la review finale: `fieldsForIndex()` con le sei colonne di sempre, perché Nova portava
  sull'index anche cinque Field del tab DEM.

### Task 6 — validazione dei manuali

- Regole per campo, decise dal dev dopo la review finale al posto di `nullable|numeric|min:0` per
  tutti: `duration_*` `nullable|integer|min:0` (il DEM restituisce minuti interi e
  `PBFGeneratorService` legge i tempi con `::integer`), `ele_*` `nullable|numeric` (quote negative
  ammesse), `ascent`/`descent`/`distance` `nullable|numeric|min:0`.

### Task 7 — i manuali arrivano sul sentiero

- La preparazione del test usa lo stesso `CASE jsonb_typeof` del job: con `COALESCE` il dato di
  partenza finiva dentro un array e il test falliva senza provare nulla.

### Richiesta a posteriori

- Su richiesta del dev, dopo la review finale, il dettaglio dell'istanza non mostra più il Field
  `Code` «Proprietà» con il JSON grezzo di `properties`: i dati tecnici ora stanno nel tab DEM, e il
  JSON esponeva anche dati del proponente.

### Bypass PHPStan al review-gate

- **2026-09-24 13:32 CEST** — bypass del blocco PHPStan confermato esplicitamente dal dev, che se ne assume la
  responsabilità. Motivazione: errori PHPStan preesistenti in `EcTrackService.php` (23) e
  `AbstractGeometryResource.php` (1, `App\Nova\User not found`) su righe non modificate da
  oc:8571; nessun errore nuovo sulle righe toccate (verificato riga per riga); la CI PHPStan del
  package è già rossa su develop (baseline con 3 voci, circa 996 errori complessivi). Gli errori
  nuovi introdotti dal cambio di base della Resource sono stati corretti prima del review-gate.
