> Ticket: oc:8660

# DEM automatico alla visualizzazione anche per EcTrack

## Cosa cambia

Quando si apre la pagina di dettaglio di una EcTrack in Nova e il suo DEM manca, il calcolo parte
da solo, come oggi succede per le istanze del Catasto Sentieri (`TrailApplication`).

Il meccanismo smette di essere specifico del TrailRegistry e sale nel model padre comune
`Wm\WmPackage\Models\Abstracts\MultiLineString`, da cui ereditano `EcTrack`, `TrailApplication` e
`UgcTrack`:

- `needsDem()`, `acquireDemLock()` e `dispatchDemIfMissing()` sono scritti una volta sola nel
  padre;
- `dispatchDem()` è un metodo vuoto nel padre, ridefinito da ogni model con ciò che va accodato;
- il lato Nova è un trait con `dispatchDemOnDetail($request)`, chiamato esplicitamente nel
  `fields()` delle Resource che lo vogliono.

Il DEM si considera **mancante** quando vale almeno una di queste condizioni:

- `properties['dem_data']` è vuoto;
- tutte le Z delle coordinate della geometria valgono `0`.

Il criterio vale per entrambi i model: `TrailApplication` guadagna quindi anche il controllo sulle
quote, che oggi non ha.

## Perché

Su EcTrack il DEM si calcola solo alla creazione, con la catena `createDataChain()` lanciata da
`EcTrackObserver::created()`. Se la catena fallisce, o se la traccia non la attraversa mai, il DEM
resta vuoto finché qualcuno non lo rilancia a mano. Su Forestas è il caso normale: l'import da
Sardegna Sentieri salva con `saveQuietly()` e di proposito non calcola il DEM (oc:8641). In locale
767 EcTrack su 769 non hanno `dem_data` e 393 hanno tutte le quote a zero.

Le istanze del Catasto Sentieri hanno già il ricalcolo alla visualizzazione (oc:8571): portarlo
nel padre evita di riscriverlo per EcTrack e, in un ticket successivo, per gli UGC.

## Requisiti

- [ ] `MultiLineString::needsDem()` restituisce `false` se la geometria non è valida o è vuota:
      il controllo resta `ST_IsValid AND NOT ST_IsEmpty` in SQL, come oggi su TrailApplication,
      spostato nel padre sulla tabella del model (`getTable()`). Con geometria valida restituisce
      `true` se `properties['dem_data']` è vuoto oppure se tutte le Z delle coordinate valgono `0`;
      le Z si controllano in PHP sul GeoJSON del model
- [ ] `MultiLineString::acquireDemLock()` prende il lock del model: unico per classe e id, su
      Redis, con durata di **un'ora**, uguale per EcTrack e TrailApplication. Sta nel padre, non
      nei job: `Bus::chain()` non rispetta `ShouldBeUnique` (`PendingChain::dispatch()` passa dal
      `Dispatcher` e non da `PendingDispatch`, dove il controllo vive)
- [ ] `MultiLineString::dispatchDemIfMissing()` chiama `dispatchDem()` solo se `needsDem()` è vero
      e `acquireDemLock()` riesce
- [ ] Il lock si prende anche alla creazione: `EcTrackService::createDataChain()` per EcTrack e il
      `created()` di TrailApplication chiamano `acquireDemLock()`. Il dettaglio aperto subito dopo
      la creazione (Nova reindirizza lì) non accoda una seconda catena in parallelo alla prima
- [ ] `MultiLineString::dispatchDem()` nel padre non fa nulla (non `abstract`: `UgcTrack` non deve
      implementarlo)
- [ ] `TrailApplication::dispatchDem()` accoda `UpdateTrailApplicationDemJob`; `uniqueFor` del job
      passa da 600 a 3600 secondi
- [ ] `EcTrack::dispatchDem()` accoda una `Bus::chain` composta da
      `EcTrackService::geometryDependentJobs($track, [SyncModelTaxonomyWhereJob::class])`
      seguita da `EcTrackService::publicationJobs($track)`, riusando le liste esistenti.
      `GenerateEcTrackPBFBatch` resta nella catena: le tile leggono `distance` e
      `duration_forward` anche da `dem_data` (`PBFGeneratorService.php:262-270`), e senza di lui
      mappa e filtri non vedrebbero i valori calcolati
- [ ] Un trait Nova espone `dispatchDemOnDetail(NovaRequest $request)`: agisce solo su
      `isResourceDetailRequest()` e chiama `dispatchDemIfMissing()` sul model
- [ ] `TrailRegistry\Nova\TrailApplication::fields()` usa il trait al posto della chiamata diretta
      di oggi; `Nova\EcTrack::fields()` del package lo chiama. Forestas eredita il comportamento
      tramite `parent::fields()` di `app/Nova/EcTrack.php`, senza modifiche
- [ ] Il comportamento è attivo di default su tutti gli shard, senza chiave di config
- [ ] La creazione di una `TrailApplication` continua ad accodare il job DEM dopo il commit, come
      oggi
- [ ] Nuovo file di test dedicato al padre `MultiLineString`, con dentro tutti i test del DEM per
      entrambe le estensioni (TrailApplication ed EcTrack). I test DEM oggi in
      `tests/Feature/TrailRegistry/TrailApplicationDemTriggerTest.php` si spostano lì; restano nel
      file vecchio solo i test che non riguardano il DEM (la mappa dell'istanza). Casi coperti, per
      ciascuno dei due model:
  - geometria non valida → non parte
  - `dem_data` vuoto → parte
  - `dem_data` pieno e Z tutte a zero → parte
  - `dem_data` pieno e Z diverse da zero → non parte
  - geometria assente → non parte
  - due chiamate di fila → un solo dispatch, per il lock
  - dettaglio aperto subito dopo la creazione → nessun secondo dispatch, per il lock preso alla
    creazione
  - per EcTrack: la catena contiene i job DEM e il PBF, senza taxonomy, seguiti da quelli di
    pubblicazione
  - per TrailApplication: la creazione accoda ancora il job dopo il commit
- [ ] I test girano sulla suite del package (database `wm_package`), dopo aver verificato
      l'isolamento

## Rischi

- **Due catene in parallelo su una EcTrack appena creata da Nova.** L'observer lancia
  `createDataChain()` e Nova reindirizza al dettaglio, dove `dem_data` è ancora vuoto; due catene
  che salvano l'intero `properties` si cancellano a vicenda i valori. *Mitigazione:* il lock del
  padre si prende anche alla creazione.
- **409 di Nova se l'operatore apre il form mentre la catena lavora.** I job EcTrack salvano con
  `saveQuietly()`, che aggiorna `updated_at`. *Accettato:* su EcTrack `updated_at` è ciò che app ed
  export usano per riscaricare la traccia (`AppExportController.php:21`,
  `EcTrackService.php:484`, `:686`), quindi non va soppresso come su TrailApplication (oc:8571).
  Succede già oggi alla creazione; basta ricaricare la pagina.
- **Tile PBF senza i valori DEM.** Le tile leggono `dem_data`: *mitigazione*, il PBF resta nella
  catena.
- **Geometria non valida che fa partire un job destinato a fallire.** *Mitigazione:* il controllo
  `ST_IsValid AND NOT ST_IsEmpty` in SQL resta, spostato nel padre.

Ipotetici, valutati e ignorati: geometrie 2D (le colonne sono `MultiLineStringZ`); `getGeojson()`
che restituisce `null` per un errore interno; salvataggio dell'operatore sovrascritto mentre il job
attende il servizio DEM; lock rimasto su un id riciclato dopo il reset delle 06:00 di Forestas;
Redis irraggiungibile; job futuri aggiunti a `geometryDependentJobs()`.

## Out of scope

- Ricalcolo in massa delle tracce senza DEM (comando di backfill): lo gestisce il dev al go-live
  di Forestas
- Applicazione del meccanismo a `UgcTrack`: il padre lo rende possibile, ma l'aggancio alla
  Resource UGC è un ticket successivo
- Modifiche all'import da Sardegna Sentieri, che continua a non calcolare il DEM (oc:8641)
- Il calcolo DEM alla creazione di una EcTrack (`createDataChain()`) resta com'è

## Moduli toccati

Tutto nel repo `wm-package`:

- `src/Models/Abstracts/MultiLineString.php` — `needsDem()`, `acquireDemLock()`,
  `dispatchDemIfMissing()`, `dispatchDem()`
- `src/Services/Models/EcTrackService.php` — `createDataChain()` prende il lock
- `src/Models/EcTrack.php` — `dispatchDem()` con la catena
- `src/TrailRegistry/Models/TrailApplication.php` — `dispatchDem()`; `created()` prende il lock;
  `needsDem()` e `dispatchDemIfMissing()` locali rimossi a favore del padre
- `src/TrailRegistry/Jobs/UpdateTrailApplicationDemJob.php` — `uniqueFor` a 3600
- nuovo trait Nova in `src/Nova/Traits/` con `dispatchDemOnDetail()`
- `src/Nova/EcTrack.php` e `src/TrailRegistry/Nova/TrailApplication.php` — uso del trait in
  `fields()`
- nuovo test del padre in `tests/Feature/` con i test DEM di entrambi i model
- `tests/Feature/TrailRegistry/TrailApplicationDemTriggerTest.php` — rimozione dei test DEM
  spostati
- `docs/knowledge/dati-dem-e-valori-manuali.md` — aggiornamento a fine lavoro

Repo `forestas`: nessuna modifica al codice. A fine lavoro va aggiornata
`docs/knowledge/8641-import-sardegna-sentieri-non-scrive-manual-data.md`, che oggi dice che in Nova
il tab DEM di un sentiero importato resta vuoto.
