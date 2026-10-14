> Ticket: oc:8776

# Notes — Mappa della UGC track in Nova: tracce e tappe sovrapposte leggibili

## Come si è arrivati alla versione definitiva

Il piano iniziale è stato superato durante le prove su localhost; `overview.md` e `plan.md` sono
stati riscritti sulla versione definitiva prima del commit. Qui la storia dei tentativi.

### Dal tratteggio alla versione definitiva

**Versione definitiva** (approvata dal dev dopo una decina di prove su localhost sulla UGC 321):

- la **traccia** è sempre 1,5px di blu su un contorno bianco di 2,5px, sopra a tutto, quando sulla
  mappa ci sono tappe di contesto; nel tooltip si chiama «Traccia registrata» (non il nome dato
  dall'utente) e viene per prima, poi i tratti ricostruiti, poi le tappe (`tooltipOrder`);
- con la corrispondenza `computed` di oc:8773 la mappa mostra **solo le tappe toccate dalla
  traccia**; senza, tutte quelle della zona come in oc:8747;
- le tappe si disegnano **intere e sottili** (1,5px) e **piene a 7px solo nei pezzi entro 50 m
  dalla traccia** (`ContextTrackBandsService` con la UgcTrack), a ogni zoom;
- **da lontano**, sui pezzi toccati dove passano più cammini, un pezzo della tappa del primo
  cammino si colora a **segmenti alternati** di 8px nei colori di tutti (`dashColors`);
- **niente nomi delle tappe scritti lungo le linee** (`lineLabel` tolto): il nome sta nel tooltip;
- se il calcolo dei pezzi fallisce, le tappe restano intere a 5px.

Dei Task 3 e 4 del piano cadono i riempimenti e tutto il calcolo dei tratti da sola. Le soluzioni
provate e scartate sono descritte sotto, in ordine.

Dopo la prima prova su localhost il dev ha chiesto di sostituire il tratteggio delle tappe con
**fasce concentriche**: al centro la traccia blu, intorno la fascia del primo cammino, poi del
secondo, e così via; la larghezza della linea dipende da quanti cammini passano in quel punto.

Scelta fra due modi (decisa dal dev):

- **larghezza per tratto (scelta):** il server calcola dove le tappe si sovrappongono fra loro,
  con la stessa tolleranza della corrispondenza (`max_distance_meters`), e manda le tappe spezzate
  in tratti con «quanti cammini qui» (n) e «posizione di questo cammino» (rank, 0 = il più
  interno, in ordine di colore cioè di id del layer);
- larghezza fissa per cammino, scartata: con i 7 cammini della UGC 321 il più esterno sarebbe
  largo circa 46px anche dove passa da solo.

Larghezze, riviste dopo la seconda prova su localhost: le prime (fascia di 3px per lato per ogni
cammino, quindi 10px con un cammino e 22px con tre) a zoom di apertura coprivano paesi e strade.
Il dev ha chiesto di seguire gli standard e di non far crescere la linea con il numero di
cammini: la linea è larga al massimo **6px** (linea di percorso dei navigatori; i sentieri sulle
mappe escursionistiche stanno sui 3–4px), la traccia al centro occupa 3px (2px di blu + 0,5px di
contorno per lato) e i 3px restanti si dividono per gli n cammini del tratto. La fascia di
posizione r è larga `3 + 3 × (r + 1) / n`; la più esterna è sempre 6px. Con 2 o più cammini le
fasce scendono sotto 1px per lato: lì è il tooltip a dire con certezza chi passa (accettato dal
dev). La soglia di zoom segue la nuova larghezza: tolleranza in 6px invece che in 10px.
Scartata una scala delle larghezze in base allo zoom: era un fraintendimento della richiesta.
Da lontano le feature di contesto originali si nascondono (`zoomedOutStyle.hidden`) e restano
le fasce, che portano lo stesso tooltip «cammino — tappa». Se il calcolo fallisce, le tappe
restano disegnate come da vicino.

Il tratteggio (`dashOverlay`) sparisce da PHP e JS: senza usi sarebbe codice morto.

**Traccia a misura fissa (terzo giro).** Il dev ha chiesto che la traccia registrata abbia sempre
la stessa misura: una linea che si stringe sopra le tappe e si allarga dove va da sola si legge
come due cose diverse. La traccia (e i tratti ricostruiti) è quindi 2px su un contorno bianco di
3px, a ogni zoom, con la proprietà `casing` del campo, quando sulla mappa ci sono tappe di
contesto; senza contesto resta com'era. Conseguenze:

- cadono i riempimenti (`onlyZoomedOut` sulle parti di traccia da sola) e
  `UgcTrackStandaloneSegmentsService` con il suo test: esistevano solo per allargare la traccia
  dove va da sola. Era la query più costosa del ticket (~0,7 s) e legava il disegno della traccia
  alla corrispondenza di oc:8773;
- `UgcTrackMatchingService::segmentCtes()` torna privata, come prima del ticket;
- il contorno non è più dentro `zoomedOutStyle` ma una proprietà della linea valida a ogni zoom;
  `zoomedOutStyle` resta per le fasce (larghezza, colore, gruppo) e per nascondere le tappe da
  lontano (`hidden`). `onlyZoomedOut` resta per le fasce.

Prototipo sulla UGC 321 (29 tappe di contesto, DB di sviluppo, transazione annullata): pezzi da
al più 50 m con `ST_Segmentize` + `ST_DumpSegments`, indice GiST su una tabella temporanea,
`ST_DWithin` fra pezzi di cammini diversi. 34.645 pezzi, ~1,6 s in tutto, 72 tratti risultanti;
pezzi con 1/2/3/4 cammini: 25.537 / 8.167 / 923 / 18. Il primo tentativo con
`ST_LineSubstring` per pezzo impiegava 21 s (rilegge la linea per ogni pezzo).

**Linee affiancate (quarto giro).** Con le fasce in 6px, da 2 cammini in su ogni fascia scendeva
sotto 1px e il colore non si leggeva. Guardando come fanno le mappe dei sentieri e dei trasporti
(Waymarked Trails, le linee di autobus e metro), il dev ha scelto le linee affiancate: ogni
cammino è una linea da 2px (`CONTEXT_SIDE_WIDTH`), spostata di lato attorno alla traccia,
posizione 0 a sinistra del verso della linea, 1 a destra, 2 a sinistra più in fuori, e così via
(`UgcTrack::contextSideOffset()`: ±2,5px, ±4,5px…). La larghezza totale cresce col numero di
cammini, ma ogni colore resta una linea leggibile.

- Lo spostamento si fa nel browser, in pixel (`zoomedOutStyle.offset` × `resolution`, con
  `line-offset/utils.mjs`), non in PostGIS con `ST_OffsetCurve`: in metri cambierebbe sullo
  schermo a ogni zoom. Il risultato è memorizzato per feature e distanza, e si ricalcola solo
  quando cambia lo zoom.
- `ContextTrackBandsService` orienta ogni tratto da ovest a est (da sud a nord se è più verticale):
  due tappe disegnate in versi opposti finirebbero altrimenti dallo stesso lato. La chiave di
  cache porta `v2`, perché il formato del risultato è cambiato.
- La soglia di zoom resta legata alla tolleranza: `ZOOMED_OUT_TOLERANCE_PIXELS` = 6.

**Segmenti alternati (quinto giro).** Le linee affiancate non sono piaciute al dev. Scelta finale:
sui tratti condivisi una linea sola, divisa in segmenti della stessa lunghezza nei colori dei
cammini che passano lì, alternati. È il tratteggio della prima idea senza i suoi due difetti:

- si alternano solo i cammini **di quel tratto** (`ContextTrackBandsService` restituisce ora anche
  `cammini`), non tutti quelli della mappa, quindi i colori stanno alla pari;
- il tratto si disegna **una volta sola**, dalla tappa del cammino in posizione 0, con un
  `Stroke` per colore sulla stessa geometria (`zoomedOutStyle.dashColors`, `dashLength`): i
  segmenti non possono sfasarsi. Le tappe degli altri cammini su quel tratto non si disegnano; il
  tooltip elenca la tappa e gli altri cammini.

Tolti lo spostamento laterale (`line-offset/utils.mjs`, `zoomedOutStyle.offset`,
`contextSideOffset()`, `CONTEXT_SIDE_WIDTH`). Chiave di cache delle fasce a `v3`.

**Tappe intere e tratti condivisi sopra (sesto giro, proposta del dev).** Nei giri precedenti le
tappe da lontano erano nascoste e ridisegnate a pezzi; il dev ha preferito tenerle intere, come
prima, e agire solo sui tratti condivisi: un pezzo della tappa del primo cammino (stessa
geometria, stessa larghezza, `zIndex` −0,5 fra tappe e traccia) colorato a segmenti, cioè la tappa
stessa che su quel tratto cambia colore. Le altre tappe del tratto restano sotto, nella loro
posizione vera. Il calcolo delle sovrapposizioni resta, ma si usa solo per i tratti con 2 o più
cammini. Larghezze: tappe 5px, traccia 3px con il contorno, quindi 1px di tappa per lato accanto
alla traccia; dopo la prova il dev ha chiesto una traccia più sottile: 1,5px su 2,5px, 1,25px di
tappa per lato. Tolta l'opzione `hidden` di `zoomedOutStyle`, non più usata.

Dopo la prova sulla 321 (settimo giro): le tappe **vicine ma non toccate** dalla traccia
(varianti e altri cammini della zona, tortuosi) a 5px diventavano macchie di colore più vistose
della traccia. Provato prima a smorzarle (3px al 50%), poi, su proposta del dev, tolte: con la
corrispondenza `computed` di oc:8773 la mappa mostra **solo le tappe toccate dalla traccia**,
intere (`UgcTrack::contextRows()`, usato da feature, legenda e tratti condivisi). Senza
corrispondenza restano tutte quelle della zona, come in oc:8747. Questo cambia il senso del
contesto di oc:8747 (da «percorsi della zona» a «percorsi toccati»).

Restavano piene, intere, anche le tappe toccate per un solo tratto da 400 m (sulla 321 la 264:
1 su 35), e facevano ancora macchia. **Ottavo giro (proposta del dev):** tappe intere sottili
(1,5px, meno della traccia) e piene solo nei pezzi che la traccia tocca: `ContextTrackBandsService`
riceve la UgcTrack, ne taglia la geometria in pezzi da 50 m in una seconda tabella temporanea con
indice, e tiene solo i pezzi di tappa entro la tolleranza da uno di essi. «Tocca» è quindi
calcolato pezzo per pezzo, più preciso dei tratti da 400 m di oc:8773. Pezzi toccati prima a 5px,
poi a 7px perché a 5px il colore ai lati della traccia non si leggeva. Il dev ha valutato di
togliere i segmenti alternati anche da lontano e ha deciso di tenerli.

**Ultime richieste:** tolti i nomi delle tappe scritti lungo le linee (coprivano la mappa); nel
tooltip la traccia è «Traccia registrata» e le righe sono ordinate (traccia, tratti ricostruiti,
tappe) con la nuova proprietà `tooltipOrder` del campo.

Provata e scartata nel sesto giro, prima della proposta del dev: far correre i colori dei
cammini sulla geometria della traccia dove la percorre, per centrare la traccia sulla fascia
colorata (lo scarto GPS–tappa la faceva sembrare decentrata). Scritto solo il test, poi tolto.

## Bug trovati

- Menu Nova di camminiditalia con le sezioni UGC, EC e Taxonomies vuote dopo un cambio di
  container: non è un bug del codice. Il `localStorage` di `localhost:8000` conteneva lo stato
  «chiusa» delle sezioni omonime di orchestrator, aperto prima sulla stessa porta; Nova lo legge
  anche per le sezioni non richiudibili (`Collapsable` mixin, chiave `md5(nome-path)`). Si
  risolve cancellando le chiavi `nova.navigation.*` dal browser.

- Una UGC perfettamente orizzontale (o verticale) ha bbox di altezza (o larghezza) zero, e
  `layerContextRows()` non trova alcuna tappa di contesto: la zona è il bbox allargato in
  proporzione, quindi resta degenere. Comportamento di oc:8747, non toccato; emerso solo con un
  dato di test, trascurabile sulle tracce reali.

## Decisioni

- Stima del ticket saltata su richiesta del dev (fase estimation).
- Tooltip: `white-space: nowrap` → `pre` nel CSS del campo, così il tooltip con più righe va a
  capo sui `\n` e quelli di una riga restano come prima.
- Tratti ricostruiti continui **sempre**, non solo da lontano: la legenda è fissa e non può
  seguire lo zoom (precisazione dell'overview, approvata col piano).
- Misura del servizio dei tratti da sola sulla 321 (da tinker): 1371 ms alla prima chiamata,
  24 pezzi; 2 ms dalla cache. Servizio poi tolto (traccia a misura fissa).

## Follow-up

- Il GeoJSON della mappa della UGC 321 pesa ~1,3 MB (con le fasce era ~2,2 MB): il grosso sono
  le tappe a piena risoluzione, come prima del ticket. Se diventa un problema, semplificarne la
  geometria (`ST_Simplify`) per la mappa.
- Il calcolo dei pezzi toccati costa ~1,5 s alla prima apertura di una UGC lunga (321), poi è in
  cache: la chiave cambia con le tappe, la traccia o la tolleranza.
- Un salvataggio dall'editor ha riscritto `UgcTrack.php` con una versione precedente e ha fatto
  perdere una modifica (ritrovata e riapplicata): con il file aperto nell'IDE, ricaricarlo dal
  disco prima di salvare.
