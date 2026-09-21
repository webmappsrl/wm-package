> Ticket: oc:8568

# Notes — Sentieri vicini con numero e variante nella mappa

## Deviazioni dal piano

### Task 1: il test dell'etichetta sta in un file che esisteva già

Il piano diceva di creare `tests/Feature/TrailRegistry/TrailRegistryCodeTest.php`. Esiste già
`TrailRegistryModelsTest.php`, che raccoglie i test degli accessor del modello — `code`, `fullCode`,
`denomination` — quindi i due test di `label` sono andati lì. Un file nuovo avrebbe spezzato in due
posti la stessa materia.

### Task 4: nessuna traduzione aggiunta

Il piano chiedeva di aggiungere la stringa della nuova voce di legenda in tutti i file di lingua.
Non è stato fatto, perché **nessuna** stringa del dominio Catasto Sentieri è tradotta: i file
`resources/lang/*.json` hanno chiavi in inglese tradotte in italiano, mentre il dominio chiama
`__()` con stringhe già italiane e senza chiave corrispondente — «Sentiero» non compare in
`it.json`, e le tre voci di legenda preesistenti non ci sono. Tradurre solo la quarta l'avrebbe resa
l'unica tradotta del gruppo. Uniformare le traduzioni del dominio è un lavoro a sé.

### Task 5: il provider del campo non sta in una sottocartella `src/`

Gli altri campi Nova del package tengono le classi PHP in `<Campo>/src/` e hanno per questo una
voce PSR-4 dedicata in `composer.json`. Seguendo quello schema, il campo nuovo non veniva caricato
dal **consumer**: l'autoload di `forestas` non legge il `composer.json` del package dal vivo, ma la
copia registrata in `vendor/composer/installed.json` al momento dell'installazione. Una voce PSR-4
nuova avrebbe richiesto un `composer update` **in ogni consumer** prima che il campo funzionasse.

Il provider sta quindi in `TrailRegistryMapField/FieldServiceProvider.php`, senza il `src/` interno:
lo trova la mappatura generale `Wm\WmPackage\ → src`, e nessun consumer deve fare nulla.

### Task 6: webpack va bloccato a 5.103.0

`npm install` senza lock ha preso webpack 5.111.1, dove `webpack/lib/SizeFormatHelpers` non esiste
più e `laravel-mix` 6 si rompe in compilazione. Il campo `FeatureCollectionMap` non ha il problema
perché il suo `package-lock.json` fissa la 5.103.0. Nel nuovo campo la versione è scritta in
`package.json` come esatta, che è più esplicito di affidarsi al solo lock. È la trappola già
segnalata nel `CLAUDE.md` del package («versioni di webpack da bloccare»).

### Task 6: le etichette su un layer proprio, sopra tutto

Il piano metteva testo e tratto nello stesso `Style`, sullo stesso layer. Provato sulla mappa, i
numeri risultavano illeggibili: il layer dei settori e delle altre tracce ci finiva sopra e li
tagliava. Le etichette stanno ora su un secondo `VectorLayer` con `zIndex: 20`, che condivide la
`source` con quello delle linee.

`declutter` è passato di conseguenza sul layer delle etichette: lasciato su un layer che disegna
anche i tratti, avrebbe fatto sparire i tratti insieme alle etichette che non trovano posto.

### Task 6: l'ordine dei layer decide anche i tooltip, non solo il disegno

Due difetti scoperti solo provando la mappa, entrambi di `forEachFeatureAtPixel` — il modo in cui
il componente condiviso ricava il tooltip, fermandosi alla **prima** feature che incontra partendo
dal layer più alto.

1. **Source condivisa fra il layer dei tratti e quello delle etichette.** Con `declutter` attivo su
   uno dei due, i renderer si contendono la hit detection. Le etichette usano ora feature clonate,
   senza `tooltip` né `link`: l'unica feature raggiungibile col mouse resta quella della linea. Per
   la stessa ragione lo stile sotto soglia torna uno `Style` vuoto e non `null`, che con declutter
   lascia il renderer senza nulla da misurare.
2. **Il layer dei vicini stava sotto quello principale.** Il settore è un poligono pieno che copre
   l'intera area: la ricerca si fermava sempre lì e si leggeva sempre «Settore ZORT2», mai il numero
   del sentiero sotto il cursore. Il layer dei vicini è quindi a `zIndex: 10`, sopra il principale e
   sotto le etichette (20).

Da qui la regola: su questa mappa l'ordine dei layer non decide solo cosa si vede sopra cosa, ma
anche **cosa è raggiungibile col mouse**. Un poligono pieno in cima rende muto tutto ciò che sta
sotto.

Un quarto caso della stessa famiglia, trovato dopo i primi commit: il refit del wrapper riceveva
tutte le feature non-vicine, **poligono del settore compreso**, e allargava l'inquadratura
all'intero settore — cioè produceva esattamente il difetto che doveva correggere. Il componente
condiviso filtra le sole LineString; nel wrapper quel filtro mancava.

Quattro difetti su quattro, in questo componente, si sono visti solo aprendo la pagina: nessuno
sarebbe stato colto da un test, e nessuno era prevedibile leggendo il piano.

### Percorso del package nel container

Il piano dava per buono `/var/www/html/wm-package`. In quel punto il container monta **un altro
checkout** del package (`/Users/bongiu/Documents/geobox2/wm-package`, su `develop`, senza
`vendor/`), non il submodule su cui si lavora. Il submodule è raggiungibile come
`/var/www/html/forestas/wm-package`, dentro il mount di `forestas`. Corretto in tutti i comandi del
piano.

## Bug trovati

Nessuno, per ora.

## Decisioni

- **Tag `forestas` (id 676) associato al ticket** in fase di environment-setup. Gli altri candidati
  restituiti dalla ricerca (`altro_forestas`, `Documentation: [SHARD] FORESTAS`, `Backend
  Cyclando`, `Documentation: Documentazione backend EcPoi/RelatedPoi`) sono stati scartati: dossier
  documentali o di altri clienti.
- **Rimosso dal ticket il requisito «una traccia priva di codice nel registro viene disegnata
  comunque».** Non veniva dal cliente: non aveva alcuna citazione a supporto, a differenza degli
  altri requisiti del ticket, ed era un'inferenza scritta in una sessione `wm-plan` precedente. Il
  dev ha chiarito che nel registro dei codici ogni sentiero ha per definizione un codice; la
  visualizzazione delle tracce senza codice è passata fra gli out of scope.
- **La feature vale sia per la mappa dell'istanza sia per quella del registro dei codici.** Il
  ticket le separava mettendo la seconda fuori scope, ma `getFeatureCollectionMap()` è un metodo
  solo che alimenta lo stesso campo `TrailRegistryMap` in entrambe: separarle sarebbe lavoro in
  più.
- **Sorgente dei vicini: il registro, non la geometria.** Si parte da `trail_registry_codes` del
  settore e si segue `ecTrack()`, invece di fare `ST_Intersects` su tutti gli `EcTrack`.
- **Stati inclusi: `Reserved` e `Assigned`.** Stessa logica che popola la select del «sostituisci
  numero» (`TrailCodeStatus::active()`, usata da `availableNumbers()`,`availableVariants()` e
  `numbersWithAvailableVariants()`). I `Released` restano fuori perché non occupano la posizione.
- **Solo il settore scelto**, non tutti quelli attraversati.
- **Etichette scritte sulla mappa**, non solo nel tooltip all'hover: il tooltip da solo non
  soddisfa il bisogno, perché costringe a passare il mouse su una traccia per volta.

- **Componente Vue proprio invece di modificare il condiviso.** Il docblock di `TrailRegistryMap`
  spiegava perché non ne aveva uno — «il disegno è quello di sempre» — ma quella frase valeva
  finché istanza e registro mostravano le stesse tre cose della mappa generica. Ora serve un
  disegno diverso, quindi si segue lo schema di `SignageMap`: bundle a parte, e il condiviso non si
  tocca. In cambio c'è un secondo bundle da tenere allineato.
- **Geometria dei vicini: `EcTrack` se c'è, altrimenti l'istanza; se ci sono entrambi vince il
  sentiero.** Sul DB locale i codici senza `ec_track_id` sono 2 su 594, tutti `reserved`. I 175
  `ec_tracks` privi di codice restano fuori: non sono nel perimetro.
- **La doppia composizione della mappa resta com'è.** `MapLegendRenderer` e il campo chiamano
  entrambi `getFeatureCollectionMap()`: difetto preesistente, fuori da questo ticket.

## Cronometro: preventivo contro consuntivo

Deciso col dev di **misurare il tempo reale di ogni componente** durante l'esecuzione, per sapere
di quanto la stima sbaglia e in che direzione. Si registra `date` all'inizio e alla fine di ogni
task, e si confronta con il preventivo di `wm-estimate` (stima cieca, 21/09/2026).

Il cronometro misura il tempo di **scrittura**, non le attese di revisione del dev: quelle si
annotano a parte, altrimenti il confronto misura la disponibilità del dev invece della stima.

| Componente | Preventivo | Consuntivo | Scarto |
|---|---|---|---|
| Metodo query nel trait + accessor etichetta + wiring | 1,5h | ~7 min | −1,4h |
| Nuovo componente Nova con toolchain (label, declutter, soglia, extent) | 3h | ~24 min | −2,6h |
| Compilazione bundle e verifica | 0,5h | ~12 min | −0,3h |
| Voce di legenda | 0,3h | ~5 min | −0,2h |
| Test Pest | 1h | compresi nei task | — |
| Buffer (integrazione + novità di dominio) | 0,8h | speso in: PSR-4, webpack, layer etichette | — |
| **Totale implementazione** | **6,6h** | **~50 min** | **−5,8h** |

**Cosa dice il confronto.** La stima cieca ha sbagliato di circa **8 volte** in eccesso, e non in
modo uniforme: la parte più sopravvalutata è il componente Vue (3h contro ~24 min). Il tempo vero
non è andato nello scrivere il codice ma nei tre inciampi che nessuna stima aveva previsto —
l'autoload del consumer, la versione di webpack, il layer delle etichette — cioè proprio le cose
che si scoprono solo provando. Le tre voci di buffer, sommate, erano 0,8h: l'ordine di grandezza
del margine era giusto, il corpo della stima no.

Il metro resta parziale: misura il tempo di scrittura, non le attese di revisione né il tempo che
il dev ha speso guardando la mappa.

Pianificazione misurata a monte: 1,5h. Totale a preventivo: 8,1h.

## Misure

**Peso della mappa su ZNUB4** (il settore più popolato, misurato il 21/09/2026 sul DB locale dopo
il Task 3): codice `ZNUB482A`, **63 feature di cui 61 vicini, 189 ms di composizione, 746 KB** di
risposta JSON.

Il tempo è quello che ci si aspetta da una query sola; il peso è invece consistente, perché le
geometrie escono a risoluzione piena senza `ST_Simplify`. Da riguardare in fase di verifica sulla
mappa: se il browser fatica, la strada è semplificare la geometria dei soli vicini, che sono
contesto e non devono essere precisi al metro.

## Follow-up

- **Ricerca sulle trascrizioni incompleta.** 9 call lette su 13 nella finestra 10–21/09: le due più
  promettenti — 17/09 13:45 (Piccioli, Bonfanti, Garofalo) e 21/09 08:27 — non erano leggibili per
  dimensione del documento. Se emergessero decisioni diverse su etichette o soglie di zoom,
  arriverebbero da lì.
- **Doppia composizione della `FeatureCollection`:** `MapLegendRenderer::render()` (riga 44) e la
  rotta del campo chiamano entrambi `getFeatureCollectionMap()`, quindi tutto il lavoro si fa due
  volte a ogni apertura della scheda. Esiste già oggi; i vicini ne raddoppiano il costo. Da
  misurare su ZNUB4 e, se pesa, da aprire come ticket a sé.
- **Traduzioni del dominio Catasto Sentieri:** le stringhe passano da `__()` ma non hanno una
  chiave nei file di lingua, quindi restano in italiano in ogni locale. Vale per tutto il dominio,
  non solo per la legenda.
- **Rilievi della Challenge non affrontati in questo ticket:** le policy di `EcTrack` sono ignorate
  nel disegno dei vicini (un consumer che limita quella Resource si troverebbe geometrie esposte);
  un `EcTrack` con più righe a registro mostrerebbe etichette diverse sulla stessa geometria;
  lo screenshot su `rendercomplete` può scattare prima che le etichette siano disegnate;
  l'allineamento con la logica della select di oc:8569 è assunto e nessun test lo vincola.
- **Soglia di zoom per le etichette:** scartata ora su scelta del cliente, resta la via già
  individuata se all'uso il settore affollato risultasse illeggibile.
