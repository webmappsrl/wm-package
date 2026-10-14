> Ticket: oc:8776

# Mappa della UGC track leggibile — piano di implementazione

> Riscritto a fine lavoro sulla versione definitiva, prima di ogni commit: il piano iniziale
> (tappe e traccia a 10px, riempimenti, tratteggio su tutta la mappa) è stato superato in otto
> giri di prova su localhost con il dev. La storia dei tentativi e il perché di ogni scelta sono
> in [notes.md](notes.md). Il codice è nel diff: qui i task, i file e come si verifica ciascuno.

**Obiettivo:** nella mappa della UGC track in Nova la traccia non copre più le tappe che percorre,
si capisce quali cammini segue anche dove più cammini condividono il sentiero, e le tappe solo
vicine o toccate di passaggio non fanno macchia.

**Architettura:** il modello `UgcTrack` decide cosa disegnare e con quali proprietà; un servizio
PostGIS (`ContextTrackBandsService`, con cache) trova i pezzi di tappa toccati dalla traccia e i
cammini che passano in ciascuno; il campo `FeatureCollectionMap` impara a disegnare proprietà
facoltative nuove (`casing`, `zoomedOutStyle`, `onlyZoomedOut`, `tooltipOrder`) e un tooltip con
tutte le linee (`stackedTooltip()`), lasciando identico lo stile delle feature che non le usano.

**Tech stack:** Laravel 12, Nova 5, PostGIS, Pest; Vue 3 + OpenLayers 9.2.4, vitest, Laravel Mix.

**Spec:** [overview.md](overview.md)

## Vincoli globali

- PHP minimo `>8.1`; geometrie solo via SQL; nelle query raw niente commenti `--` con `:parola`.
- Documentazione e commenti in italiano; identificatori in inglese.
- Test PHP del package, dalla root di camminiditalia:
  `docker run --rm --network camminiditalia_default -e DB_HOST=postgres-camminiditalia -v "$PWD/wm-package:/app" -w /app wm-phpfpm:8.4 vendor/bin/pest <test>`
- Test JS e `dist`: dentro `wm-package/src/Nova/Fields/FeatureCollectionMap/`, `npm test` e
  `npm run prod`; il `dist` è versionato.
- Nessun testo nuovo da tradurre: «Traccia registrata» usa la chiave esistente `Recorded track`.

## Nomi nuovi

| Nome | Dove | Cosa indica |
|---|---|---|
| `casing` | proprietà di una feature | contorno sotto la linea, a ogni zoom |
| `zoomedOutStyle` | proprietà di una feature | stile da una `minResolution` in su (mappa vista da lontano); con `dashColors` e `dashLength` segmenti alternati |
| `onlyZoomedOut` | proprietà di una feature | la feature si disegna solo da lontano |
| `tooltipOrder` | proprietà di una feature | posizione della riga nel tooltip con tutte le linee |
| `stackedTooltip()` | `FeatureCollectionMap` (PHP) | opzione del campo: tooltip con tutte le linee sotto il mouse |
| `lineStyles(props, resolution)` | `line-style/utils.mjs` | elenco degli stili di una linea a quella `resolution` |
| `stackedTooltipText(propsList)` | `tooltip/utils.mjs` | testo del tooltip con tutte le linee |
| `ContextTrackBandsService::bands()` | `src/Services/Models/` | pezzi di tappa, eventualmente solo quelli toccati da una UgcTrack, con i cammini che passano in ciascuno |
| `UgcTrack::contextRows()` | modello | tappe mostrate: solo le toccate se la corrispondenza c'è |
| `UgcTrack::touchedPortionFeatures()` | modello | pezzi toccati a 7px e segmenti da lontano |

## Review focus

1. **Latitudine e `resolution`.** La soglia «da lontano» va divisa per cos(lat), perché OpenLayers
   misura la `resolution` in unità EPSG:3857. → test della soglia in Task 4.
2. **Corrispondenza assente o fallita.** Con stato diverso da `computed` le righe vecchie di
   `ugc_track_ec_track_matches` non valgono: si mostrano tutte le tappe della zona. → Task 4.
3. **Calcolo PostGIS che fallisce.** La mappa non deve sparire: tappe intere a 5px. → Task 3 e 4.
4. **Altri usi del campo.** Una feature senza proprietà nuove deve avere lo stesso stile, lo stesso
   numero di `Style` e lo stesso `zIndex`. → Task 1; suite delle mappe del campo in Task 6.
5. **Tappe disegnate in versi opposti.** I pezzi devono uscire tutti nello stesso verso. → Task 3.

---

### Task 1: stili delle linee nel campo — `lineStyles()`

**File:** `src/Nova/Fields/FeatureCollectionMap/resources/js/line-style/utils.mjs`,
`utils.test.mjs`, `components/FeatureCollectionMap.vue` (funzione di stile).

- [x] `lineStyles(props, resolution)` restituisce un elenco di stili:
  - senza `zoomedOutStyle` o sotto la sua `minResolution`: `[lineStyleSpec(props)]`, con sotto il
    contorno se c'è `casing`; `onlyZoomedOut` → nessuno stile;
  - da lontano: colore, spessore e `zIndex` di `zoomedOutStyle`, senza tratteggio; con
    `dashColors` (più di uno) un tratto per colore con `lineDash [L, (n−1)L]`,
    `lineDashOffset (n−i)·L`, `lineCap: 'butt'`.
- [x] Il Vue costruisce un `Style` per elemento (con `lineDashOffset` e `lineCap`) e restituisce
  l'oggetto singolo quando è uno solo, come prima.
- [x] Verifica: `npm test` — invarianza senza proprietà nuove, soglia, contorno, segmenti,
  `onlyZoomedOut`, input malformati.

### Task 2: tooltip con tutte le linee — `stackedTooltip()`

**File:** `src/Nova/Fields/FeatureCollectionMap/src/FeatureCollectionMap.php`,
`resources/js/tooltip/utils.mjs` (+ test), `components/FeatureCollectionMap.vue`,
`components/DetailField.vue`, `src/Nova/UgcTrack.php`.

- [x] Opzione PHP `stackedTooltip(bool $enabled = true)`, meta `stackedTooltip`, default spenta.
- [x] Prop Vue `stackedTooltip`: il testo raccoglie `getFeaturesAtPixel` con
  `stackedTooltipText()`; cursore, link e profilo restano legati alla prima feature.
- [x] `stackedTooltipText()`: una riga per feature (`tooltip` o `name`), senza doppioni, prima le
  feature con `tooltipOrder` in ordine crescente.
- [x] CSS del tooltip `white-space: pre`, per andare a capo sui `\n`.
- [x] `Nova\UgcTrack` accende `->stackedTooltip()`.
- [x] Verifica: `npm test`; Pest «il campo di default non ha stackedTooltip, la mappa della
  UgcTrack sì».

### Task 3: pezzi di tappa toccati — `ContextTrackBandsService`

**File:** `src/Services/Models/ContextTrackBandsService.php`,
`tests/Feature/ContextTrackBandsServiceTest.php`.

- [x] `bands(array $camminoByEcTrack, int $maxDistanceMeters, ?int $nearUgcTrackId = null)`:
  - in una transazione (`SET LOCAL jit = off`) taglia le tappe in pezzi di al più
    `max_distance_meters` (`ST_Segmentize` + `ST_DumpSegments`) in una tabella temporanea con
    indice GiST;
  - con `$nearUgcTrackId` fa lo stesso con la traccia e tiene solo i pezzi di tappa entro la
    tolleranza da un pezzo della traccia;
  - per ogni pezzo i cammini entro la tolleranza (lo stesso cammino una volta sola), `rank`
    (posizione del cammino della tappa) e `n`; unisce i pezzi per tappa, cammini e posizione;
  - orienta ogni pezzo da ovest a est (da sud a nord se più verticale);
  - cache 30 giorni con chiave da tappe, cammini, tolleranza, `updated_at` di tappe e traccia;
    `null` e log se il calcolo fallisce, nulla in cache.
- [x] Verifica: `vendor/bin/pest tests/Feature/ContextTrackBandsServiceTest.php` — posizioni,
  stesso cammino, ordine per chiave, orientamento, filtro vicino alla traccia, cache, errore.

### Task 4: mappa della UGC track — `UgcTrack::getFeatureCollectionMap()`

**File:** `src/Models/UgcTrack.php`, `src/Nova/UgcTrack.php`,
`tests/Feature/UgcTrackLayerContextMapTest.php`, `tests/Feature/Nova/UgcTrackCleanupNovaTest.php`.

- [x] Costanti: `TRACK_COLOR`, `TRACK_WIDTH` 1,5, `TRACK_CASING_WIDTH` 2,5,
  `TRACK_CASING_COLOR`, `CONTEXT_STAGE_WIDTH` 5, `CONTEXT_THIN_WIDTH` 1,5,
  `CONTEXT_TOUCHED_WIDTH` 7, `ZOOMED_OUT_TOLERANCE_PIXELS` 5, `ZOOMED_OUT_DASH_LENGTH` 8; tolta
  `RECONSTRUCTED_SEGMENT_DASH`.
- [x] Tratti ricostruiti continui; voce di legenda `dash => false`, colore della traccia da
  `TRACK_COLOR`.
- [x] Corrispondenza: `computedMatchingRun()` (solo stato `computed`), `matchingMaxDistance()`
  (tolleranza del calcolo, altrimenti quella dell'App), `traversedEcTrackIds()`.
- [x] `contextRows()`: con corrispondenza solo le tappe toccate; usato da
  `layerContextFeatures()`, `contextLegendItems()` e `touchedPortionFeatures()`.
- [x] `layerContextFeatures(bool $thin = false)`: tappe intere, larghe 1,5px se i pezzi toccati si
  disegnano a parte, altrimenti 5px; niente `lineLabel`.
- [x] `zoomedOutMinResolution()`: `max_distance_meters / 5 / cos(lat)` sul centroide.
- [x] `touchedPortionFeatures()`: per ogni pezzo toccato una feature a 7px nel colore del cammino;
  dove `n ≥ 2` e `rank = 0`, una feature `onlyZoomedOut` con `dashColors` (colori opachi),
  `zIndex` −0,5, tooltip «tappa + altri cammini». `null` se il servizio fallisce.
- [x] `getFeatureCollectionMap()`: con contesto, traccia e tratti ricostruiti a 1,5px con
  `casing`, `tooltipOrder` 0 e 1, tooltip della traccia «Traccia registrata».
- [x] Verifica: Pest sui due file — tappe sottili e pezzi toccati, segmenti da lontano, tooltip,
  solo tappe toccate con e senza corrispondenza, fallimento del calcolo, soglia per latitudine,
  tratti ricostruiti continui.

### Task 5: `dist`, README e verifica in Nova

- [x] `npm run prod` nella cartella del campo; il diff del `dist` tocca solo `field.js` e
  `mix-manifest.json`.
- [x] README del campo: `stackedTooltip()`, `casing`, `zoomedOutStyle`, `onlyZoomedOut`,
  `tooltipOrder`; legenda dell'esempio con `dash => false`.
- [x] Verifica a occhio su localhost sulla UGC 321, approvata dal dev.

### Task 6: chiusura

- [x] Suite: tutti i file di test del package su UGC track, `ContextTrackBandsService` e
  `FeatureCollectionMap` che usano il `TestCase` del package (26 file, 211 test); `npm test`.
- [x] PHPStan di camminiditalia (controllo CI): 0 errori.
- [ ] Commit in wm-package e PR verso `develop`.
- [ ] Dopo il merge: bump del gitlink in camminiditalia, riga in `CLAUDE.md`, commit e PR.
