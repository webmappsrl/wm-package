> Ticket: oc:8662

# Mappa dell'istanza: dopo «Sostituisci numero» resta il numero vecchio

## Cosa cambia

La mappa del Catasto Sentieri (Field `TrailRegistryMap`), nel dettaglio di un'istanza
(`TrailApplication`) e nel dettaglio di un codice del registro (`TrailRegistryCode`):

1. **mostra il numero del codice in esame**, oggi assente: la feature del tracciato dell'istanza
   ha solo il tooltip «Istanza #N» e nessuna `label`;
2. **disegna i numeri come segnavia CAI**, orizzontali e posati in un punto del tracciato,
   invece che piegati lungo la linea (`placement: 'line'`), con questi stili:

   | Numero | Aspetto |
   |---|---|
   | vicino validato (`assigned`) | bandierina CAI: rosso, fascia bianca con il numero, rosso |
   | vicino proposto (`reserved`, altra istanza) | stessa forma, bordo rosso e interno bianco, senza bande piene |
   | codice in esame | stessa forma dei proposti, bordo e bande arancioni (il colore del tracciato dell'istanza, `rgba(234, 88, 12, 1)`), disegnato sopra tutti gli altri |
   | codice in esame `released` (istanza rifiutata, codice liberato) | grigio e barrato |

3. **si aggiorna da sola dopo «Sostituisci numero», «Approva» e «Rifiuta»**, senza ricaricare la
   pagina e senza spostare la vista che il gestore sta guardando;
4. **mostra il profilo altimetrico** del componente `SlopeChart` già presente nella mappa
   condivisa, oggi spento sulla mappa del catasto:
   - nel dettaglio dell'istanza: il tracciato proposto;
   - nel dettaglio del codice nel registro: il sentiero (`ec_track`) quando c'è, altrimenti il
     tracciato dell'istanza.

## Perché

Il gestore sostituisce il numero e non vede il cambiamento. Il salvataggio è corretto —
`replaceNumber()` crea il codice nuovo e mette il vecchio in `released`, verificato nel DB locale
sulle istanze 2 e 3 — ma sulla mappa:

- il numero dell'istanza non compare mai, né prima né dopo;
- il numero che si vede sopra il tracciato può essere quello di un'altra istanza con la stessa
  geometria: le istanze 2 e 3 sono identiche (`ST_Equals` = true), e sulla mappa dell'istanza 2
  il «61» dell'istanza 3 (ZNUB461) sembra il numero dell'istanza 2 (in realtà ZNUB466);
- il componente condiviso scarica il GeoJSON una volta sola (`onMounted`) e lo riscarica solo se
  cambia `geojsonUrl`, che dipende solo dall'id della risorsa. Dopo un'Action Nova rilegge la
  risorsa (`views/Detail.vue`, `actionExecuted()` → `getResource()`) ma non ricrea il componente:
  l'URL non cambia e la mappa resta quella di prima.

Le etichette piegate lungo la linea sono poco leggibili; il segnavia CAI è il simbolo con cui chi
gestisce i sentieri riconosce un numero.

Il profilo altimetrico serve a valutare il tracciato proposto senza uscire dalla scheda: i dati
ci sono già (geometria con le quote e `dem_data`, oc:8571/oc:8660).

## Requisiti

- [ ] La feature del codice in esame (tracciato dell'istanza e, se c'è, sentiero) porta nel
      GeoJSON il numero (numero + variante, stessa formula dei vicini) e un marcatore che la
      distingue dai vicini.
- [ ] Le feature dei vicini portano lo stato del codice (`assigned` / `reserved`).
- [ ] Le etichette sono segnavia CAI orizzontali, posati in un punto della linea, con gli stili
      della tabella sopra; il codice in esame sta su un layer proprio, sopra i vicini e senza
      `declutter`, così resta visibile anche quando si sovrappone a un vicino con la stessa
      geometria.
- [ ] Se tracciato e sentiero del codice in esame sono entrambi presenti, l'etichetta del codice
      in esame è una sola.
- [ ] Il numero del codice in esame compare anche quando il settore non ha vicini (oggi
      `DetailField.vue` esce subito con `neighbours.length === 0`); a ogni ricarica i layer delle
      etichette precedenti vengono tolti.
- [ ] La soglia di zoom delle etichette (`labelMinZoom`) e lo scostamento reciproco
      (`declutter`) restano in vigore per i vicini.
- [ ] Dopo «Sostituisci numero», «Approva» e «Rifiuta» la mappa si ricarica da sola: l'URL del
      GeoJSON, calcolato dal Field in PHP, contiene una chiave che cambia con il codice mostrato
      (id, stato e `updated_at`), così il watcher esistente su `geojsonUrl` scatta. Un
      `geojsonUrl` impostato esplicitamente da uno shard continua ad avere la precedenza.
- [ ] Alla ricarica automatica la vista (centro e zoom) resta quella del gestore; il tracciato si
      inquadra solo alla prima apertura.
- [ ] Il profilo altimetrico è visibile nel dettaglio dell'istanza (tracciato proposto) e nel
      dettaglio del codice nel registro (sentiero se c'è, altrimenti tracciato dell'istanza). Si
      accende con un meta del Field impostato per Resource: la scheda delle anomalie, che usa lo
      stesso Field, resta senza profilo.
- [ ] Il profilo segue solo la linea in esame: il passaggio del mouse o il clic su un vicino non
      spostano né sostituiscono il profilo.
- [ ] La legenda (`MapLegendRenderer`) descrive i segnavia (validato, proposto, codice in esame,
      codice liberato) e il profilo.
- [ ] La linea del profilo si indica esplicitamente nel GeoJSON (una property sulla feature), e
      `getSlopeChartTrackFromGeojson()` la preferisce; senza la property il comportamento
      attuale (una sola linea → profilo, più linee → niente) resta identico.
- [ ] Test PHP sulle property del GeoJSON (numero e stato del codice in esame, stato dei vicini,
      linea del profilo nei due contesti, chiave dell'URL che cambia dopo sostituzione,
      approvazione e rifiuto) e test JS su `getSlopeChartTrackFromGeojson()`, lanciati a mano
      prima della review.
- [ ] Bundle JS ricompilati (`dist/`) per entrambi i Field toccati, con `npm ci` dal lockfile:
      `TrailRegistryMapField/dist` incorpora una copia di `FeatureCollectionMap.vue` e
      `utils.mjs`, e le due copie non devono divergere.

## Rischi

- **Componente condiviso**: `FeatureCollectionMap` e le sue `utils.mjs` sono usati anche da altri
  shard. La modifica è additiva (una property in più letta solo se presente); il comportamento
  senza la property resta coperto dai test esistenti.
- **I test JS non girano in CI** (`.github/workflows` non ha step Node): una regressione della
  mappa condivisa non verrebbe segnalata. Mitigazione: test lanciati a mano in questo ticket,
  step in CI in un ticket a parte.
- **Tre artefatti da tenere allineati** (sorgenti e due `dist/`): un eventuale revert va fatto su
  tutti e tre, poi va aggiornato il submodule negli shard.

## Out of scope

- La mappa della scheda delle anomalie (`TrailRegistryAnomaly::getFeatureCollectionMap()`): ha una
  composizione propria e non cambia.
- La logica di `replaceNumber()` e dell'Action «Sostituisci numero»: funzionano già.
- L'esclusione dai vicini dei codici con geometria identica all'istanza: il codice in esame viene
  disegnato sopra e resta leggibile; i doppioni restano visibili come vicini.
- Il calcolo del DEM: si usano i dati già presenti. Se il job DEM non ha ancora finito, il
  profilo resta vuoto fino al ricaricamento.
- Lo step in CI per i test JS: ticket a parte.

## Moduli toccati

Tutto nel submodule `wm-package`; in Forestas solo l'aggiornamento del puntatore del submodule.

- `wm-package/src/TrailRegistry/Models/TrailRegistryCode.php` — numero e marcatore sulla feature
  del codice in esame, stato sui vicini, linea del profilo secondo il contesto
- `wm-package/src/TrailRegistry/Models/TrailApplication.php` — delega a `mapCode()` indicando il
  contesto «istanza» per il profilo
- `wm-package/src/TrailRegistry/Nova/Fields/TrailRegistryMap.php` — URL del GeoJSON che cambia con
  il codice mostrato, meta per accendere il profilo
- `wm-package/src/TrailRegistry/Nova/TrailApplication.php`, `wm-package/src/TrailRegistry/Nova/TrailRegistryCode.php`
  — profilo acceso sul Field
- `wm-package/src/TrailRegistry/Nova/MapLegendRenderer.php` — legenda dei segnavia e del profilo
- `wm-package/src/Nova/Fields/FeatureCollectionMap/resources/js/components/FeatureCollectionMap.vue`
  — vista mantenuta alla ricarica, profilo che segue solo la linea indicata
- `wm-package/src/TrailRegistry/Nova/Fields/TrailRegistryMapField/resources/js/components/DetailField.vue`
  — segnavia CAI, etichetta del codice in esame, profilo acceso
- `wm-package/src/Nova/Fields/FeatureCollectionMap/resources/js/slope-chart/utils.mjs` (+ test) —
  scelta della linea del profilo tramite property
- `wm-package/src/TrailRegistry/Nova/Fields/TrailRegistryMapField/dist/`,
  `wm-package/src/Nova/Fields/FeatureCollectionMap/dist/` — bundle ricompilati
- `wm-package/tests/Feature/TrailRegistry/…` — test sulle property del GeoJSON
