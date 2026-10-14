> Ticket: oc:8776

# Mappa della UGC track in Nova: tracce e tappe sovrapposte leggibili

## Cosa cambia

Nella mappa del dettaglio di una UGC track in Nova (campo `FeatureCollectionMap`, tappe di
contesto introdotte con oc:8747), quando sulla mappa ci sono tappe di contesto:

- la **traccia registrata** ha sempre la stessa misura, a ogni zoom: una riga blu sottile con un
  contorno bianco, sopra a tutto. Anche i **tratti ricostruiti** (oc:8719) sono così, in arancio
  e continui;
- se la corrispondenza UGC ↔ EC track di oc:8773 è calcolata, compaiono **solo le tappe toccate
  dalla traccia**; altrimenti tutte quelle della zona, come in oc:8747;
- le tappe si disegnano **intere e sottili**, e **piene solo nei pezzi che la traccia tocca**:
  si legge quale cammino la traccia sta seguendo, e una tappa incrociata di passaggio non si
  colora per tutti i suoi chilometri;
- **da lontano**, sui pezzi toccati dove passano più cammini, la linea si divide in **segmenti
  alternati** nei colori di quei cammini;
- i **nomi delle tappe** non sono più scritti lungo le linee: stanno nel tooltip;
- il **tooltip** elenca tutte le linee sotto il mouse, nell'ordine «Traccia registrata», tratti
  ricostruiti, tappe.

Senza tappe di contesto la mappa resta com'era.

## Perché

Con traccia e tappe entrambe a 3px e la traccia disegnata sopra, una UGC che segue una tappa la
nasconde del tutto: nel dettaglio non si capisce quale tappa sta percorrendo. Le tappe di cammini
diversi sullo stesso sentiero si coprono a vicenda, e resta visibile solo l'ultimo cammino
disegnato. Inoltre le tappe della zona, anche se non toccate, a zoom basso diventano macchie di
colore più vistose della traccia, e i nomi scritti lungo le linee coprono la mappa.

## Requisiti

### Traccia

- [ ] Con tappe di contesto, la traccia e i tratti ricostruiti sono larghi `TRACK_WIDTH` (1,5px)
  su un contorno bianco di `TRACK_CASING_WIDTH` (2,5px), a ogni zoom, con la proprietà `casing`
  del campo. Senza tappe di contesto restano come prima.
- [ ] I tratti ricostruiti sono continui sempre (niente `strokeDash`), con voce di legenda
  `dash => false`: la legenda è fissa e non può seguire lo zoom.
- [ ] La traccia resta una sola feature, con `slopeChart`, `id`, popup e inquadratura come prima.
- [ ] Nel tooltip la traccia si chiama «Traccia registrata» (`__('Recorded track')`), non con il
  nome dato dall'utente.

### Tappe

- [ ] Con la corrispondenza `computed` (`ugc_track_matching_runs`), mappa, legenda e tratti
  condivisi mostrano solo le EcTrack presenti in `ugc_track_ec_track_matches` per quella traccia;
  senza, tutte quelle della zona di oc:8747 (`UgcTrack::contextRows()`).
- [ ] Le tappe intere sono larghe `CONTEXT_THIN_WIDTH` (1,5px); i pezzi entro
  `max_distance_meters` dalla traccia sono feature in più, larghe `CONTEXT_TOUCHED_WIDTH` (7px),
  nel colore del cammino, a ogni zoom.
- [ ] «Pezzo toccato» si calcola in PostGIS (`ContextTrackBandsService`): tappe e traccia tagliate
  in pezzi di al più `max_distance_meters`, tenuti i pezzi di tappa entro la tolleranza da un pezzo
  della traccia. Stessa tolleranza della corrispondenza di oc:8773.
- [ ] Niente `lineLabel` sulle tappe: il nome sta nel tooltip «Cammino — Tappa».
- [ ] Se il calcolo dei pezzi fallisce, la mappa resta visibile e le tappe si disegnano intere a
  `CONTEXT_STAGE_WIDTH` (5px).

### Da lontano: segmenti alternati

- [ ] La mappa è «vista da lontano» da una `resolution` ricavata dalla tolleranza: quando
  `max_distance_meters` sta in `ZOOMED_OUT_TOLERANCE_PIXELS` (5px), corretto per la latitudine
  (un'unità EPSG:3857 vale cos(lat) metri). Il cambio avviene nel browser, senza ricaricare.
- [ ] Sui pezzi toccati dove passano 2 o più cammini, sopra il pezzo del primo cammino (in ordine
  di colore) si posa una feature `onlyZoomedOut` con `zoomedOutStyle.dashColors`: segmenti di
  `ZOOMED_OUT_DASH_LENGTH` (8px), estremità piatte, un colore per cammino, tutti sulla stessa
  geometria (non possono sfasarsi). Tooltip: la tappa e gli altri cammini.
- [ ] I pezzi escono orientati da ovest a est (da sud a nord se più verticali).

### Tooltip

- [ ] Nuova opzione del campo `stackedTooltip()`, **spenta di default** e accesa sulla mappa della
  UGC track: tutte le linee sotto il mouse, una per riga, senza doppioni.
- [ ] Nuova proprietà facoltativa `tooltipOrder`: prima le feature che ce l'hanno, in ordine
  crescente (traccia 0, tratti ricostruiti 1), poi le altre come arrivano.

### Prestazioni

- [ ] Il risultato di `ContextTrackBandsService` è in cache 30 giorni, con chiave fatta da tappe e
  loro cammini, tolleranza, `updated_at` delle tappe e della traccia: un cambio dei dati cambia la
  chiave da solo. Sulla UGC 321: ~1,5 s alla prima apertura, poi pochi ms.

### Campo condiviso

- [ ] Nessun cambiamento per gli altri usi del campo (TaxonomyWhere, FeatureCollection, Layer,
  PointResourceTrait, MultiLinestringResourceTrait, TrailRegistry): `casing`, `zoomedOutStyle`,
  `onlyZoomedOut`, `tooltipOrder` e `stackedTooltip()` sono facoltativi, e una feature senza di
  essi ha esattamente lo stile di prima.

### Verifica

- [ ] Test PHP: `tests/Feature/UgcTrackLayerContextMapTest.php`,
  `tests/Feature/ContextTrackBandsServiceTest.php`, `tests/Feature/Nova/UgcTrackCleanupNovaTest.php`.
- [ ] Test JS: `line-style/utils.test.mjs`, `tooltip/utils.test.mjs`.
- [ ] Verifica a occhio in Nova sulla UGC track 321 (126 km, 7 cammini).
- [ ] `dist` del campo ricompilato con `npm run prod` dentro `src/Nova/Fields/FeatureCollectionMap/`.

## Rischi

- **Costo del calcolo dei pezzi.** ~1,5 s alla prima apertura di una UGC lunga, poi in cache.
  Se diventa un problema, si sposta il calcolo nel job di oc:8773.
- **Corrispondenza vecchia.** Le tappe mostrate dipendono dalla corrispondenza di oc:8773: se una
  EcTrack cambia, fino al ricalcolo la lista può essere indietro. I pezzi toccati invece si
  ricalcolano da soli (la chiave di cache dipende da `updated_at`).
- **Peso della risposta.** Sulla 321 il GeoJSON è ~1,3 MB, quasi tutto tappe a piena risoluzione,
  come prima del ticket.
- **Campo condiviso.** `FeatureCollectionMap.vue` e il suo `dist` sono usati da altri shard: un
  revert vuol dire revert del sorgente e ricompilazione del `dist`. Mitigato dall'opzionalità
  delle nuove proprietà e dai test sullo stile invariato.
- **API pubblica.** Tolta la costante `UgcTrack::RECONSTRUCTED_SEGMENT_DASH`: in camminiditalia non
  è usata; gli altri consumer vanno controllati al bump.

## Out of scope

- Accendere o spegnere i singoli cammini sulla mappa.
- Mostrare il colore di più cammini quando una sola EcTrack appartiene a più layer (oggi 3 casi
  su 1292): prende il colore del layer con id più basso.
- Più di 8 cammini sulla stessa mappa: la palette ricomincia.
- Semplificare la geometria delle tappe per alleggerire la risposta.
- Il testo «Caricamento mappa...» scritto in italiano nel Vue.

## Moduli toccati

Tutti in **wm-package**:

- `src/Models/UgcTrack.php` — stile della traccia, tappe mostrate, tappe sottili e pezzi toccati,
  segmenti da lontano, soglia di zoom, tooltip
- `src/Services/Models/ContextTrackBandsService.php` — nuovo: pezzi di tappa toccati e cammini
  che passano in ciascuno
- `src/Nova/UgcTrack.php` — legenda dei tratti ricostruiti, `stackedTooltip()`
- `src/Nova/Fields/FeatureCollectionMap/src/FeatureCollectionMap.php` — opzione `stackedTooltip()`
- `src/Nova/Fields/FeatureCollectionMap/resources/js/line-style/utils.mjs` — `lineStyles()`
- `src/Nova/Fields/FeatureCollectionMap/resources/js/tooltip/utils.mjs` — nuovo: `stackedTooltipText()`
- `src/Nova/Fields/FeatureCollectionMap/resources/js/components/{FeatureCollectionMap,DetailField}.vue`
- `src/Nova/Fields/FeatureCollectionMap/dist/`, `README.md`
- test elencati sopra

In **camminiditalia**: solo il bump del gitlink `wm-package` e la riga in `CLAUDE.md`.
