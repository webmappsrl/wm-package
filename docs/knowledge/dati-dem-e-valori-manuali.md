# Dati DEM e valori manuali

Come nascono e si combinano i dati tecnici di un tracciato — lunghezza, dislivelli, quote, tempi —
su sentieri (`EcTrack`) e istanze del Catasto Sentieri (`TrailApplication`).

## Come funziona oggi

- **Quattro sorgenti in `properties`, una sola precedenza.** I nove campi (`distance`, `ascent`,
  `descent`, `ele_max`, `ele_min`, `ele_from`, `ele_to`, `duration_forward`, `duration_backward`)
  stanno in `manual_data`, `osm_data`, `dem_data` e, per eredità di GeoHub, al primo livello.
  Chi mostra il dato usa `HasDemClassification::classifyField()`: MANUAL, poi OSM (solo con
  `osmid`), poi DEM. Un manuale vuoto o `null` fa tornare il valore successivo.
- **Unità**: `distance` km, dislivelli e quote metri, tempi minuti interi (il servizio DEM li
  calcola con `intval`, `geobox2/dem` `SlopeAndElevationTrait::calcDuration()`), e
  `PBFGeneratorService` li legge con `::integer`.
- **Il tab DEM di Nova** (`AbstractGeometryResource::getDemTabFields()`) mostra per ogni campo la
  tabellina DEM / manuale / corrente e, nel form, i nove Field `manual_data` con validazione per
  campo: tempi `nullable|integer|min:0`, quote `nullable|numeric` (anche negative), il resto
  `nullable|numeric|min:0`. Gli altri cinque Field del tab (`round_trip`, durate bici ed
  escursionismo) scrivono in `dem_data` e non hanno restrizioni di visibilità.
- **Sul sentiero** il DEM lo calcola la catena di `EcTrackService` (`UpdateEcTrackDemJob`,
  `UpdateEcTrack3DDemJob`), alla creazione e a ogni modifica della geometria.
- **Sull'istanza** lo calcola `UpdateTrailApplicationDemJob`: accodato alla creazione con
  `afterCommit()`, rilanciato dal dettaglio solo se la geometria è valida e `dem_data` è vuoto,
  unico per istanza con lock su Redis. Scrive in SQL solo `dem_data` e la geometria con la Z del
  DEM, senza toccare `updated_at`. Il file caricato resta intatto nella collection
  `original_geometry`. L'operatore corregge i manuali solo mentre l'istanza è `under_review`; con
  l'approvazione `properties` e il file passano al sentiero.
- **`updateManualData()` parte dai manuali esistenti** e aggiunge solo i valori al primo livello
  diversi da DEM e OSM: non cancella più ciò che è stato scritto dal tab.
- **Invertire il verso di una traccia** passa da `EcTrackService::reverse()`, chiamato dall'Action
  Nova `ReverseTrackDirectionAction` (solo Administrator). L'utente sceglie se invertire la geometria
  e quali coppie scambiare: `ascent`/`descent`, `ele_from`/`ele_to`,
  `duration_forward`/`duration_backward` in `manual_data`, `from`/`to` al primo livello
  (`REVERSE_SWAP_PAIRS`). `distance`, `ele_min` ed `ele_max` non si toccano. Gli scambi scrivono la
  sola colonna `properties` con un update mirato e aggiornano `updated_at`. Dopo il commit parte una
  catena dedicata (con la geometria: il blocco geometria di `updateDataChain()` meno
  `REVERSE_EXCLUDED_JOBS`, più la coda; con i soli scambi: PBF e AWS) e poi la reindicizzazione
  Scout, in un `try/catch`. Le tracce con `osmid` sono in sola lettura.

## Perché così

- **Il manuale vince, il DEM resta visibile** (oc:8571): in istruttoria Forestas confronta il
  dichiarato con il calcolato; se il manuale non convince lo si cancella e torna il DEM. Call
  tecnica Sardegna Sentieri del 15/09/2026.
- **La Z dell'istanza è sempre quella del nostro DEM** (oc:8571): le quote dei file caricati
  vengono spesso da altri DEM, non dal campo. Per i casi rilevati sul campo c'è il file originale.
- **Il job dell'istanza scrive in SQL e non tocca `updated_at`** (oc:8571): la geometria nel
  package non passa dall'ORM, e un `updated_at` aggiornato faceva rifiutare da Nova (409) il
  salvataggio di un operatore con il form già aperto.
- **Tempi interi** (oc:8571): un tempo decimale in `manual_data` faceva fallire la generazione
  dell'intera tile PBF.
- **L'exporter Excel passa da `classifyField()`** (oc:7984): leggendo `properties.*` lo stesso dato
  usciva diverso a seconda di dove lo si guardava.
- **L'inversione non tocca i manuali da sola** (oc:8543): i manuali sono spesso inseriti pensando
  già al verso giusto (tipico con un GPX disegnato al contrario), quindi ogni coppia si scambia solo
  su scelta esplicita; e la geometria si può lasciare com'è per scambiare dati dopo un'inversione
  già fatta.
- **Fuori dalla catena dell'inversione `UpdateEcTrackManualDataJob` e `UpdateEcTrackCurrentDataJob`**
  (oc:8543): il primo ricalcolerebbe i manuali dal primo livello sovrascrivendo lo scambio; il
  secondo in coda non fa nulla (`getDirty()` è vuoto su un modello riletto dal DB). Restano nelle
  catene standard fino a oc:8642.
- **Le tracce OSM non si invertono** (oc:8543): senza manuale mostrano i valori OSM, e al primo
  salvataggio `UpdateEcTrackFromOsmJob` riscrive la geometria da OSM. Il verso si corregge su
  OpenStreetMap.
- **`reverse()` aggiorna `updated_at`, al contrario del job dell'istanza** (oc:8543): app ed export
  incrementali scelgono le tracce da riscaricare con quella data. Un operatore con il form della
  traccia aperto riceve il 409 di Nova, che qui è corretto: i dati sono cambiati.
- **`ascent` nell'indice Scout è il valore corrente** (oc:8543), come `distance` e
  `duration_forward`: prima si leggeva dal primo livello, vuoto su Forestas.

## Come ci siamo arrivati

- **`updateManualData()` ricostruiva `manual_data` da zero** (superata in oc:8571): è la logica di
  GeoHub, dove i nove campi erano colonne di `ec_tracks`. Cancellava a ogni modifica della
  geometria i valori scritti dal tab DEM, che vivono solo in `manual_data`. L'eliminazione del
  primo livello, che attraversa package e osm2cai2, è in oc:8642.
- **L'import da Sardegna Sentieri scrive i tempi e le quote di Drupal in `manual_data`**: nella
  call del 14/09/2026 si è deciso di smettere, perché quei valori venivano dalla vecchia libreria
  Webmapp (oc:8641).
- **Inversione con la catena completa di `updateDataChain(forceGeometryChain: true)`** (superata in
  oc:8543): accodava `UpdateEcTrackManualDataJob`, che allora azzerava `manual_data`, e il
  `$chain[0]->afterCommit()` aggiunto dentro `updateDataChain()` cambiava il comportamento di tutti
  i chiamanti. Prima ancora (primo ciclo) partiva solo il DEM, e scheda, profilo e app restavano al
  verso vecchio.
