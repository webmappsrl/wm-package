# Mappa del Catasto Sentieri

La mappa delle schede di un codice (`TrailRegistryCode`) e di un'istanza (`TrailApplication`):
Field `TrailRegistryMap`, componente Vue `TrailRegistryMapField/…/DetailField.vue`, che usa come
figlio il componente condiviso `FeatureCollectionMap`. Cosa descrive per chi la usa è in
[docs/resources/TrailRegistry.md](../resources/TrailRegistry.md), sezione «Interfaccia Nova».

## Come funziona oggi

- **Cosa si disegna lo decide il PHP**: `TrailRegistryCode::getFeatureCollectionMap($subject)`
  compone settori, vicini, sentiero e traccia dell'istanza. Il Vue legge poche property:
  - `neighbour` e `codeStatus` sui vicini;
  - `current`, `label`, `codeStatus`, `slopeChart` e `subjectKind` su **una sola** linea, quella
    del codice in esame (`markSubject()`).
- **Il punto di vista conta**: dalla scheda del codice la linea del codice in esame è il sentiero
  se c'è, altrimenti la traccia dell'istanza (`MAP_SUBJECT_CODE`); dalla scheda dell'istanza è
  sempre la traccia proposta (`MAP_SUBJECT_APPLICATION`). La legenda riceve lo stesso punto di
  vista della mappa.
- **I segnavia** (come appaiono e da che zoom: [TrailRegistry.md](../resources/TrailRegistry.md))
  si disegnano su canvas in `trail-sign.mjs`, che contiene solo funzioni pure e ha i suoi test;
  il Vue li mette su due layer propri, i vicini e il codice in esame. I colori sono ripetuti in
  `MapLegendRenderer::SIGNS` e vanno cambiati insieme.
- **Il profilo altimetrico** è lo `SlopeChart` del componente condiviso. Si accende per Resource
  (`->enableSlopeChart()` su istanza e codice), perché il Field nasce spento e la scheda delle
  anomalie non lo vuole. Segue la linea marcata `slopeChart`: il clic o il passaggio del mouse su
  un'altra linea non lo cambiano.
- **Dopo un'Action la mappa si ricarica perché Nova ricrea il dettaglio**, non per una logica
  della mappa. La vista del gestore (centro e zoom) sopravvive grazie a `view-memory.mjs`: si
  salva allo smontaggio e si riprende al montaggio successivo della stessa scheda, entro 10
  secondi e una volta sola. L'URL del GeoJSON porta anche `?v=<mapVersion>` (id, stato e
  `updated_at` del codice, più lo stato dell'istanza), che protegge da una cache HTTP.

## Perché così

- **Il numero del codice in esame è una feature marcata, non dedotta dal Vue** (oc:8662): prima
  la mappa mostrava solo i numeri dei vicini, e un'istanza con la stessa geometria di un'altra
  faceva leggere al gestore il numero sbagliato come fosse il proprio.
- **Segnavia a punto invece di testo lungo la linea** (oc:8662): il testo piegato sulla geometria
  era illeggibile, e il segnavia è il simbolo con cui chi gestisce i sentieri riconosce un numero.
- **Il layer del codice in esame non ha `declutter`** (oc:8662): con `declutter` OpenLayers può
  nasconderlo quando si sovrappone a un vicino, che è proprio il caso dei doppioni.
- **La linea del profilo si dichiara nel GeoJSON** (oc:8662): la regola del componente condiviso,
  «profilo solo se c'è una linea sola», su questa mappa con decine di vicini non sceglierebbe mai
  nulla. La property è letta solo se presente, quindi le altre mappe del package non cambiano.
- **La vista si ricorda fuori dal componente** (oc:8662): lo stato del componente non sopravvive
  al rimontaggio che Nova fa dopo un'Action.

## Come ci siamo arrivati

- **«Nova rilegge la risorsa ma non ricrea il componente»** (oc:8662, superata): era la premessa
  dell'overview, e portava a una chiave nell'URL e a una prop `preserveViewOnReload` per non
  reinquadrare. La review finale ha mostrato che `getResource()` in
  `vendor/laravel/nova/resources/js/views/Detail.vue` azzera `panels` e la mappa rinasce da zero:
  la ricarica c'era già, e la vista si perdeva comunque. La prop resta nel componente condiviso
  (copre la ricarica senza rimontaggio ed è additiva), ma non è lei a tenere la vista.
- **Etichette come `Text` con `placement: 'line'` sui cloni delle linee dei vicini** (oc:8568,
  superata da oc:8662): sostituite dai segnavia a punto.
