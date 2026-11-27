# Catene di job delle EcTrack e pubblicazione di ripiego

## Come funziona oggi

Ogni salvataggio di una EcTrack accoda una catena di job costruita in `EcTrackService`:
`updateDataChain()`, `createDataChain()`, `dispatchDemChain()` e `reverse()`. Quali job contengono
lo dice il codice (`geometryDependentJobs()`, `publicationJobs()` e la coda propria di
`createDataChain()`); la composizione della catena del DEM e dell'inversione è descritta anche in
[dati-dem-e-valori-manuali.md](dati-dem-e-valori-manuali.md).

Tutte passano da `EcTrackService::dispatchChainWithFallback()`, che aggiunge un `->catch()`: se un
job fallisce, si accodano i job che la catena esegue per pubblicare quando riesce, compreso
`GenerateEcTrackPBFBatch` se la catena lo esegue (geometria cambiata, DEM, inversione; anche
l'inversione dei soli scambi). La creazione non esegue il PBF e il suo ripiego neppure. Il ripiego
non ha a sua volta un `catch()`, e il job fallito resta in `failed_jobs`. Le catene custom di
`ExecuteEcTrackDataChainAction` usano come ripiego i job di pubblicazione che contengono; una catena
senza pubblicazione, o fatta solo di quella (come «Update Tracks on AWS»), non ha ripiego. I vincoli
del codice (closure `static`, nome di `dispatchFallbackJobs()` da non cambiare) sono nei docblock e
in `.claude/rules/job-e-import.md`.

`UpdateEcTrackGenerateElevationChartImage` non è in nessuna catena. La classe resta, ma
`elevation_chart_image` già salvato nelle `properties` non si aggiorna più.

## Perché così

- **Pubblicare anche quando un job fallisce** (oc:9050): una catena si ferma al primo job che
  fallisce, e un calcolo secondario rotto lasciava l'app con il testo vecchio senza che nessuno se ne
  accorgesse. Un DEM che fallisce ora pubblica il testo nuovo con le quote precedenti; il costo
  accettato è che il fallimento resta visibile solo in Horizon e in `failed_jobs`.
- **Il PBF nel ripiego delle catene che lo eseguono** (oc:9050): senza, la detail view mostrerebbe il
  tracciato nuovo e la mappa quello vecchio.
- **Ripiego uguale alla pubblicazione della catena riuscita** (oc:9050): la creazione non ha il PBF
  nemmeno quando riesce, quindi non lo ha nemmeno il ripiego.
- **Elevation chart fuori dal flusso** (oc:9050, scrum del 27/11): il modulo nativo `canvas` è
  compilato per Node 18 e l'immagine `wm-phpfpm:8.4` usa Node 20 dal 22/10. Il job falliva sempre e
  fermava la catena prima del PBF e della pubblicazione: su Cammini d'Italia 790 catene ferme su 540
  tappe fra il 20/10 e il 27/11 (`failed_jobs` di produzione, contati il 27/11). Nei frontend Webmapp
  `elevation_chart_image` compare solo in test e fixture (`gh search code` sull'organizzazione, 27/11).

## Come ci siamo arrivati

- **Ricompilare `canvas` per Node 20** (oc:9050, scartata): il team non vuole più generare
  l'immagine nel package.
- **Rendere «non bloccanti» i job secondari con un try/catch dentro ciascuno** (oc:9050, scartata):
  bisognava decidere job per job cosa è secondario, e gli errori sarebbero spariti da `failed_jobs`.
- **Rimettere in pari i dati con un batch PBF per ogni tappa ferma** (oc:9050, scartata): migliaia di
  `GeneratePBFJob` non unici sulla coda `pbf`, e nessuna rigenerazione dei tile del tracciato vecchio.
  Si usa invece la pubblicazione per tappa più un solo `DispatcherAppPbfsDebouncedJob` per App.
