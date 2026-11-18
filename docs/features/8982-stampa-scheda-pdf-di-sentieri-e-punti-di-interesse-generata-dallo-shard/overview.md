> Ticket: oc:8982

# Stampa: scheda PDF di sentieri e punti di interesse generata dallo shard

Questo overview copre la parte del `wm-package`, che porta quasi tutto il codice. La
personalizzazione di Forestas e il servizio nel compose dello shard sono in
`forestas/docs/features/8982-…/overview.md`. Il sito WordPress non è toccato da questo ticket.

## Cosa cambia

Il package impara a produrre tre file stampabili, per qualunque shard:

1. **la scheda PDF di un `EcTrack` o di un `EcPoi`**, in una lingua: dati principali,
   descrizione, mappa del percorso, immagini;
2. **lo ZIP delle schede PDF** di più `EcTrack` selezionati;
3. **la mappa d'insieme in PDF** di più `EcTrack` selezionati, su una pagina sola.

I PDF si compongono in **LaTeX** e le mappe si disegnano dalle **tile vettoriali di
`https://tiles.maphub.it/`** (stile `style.json`), non dalle tile raster PNG. Compilazione LaTeX e
rendering delle mappe sono due operazioni di un **motore di rendering** (`map` e `pdf`). **Dove
gira il motore** (nell'immagine PHP dello shard o in un container separato) **è una domanda
aperta**: vedi la sezione «Domanda aperta» più sotto.

La grafica ha un **default nel package**, una scheda neutra che prende il colore primario dalla
configurazione del tema dell'app (`primary_color`). Ogni shard può **sostituirla**: i token del suo
design system (`tokens.json`) diventano in automatico un file di stile LaTeX (`.sty`), e
l'impaginazione si sostituisce fornendo una propria versione della view Blade che **produce il
sorgente LaTeX della scheda** (override delle view del package in
`resources/views/vendor/wm-package/`).

Il PDF della scheda nasce **su richiesta** e poi **resta aggiornato**:

- il link della scheda apre una rotta web con la lingua nell'URL; se il PDF c'è, redirect al file
  su `wmfe`; se manca, si accoda il Job e si mostra una **pagina d'attesa** che controlla lo stato e
  fa il redirect quando il file è pronto;
- quando l'elemento cambia, i PDF **già generati** si rigenerano nella catena dei
  `publicationJobs`, come il JSON di dettaglio;
- una **Action Nova** su `EcTrack` ed `EcPoi` genera le schede nelle lingue scelte fra quelle
  dell'app, di default solo l'italiano.

## Perché

Il nuovo sito di Sardegna Sentieri ha il pulsante «Stampa» visibile ma disattivato, e il capitolato
chiede la scheda stampabile e lo scarico multiplo (WEBSITE-18, WEBSITE-19, test minimo n. 8). Il
team ha deciso che il PDF si genera **lato server nel `wm-package`** e diventa quello di tutti gli
shard, anche dell'app, al posto della stampa dal browser ereditata da GeoHub (rotta web
`/track/pdf/{id}` di GeoHub, residuo nel package: rotta `api/ec/track/pdf/{id}` e link «Print PDF»
commentato in `src/Models/EcTrack.php`).

Le scelte e i loro motivi:

- **LaTeX** (scrum 17/11 e 18/11): resa tipografica di stampa. Vincolo di Giuseppe: la grafica
  deve restare personalizzabile per shard; se LaTeX non lo permettesse, si torna a valutare. Il
  design system di Forestas ha `tokens.json` leggibile da un programma, quindi la personalizzazione
  si ottiene generando lo stile LaTeX dai token, senza scriverlo a mano e senza modelli.
- **Su richiesta, salvato su `wmfe`, poi tenuto aggiornato** (scrum 18/11): «se è in AVS glielo
  torni, se non è in AVS lo calcoli, glielo torni e lo salvi»; «solo il primo lo pagherà». Alessio:
  una modifica al titolo deve finire nel PDF. Giuseppe: «voglio che si usi quel meccanismo», cioè
  quello del JSON.
- **Lingua nell'URL**: il pulsante del sito è un link (`<a href>`), che non può impostare header;
  `Accept-Language` porterebbe la lingua del browser, non quella della pagina.
- **Mappa vettoriale** (Alessio, scrum 18/11, e indicazione scritta del 18/11: «non usare i png»):
  le tile raster da 256 px non arrivano a 300 dpi con nomi leggibili.
- **LaTeX e mappe nello stesso posto**: in qualunque delle due soluzioni sotto, compilazione e
  rendering delle mappe stanno insieme. Separarli non porta un vantaggio concreto: uno shard che usa
  solo le mappe (come camminiditalia per la condivisione dei cammini) paga TeX Live solo in spazio
  su disco, non in CPU o RAM.

## Domanda aperta: dove gira il motore di rendering

Non è deciso. Le due strade, con quello che è stato verificato il 18/11:

**A) Nell'immagine PHP dello shard (`wm-phpfpm:8.4`).** TeX Live, `@maplibre/maplibre-gl-native`
e le librerie grafiche che richiede si aggiungono al Dockerfile dello shard; il Job lancia
`xelatex` e il renderer come processi.

- A favore: è la soluzione più semplice, senza servizi in più né chiamate HTTP. Ed è coerente con
  un precedente: `UpdateEcTrackGenerateElevationChartImage` usa già node dentro l'immagine PHP per
  il grafico altimetrico.
- Il contenimento della memoria si ottiene anche qui, con limiti di memoria e di tempo per processo
  (`prlimit`, `timeout`): se un rendering sfora muore solo quel processo.
- Contro: le dipendenze native vanno copiate nel Dockerfile **di ogni shard**. Oggi i tre shard
  costruiscono `wm-phpfpm:8.4` da tre Dockerfile copiati a mano, e uno è già rimasto indietro
  (maphub a `php:8.4.3-fpm`, gli altri a `8.4.27`). Se uno shard ha una versione diversa o dimentica
  una libreria, la stampa si rompe lì.
- Da verificare: `@maplibre/maplibre-gl-native` ha binari compilati per versioni precise di Node,
  e nell'immagine PHP Node arriva da `apt` (`nodejs npm`), non da una scelta nostra.

**B) In un container separato, uno per shard, costruito da un'immagine unica il cui Dockerfile sta
nel `wm-package` (`docker/render/`).** Il Job chiama il servizio via HTTP.

- A favore: le dipendenze sono versionate nel `wm-package`, nello stesso commit del codice che le
  usa, e Node è una versione scelta da noi. Gli shard costruiscono già le immagini da percorsi
  locali, quindi non serve un registro.
- In più, ma non decisivo: un secondo livello di protezione (il container non ha il `.env` con le
  credenziali) e un `mem_limit` sul container.
- Contro: un servizio in più nel compose di ogni shard che lo usa, un Dockerfile in più, una
  chiamata HTTP al posto di un comando locale.

**Motivi considerati e ridimensionati:**
- la memoria disponibile sulle produzioni (camminiditalia ha 2,3 GB liberi su 7,6): un server si
  può ampliare, e i limiti per processo valgono anche nella soluzione A;
- l'isolamento dei segreti dal testo importato da Drupal: Drupal sarà spento con la produzione, e il
  testo arriverà solo dagli editor in Nova. L'irrigidimento di LaTeX vale in entrambe le soluzioni.

Il motivo che resta a favore di B è uno solo: tenere le dipendenze in un punto unico, versionate
con il codice. Il codice del package chiama il motore attraverso un'interfaccia con le due
operazioni `map` e `pdf`, così la scelta tocca solo l'implementazione.

## Requisiti

### Motore di rendering

Valgono in entrambe le soluzioni della «Domanda aperta»; dove una voce dipende dalla scelta, lo
dice.

- [ ] Interfaccia nel package con due operazioni, usata da tutto il resto del codice:
  - `map`: riceve geometrie GeoJSON, dimensioni in mm, `pixelRatio` e lo stile; restituisce un PNG
    alla risoluzione di stampa (circa 300 dpi). Lo stile di default è
    `https://tiles.maphub.it/style.json`;
  - `pdf`: riceve il sorgente `.tex` e i file allegati (mappa, foto, font); restituisce il PDF o
    l'errore di compilazione.
- [ ] XeLaTeX (o LuaLaTeX) e `@maplibre/maplibre-gl-native`, con TeX Live **ridotto ai pacchetti
      usati dai template**, non lo schema completo: la produzione di camminiditalia ha 11 GB liberi
      su 38.
- [ ] Compilazione senza shell escape e con `openin_any=p` / `openout_any=p`.
- [ ] Limite di tempo e di memoria per ogni rendering, e un errore leggibile quando scatta (soluzione
      A: per processo; soluzione B: per richiesta e `mem_limit` sul container).
- [ ] Soluzione B: Dockerfile e server in `docker/render/`, nessun segreto nell'ambiente del
      container, nessuna porta pubblicata.
- [ ] Il motore si attiva dalla configurazione del package; spento, le funzioni di stampa non ci
      sono e il JSON di dettaglio non espone link.

### Generazione e salvataggio

- [ ] Struttura come in osm2cai2 (`GeneratesPdfTrait`, `PdfService`, `GeneratePdfJob`), portata nel
      package con il motore LaTeX: il modello dichiara view, percorso del file e lingue.
- [ ] Il Job raccoglie i dati, chiede la mappa al motore (`map`), scarica le foto da S3, compone il
      `.tex` dalla view Blade, chiede il PDF al motore (`pdf`) e lo salva con `StorageService` su `wmfe`, un file per
      lingua.
- [ ] I Job di stampa girano su una coda Horizon dedicata.
- [ ] Il testo libero (descrizione, nomi) passa da HTML a LaTeX con l'escape di tutti i caratteri
      speciali: tutte le 769 descrizioni di `EcTrack` di Forestas hanno HTML, 457 hanno caratteri
      speciali per LaTeX (conteggio locale del 17/11).
- [ ] Le immagini in formati che XeLaTeX non legge (per esempio WebP) si convertono prima della
      compilazione.
- [ ] Le etichette fisse del template vengono da `resources/lang/*.json` del package (chiave in
      inglese, voce in tutte e cinque le lingue `de`, `en`, `es`, `fr`, `it`); lo shard le può
      sovrascrivere. Per una lingua senza testo, i campi tradotti usano il fallback di
      `spatie/laravel-translatable`.

### Rotte e pagina d'attesa

- [ ] Rotta web della scheda di `EcTrack` e di `EcPoi`, con la lingua nell'URL (`?lang=`). PDF
      presente e aggiornato: `302` al file su `wmfe`. Assente: accoda il Job e mostra la pagina
      d'attesa.
- [ ] Rotta di stato, interrogata dalla pagina d'attesa, che dice se il file è pronto.
- [ ] La pagina d'attesa fa il redirect da sola quando il file è pronto, e mostra un messaggio
      leggibile se la generazione fallisce.
- [ ] Un PDF già in coda non si accoda una seconda volta se arrivano più richieste insieme.
- [ ] L'URL della scheda (della rotta, non del file) è nel JSON di dettaglio di `EcTrack` ed `EcPoi`.

### Aggiornamento

- [ ] Un Job nei `publicationJobs` di `EcTrack` (`src/Services/Models/EcTrackService.php:416`)
      rigenera i PDF **delle sole lingue già presenti**. Un elemento mai stampato non genera nulla.
- [ ] Per `EcPoi` la catena di oggi (`EcPoiService::updateDataChain`) parte solo quando cambia la
      geometria: serve un aggancio sul salvataggio che rigeneri i PDF già presenti del POI.
- [ ] La modifica di un `EcPoi` rigenera i PDF già presenti degli `EcTrack` che lo mostrano nella
      scheda.
- [ ] La cancellazione dell'elemento cancella i suoi PDF (`StorageService::deleteModelFiles`
      cancella già la cartella `files/{type}/{id}`: da verificare che il percorso dei PDF ci stia
      dentro).

### Action Nova

- [ ] Action su `EcTrack` ed `EcPoi` che genera le schede degli elementi selezionati, con le lingue
      scelte fra quelle dell'app; di default solo l'italiano.

### ZIP delle schede

- [ ] Rotta che riceve gli id di più `EcTrack` e la lingua, e restituisce uno ZIP con un PDF per
      sentiero. Riusa i PDF salvati; compila solo quelli mancanti.
- [ ] Stesso flusso della scheda singola: link, pagina d'attesa, redirect.
- [ ] Lo ZIP è un file temporaneo, con scadenza; non si conserva.
- [ ] Un tetto tecnico al numero di sentieri per ZIP.

### Mappa d'insieme

- [ ] Rotta che riceve gli id di più `EcTrack` e la lingua, e produce un PDF A4 orizzontale con una
      mappa vettoriale che li contiene tutti, con i codici dei sentieri.
- [ ] Scala automatica, con un **tetto**: se per contenere la selezione servirebbe una scala
      peggiore della soglia (indicativamente 1:50.000), il PDF non si genera e la pagina d'attesa
      spiega di ridurre la selezione.
- [ ] Il PDF è un file temporaneo, con scadenza; non si conserva.

### Grafica di default e personalizzazione

- [ ] Template di default (scheda neutra): dati principali, descrizione, mappa, foto, crediti della
      mappa (© OpenStreetMap e contributori, Webmapp), colore primario da `primary_color` dell'app.
- [ ] Generatore che legge un `tokens.json` nel formato del design system (liste
      `{"name","value","usage"}` per famiglia, alias `{nome}`) e produce lo `.sty`: colori del tema
      `light`, famiglie e scala tipografica, spazi, raggi, bordi; px convertiti in pt.
- [ ] Macro LaTeX per i componenti ricorrenti, con il nome del componente del design system
      (`BadgeStato`, `PillDifficolta`, `TitoloSezione`, `Tabella`, `FilettoOro`).
- [ ] Lo shard sostituisce l'impaginazione con l'override delle viste
      (`resources/views/vendor/wm-package/…`) e lo stile con il proprio `tokens.json`.

### Interfaccia

- [ ] [UX] La pagina d'attesa mostra che il lavoro è in corso (non una pagina vuota) e annuncia lo
      stato agli screen reader (`aria-busy` / regione `aria-live`).
- [ ] [UX] Nel PDF, corpo del testo non sotto i 9 pt e contrasti dei token rispettati anche su
      carta (le tinte «solo grafica» del design system non diventano testo). Giudizio mio, non da
      una fonte.

## Rischi

- **Iniezione LaTeX dal testo degli editor.** Mitigazione: escape di tutti i caratteri speciali,
  compilazione senza shell escape e con lettura/scrittura limitate alla cartella di lavoro (più,
  con la soluzione B, un container senza segreti). Test con descrizioni che contengono `\input`,
  `\write18`, `%`, `&`.
- **Picchi di memoria del rendering.** Mitigazione: limite di memoria per rendering, coda Horizon
  dedicata, tetto sulla scala della mappa d'insieme e sul numero di sentieri per ZIP.
- **Dipendenza da `tiles.maphub.it`.** Se il server delle tile non risponde, la mappa non si
  disegna. Mitigazione: timeout e errore leggibile; il PDF esistente non si sovrascrive con uno
  peggiore. Da decidere nel piano se generare comunque la scheda senza mappa.
- **Rigenerazioni a cascata.** La modifica di un POI molto usato rigenera i PDF di più sentieri.
  Mitigazione: si rigenerano solo le lingue già presenti, sulla coda dedicata.
- **Peso di TeX Live.** Lo schema completo pesa diversi GB (stima mia, non misurata), che finiscono
  nell'immagine PHP (soluzione A) o nell'immagine del servizio (soluzione B). Mitigazione: solo i
  pacchetti necessari; da misurare nel piano.
- **Versione di Node per il renderer** (soluzione A): va verificato che la versione di Node
  installata da `apt` nell'immagine PHP sia supportata da `@maplibre/maplibre-gl-native`.
- **Il renderer MapLibre con lo stile Webmapp non è ancora stato provato.** La resa a 300 dpi, i
  font dei glyph e lo sprite vanno verificati con un prototipo prima del resto.

## Out of scope

- Tutto il lato WordPress, che fa un altro ticket usando le rotte e l'URL nel JSON di dettaglio
  di questo: il pulsante «Stampa» del dettaglio nel tema figlio di `wp-forestas` (`track.php`,
  `poi.php`), e i pulsanti «Stampa la mappa di insieme» e «Scarica le schede PDF» nella barra della
  selezione multipla del plugin `wp-package`.
- La scala selezionabile e le pagine multiple ad atlante (WEBSITE-23, nelle bozze del capitolato).
- L'uso dell'operazione `map` fuori dal PDF (per esempio la condivisione dei cammini su
  camminiditalia, o le immagini di condivisione di `UgcTrack`): il motore lo permette,
  l'integrazione è di altri ticket.
- Il collegamento dell'app al nuovo PDF al posto della stampa ereditata da GeoHub.
- L'installazione del motore su shard diversi da Forestas.

## Moduli toccati

Tutti nel repo `wm-package`.

- `docker/render/` (nuovo, solo con la soluzione B): Dockerfile, server Node, configurazione TeX.
- `config/` : attivazione del motore (e, con B, URL del servizio), coda, tetti (scala, numero di
  sentieri), stile delle tile.
- `src/Services/` : interfaccia del motore di rendering e sua implementazione, servizio di stampa
  (composizione, salvataggio con
  `StorageService`), generatore dello `.sty` da `tokens.json`, conversione HTML→LaTeX con escape.
- `src/Jobs/` : Job di generazione della scheda, dello ZIP e della mappa d'insieme.
- `src/Services/Models/EcTrackService.php` : Job di rigenerazione nei `publicationJobs`.
- `src/Services/Models/EcPoiService.php`, `src/Observers/EcPoiObserver.php` : rigenerazione dei PDF
  del POI e dei sentieri collegati.
- `src/Models/EcTrack.php`, `src/Models/EcPoi.php` : URL della scheda nel JSON di dettaglio; il
  trait della stampa.
- `routes/web.php` (o equivalente del package): rotte della scheda, dello stato, dello ZIP e della
  mappa d'insieme.
- `resources/views/` : template LaTeX di default (view Blade che produce `.tex`), pagina d'attesa.
- `resources/lang/*.json` : etichette del template.
- `src/Nova/` : Action di generazione su `EcTrack` ed `EcPoi`.
- `tests/` : test di generazione, escape, rotte e rigenerazione.
