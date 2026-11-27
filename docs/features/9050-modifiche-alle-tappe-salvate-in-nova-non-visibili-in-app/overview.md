> Ticket: oc:9050
> Stato: approvata il 2026-11-27

# Modifiche alle tappe salvate in Nova non visibili in app

## Cosa cambia

Le catene di job delle EcTrack non accodano più `UpdateEcTrackGenerateElevationChartImage`, e se un
job di una catena fallisce la traccia viene pubblicata lo stesso: il JSON che l'app legge si
aggiorna anche quando un calcolo secondario non riesce.

## Perché

Su Cammini d'Italia, dal 20/11/2026, le modifiche salvate in Nova su alcune tappe non arrivano in
app (ticket del cliente, esempio la EcTrack 434). In produzione `UpdateEcTrackGenerateElevationChartImage`
fallisce sempre: il modulo nativo `canvas` è compilato per Node 18 (`NODE_MODULE_VERSION 108`) e
l'immagine `wm-phpfpm:8.4` del 22/10 usa Node 20 (`NODE_MODULE_VERSION 115`). Il job sta in catena
prima di `GenerateEcTrackPBFBatch` e di `UpdateEcTrackAwsJob`, e una catena si ferma al primo job che
fallisce: il JSON non viene riscritto e l'app mostra il testo precedente. Dal 20/10 in produzione le
catene ferme sull'elevation chart sono state 790, su 540 tappe diverse.

Allo scrum del 27/11 il team ha deciso di togliere l'immagine dell'elevation chart dal flusso senza
cancellare il codice, e ha proposto che un errore secondario non blocchi tutto l'aggiornamento.

## Punto di partenza

- `EcTrackService::geometryDependentJobs()` contiene `UpdateEcTrackGenerateElevationChartImage` fra
  `SyncModelTaxonomyWhereJob` e `GenerateEcTrackPBFBatch`. La usano `updateDataChain()` (quando cambia
  la geometria), `dispatchDemChain()` (DEM mancante all'apertura della detail view, oc:8660) e
  l'inversione del verso (oc:8543).
- `EcTrackService::createDataChain()` accoda lo stesso job prima di `UpdateEcTrackAwsJob`.
- Le quattro catene sono `Bus::chain(...)->dispatch()` senza `catch()`: un job che fallisce ferma
  quelli dopo, compresi i job di `publicationJobs()`.
- `elevation_chart_image` viene scritto solo da quel job: nel package nessun codice lo legge.

## Requisiti

- <a id="r1"></a>**R1** — Nessuna catena di EcTrack del package accoda `UpdateEcTrackGenerateElevationChartImage`: creazione, aggiornamento con geometria cambiata, DEM mancante all'apertura della detail view, inversione del verso. La classe del job resta nel package.
- <a id="r2"></a>**R2** — Se un job di una catena di EcTrack fallisce, i job di pubblicazione che quella catena esegue quando va a buon fine vengono accodati comunque; nelle catene che contengono i job della geometria, prima della pubblicazione si accoda anche `GenerateEcTrackPBFBatch` della tappa. Il job fallito resta in `failed_jobs`.
- <a id="r3"></a>**R3** — Quando nessun job della catena fallisce, la pubblicazione avviene una volta sola, nello stesso ordine di prima.

## Rischi

- **Vale per tutti gli shard.** Nessuno shard genera più l'immagine dell'elevation chart. Deciso
  allo scrum del 27/11; il difetto di `canvas` con Node 20 è nell'immagine PHP comune, quindi con ogni
  probabilità gli altri shard ne hanno già il fallimento (non verificato sugli altri server).
- **Pubblicazione con dati di quota vecchi.** Con [R2](#r2), se fallisce un job del DEM l'app riceve
  il testo nuovo con i dati di quota precedenti. È voluto: il fallimento resta visibile in Horizon.
- **`catch()` quando fallisce la pubblicazione stessa.** Il `catch()` riaccoda la pubblicazione una
  volta, senza un nuovo `catch()`: non può andare in loop.
- **Fallimenti silenziosi (rischio accettato dal dev, nessun ticket).** Con [R2](#r2) l'app si aggiorna
  anche quando un job della catena fallisce, e il fallimento resta solo in Horizon e in `failed_jobs`.
  Il guasto dell'elevation chart è durato dal 20/10 al 24/11 prima di essere notato, e solo perché il
  testo non si aggiornava.
- **`elevation_chart_image` resta nel JSON.** `EcTrackPropertiesBuilder` pubblica le `properties` e il
  campo punta a un SVG che non si aggiorna più (in produzione 53 JSON su un campione di 60 lo hanno).
  Nei frontend Webmapp compare solo in file di test e fixture (`gh search code`, 27/11), quindi l'app
  non lo mostra.

## Out of scope

- Ricompilare `canvas` o cambiare la versione di Node nell'immagine PHP.
- Cancellare la classe `UpdateEcTrackGenerateElevationChartImage` o togliere `elevation_chart_image`
  dalle `properties` già salvate.
- L'errore `Call to undefined method EcTrack::getDemDataFields()` che `HandlesData` scrive nel log a
  ogni catena con geometria: non ferma la catena.

## Moduli toccati

- `src/Services/Models/EcTrackService.php`
- test delle catene di EcTrack in `tests/`
