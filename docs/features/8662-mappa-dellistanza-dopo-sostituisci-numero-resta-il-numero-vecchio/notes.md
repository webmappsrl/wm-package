> Ticket: oc:8662

# Notes — Mappa dell'istanza: dopo «Sostituisci numero» resta il numero vecchio

## Divergenze dal piano, task per task

### Task 1 GeoJSON codice in esame e stato dei vicini

Il test «un codice liberato resta il codice in esame» crea il codice `Reserved` con `makeCode()`
e poi lo passa a `Released`, invece di preparare a mano istanza e geometria come scritto nel piano.
È la stessa transizione di «Rifiuta» e `makeCode()` crea già l'istanza con la geometria solo per i
`Reserved`: l'asserzione è identica.

### Task 5 segnavia CAI funzioni pure

- Aggiunto `TrailRegistryMapField/vitest.config.cjs`, copia di quello di `FeatureCollectionMap`:
  senza, vitest risale le cartelle, trova il `vite.config.js` di Forestas e non parte
  (`TypeError: laravel is not a function`).
- `npm install --save-dev vitest` ha aggiunto 96 pacchetti al `package-lock.json` senza cambiare la
  versione di nessun pacchetto già presente (verificato confrontando i due lockfile); `webpack`
  resta `5.103.0`.

### Task 6 DetailField del catasto segnavia numero corrente ricarica profilo

- **La vista si mantiene con una memoria di modulo, non solo con `preserveViewOnReload`.** La review
  finale ha trovato che Nova 5.7.6, dopo un'Action, **ricrea** il dettaglio: `getResource()` in
  `vendor/laravel/nova/resources/js/views/Detail.vue:227-228` azzera `panels` e `resource`, i
  pannelli si smontano e si rimontano. Lo stato del componente si perde, e `hasFitted` riparte da
  `false`. Aggiunto `view-memory.mjs` (con test): la vista si salva in `beforeUnmount` e si riprende
  al montaggio successivo della stessa scheda, entro 10 secondi e una volta sola. La prop
  `preserveViewOnReload` del componente condiviso resta: copre la ricarica senza rimontaggio ed è
  additiva.
- **`refitOn` solo con i vicini, come prima.** Chiamarlo sempre cambiava lo zoom della mappa delle
  anomalie, che la spec vuole invariata.
- **Passaggio del mouse sul profilo**: con la linea del profilo fissata, il componente condiviso la
  cerca sotto il mouse con una seconda `forEachFeatureAtPixel` filtrata su `slopeChart`, invece di
  guardare solo la feature più in alto. Senza, sulla scheda di un codice approvato la traccia
  arancione (sopra) copriva il sentiero verde (profilo) e il marker non si muoveva mai.

## Bug trovati

- **La diagnosi dell'overview sulla ricarica era sbagliata.** L'overview dice che dopo un'Action
  Nova «rilegge la risorsa ma non ricrea il componente», e che per questo la mappa non si
  aggiornava. In realtà il componente viene ricreato e il GeoJSON riscaricato; la rotta non manda
  header di cache. Il bug originale era solo il numero dell'istanza assente dalla mappa, confuso con
  quello di un'istanza sovrapposta. Il parametro `?v=` nell'URL resta: è innocuo e protegge da una
  eventuale cache HTTP.

- **Passando sul profilo la mappa si svuotava** (trovato dal dev in Nova). Il marker nero che il
  profilo posa sulla traccia è un `CircleStyle` con colori stringa (`rgba(0, 0, 0, 0.9)`): per il
  canvas di hit detection OpenLayers li converte con `color-parse`, e nel bundle del catasto quella
  conversione fallisce con `Failed to parse "rgba(0, 0, 0, 0.9)" as color`. L'eccezione interrompe
  il disegno dell'intera mappa, tile comprese. Nel bundle condiviso lo stesso codice funziona (sulla
  scheda di un EcTrack il marker compare). I due campi hanno versioni diverse delle librerie colore,
  già nei lockfile prima di questo lavoro: `color-parse` 2.1.2 / `color-rgba` 3.1.1 nel catasto,
  2.0.2 / 3.0.0 nel condiviso; in Node la 2.1.2 legge il colore correttamente, quindi il guasto
  nasce da come webpack la impacchetta. Corretto passando i colori del marker come array
  `[r, g, b, a]`, che OpenLayers usa senza parsing. Verificato in Nova: mappa visibile, marker sulla
  traccia, console pulita.

## Decisioni

- **Traduzioni**: le nuove voci della legenda sono stringhe italiane dentro `__()`, senza chiavi in
  `resources/lang/*.json`. È la convenzione già in uso per tutte le voci di `MapLegendRenderer`,
  nessuna delle quali è tradotta; tradurre solo le nuove creerebbe una legenda metà tradotta.

- **Settore davanti al numero sui segnavia** (richiesta del dev dopo il rilascio, diretta su
  `develop`): l'etichetta passa da numero e variante (`11`, `10A`) a settore, numero e variante
  (`211`, `210A`), come le opzioni del «sostituisci numero». Il tooltip resta `Sentiero` + codice
  intero.

## Follow-up

Rilievi minori della review finale, non corretti:

- sopra il segnavia arancione il tooltip «Istanza #N» e il clic sulla traccia non si raggiungono (il
  segnavia è un punto senza `tooltip` né `link`);
- la voce «Profilo altimetrico» della legenda compare anche quando la geometria non ha ancora le
  quote e il profilo è vuoto;
- con due istanze identiche il segnavia del vicino sta esattamente sotto quello arancione e non si
  legge: il doppione si scopre solo dal tooltip;
- nel bundle del catasto ogni colore **stringa** che OpenLayers deve convertire (hit detection di
  `CircleStyle`/`RegularShape`, `Icon` con `color`) fallisce: chi aggiunge punti o forme a quella
  mappa passi i colori come array, o si allineino le versioni di `color-parse` fra i due campi;
- `docs/resources/TrailRegistry.md` descrive ancora i numeri «scritti sul tracciato»: da aggiornare
  in `update-context`.

- Step in CI per i test JS (vitest) della mappa condivisa e del catasto: oggi `.github/workflows`
  non ha step Node e questi test si lanciano solo a mano (deciso in challenge).
