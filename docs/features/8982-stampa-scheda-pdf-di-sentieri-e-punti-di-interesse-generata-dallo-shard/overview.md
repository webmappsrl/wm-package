> Ticket: oc:8982
> Stato: approvata il 2026-11-18

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

### Risposta (18/11): A per il PDF, le mappe le fa `wm-tiles`

La domanda sopra resta com'era posta. La risposta è arrivata misurando ciò che il testo dava per
stimato, e la riassume una frase: **la scelta era difficile perché trattava il motore come un
blocco solo**. `xelatex` e il renderer delle mappe sono oggetti diversi, e separati trovano ognuno
il proprio posto.

**Cosa hanno detto le misure** (18/11, sul container `php-forestas` e sui repo):

| Quello che il testo sopra dice | Misurato |
|---|---|
| TeX Live «pesa diversi GB» | `texlive-xetex` con le dipendenze = **338 MB**, con `texlive-fonts-recommended` **353 MB**. I 7,4 GB sono `texlive-full`, che il requisito già escludeva. Le librerie grafiche sono altri **15 MB** |
| «`@maplibre/maplibre-gl-native` ha binari per versioni precise di Node, e Node arriva da `apt`» | Node nell'immagine è **v20.19.2**, il modulo chiede `>=20`, e la release pubblica il prebuilt `node-v115` per `linux-x64` e `linux-arm64`. Il punto è chiuso |
| «uno shard è rimasto indietro (maphub a `php:8.4.3-fpm`)» | **Sbagliato**: maphub è a `php:8.4.27-fpm`, anche su `origin/develop`. A divergere sono **ersaf** (`8.4.3-fpm`, cioè Debian 12 con Node 18) ed **ersaf-osm** (`8.4-fpm`, un tag mobile). Il fatto regge, l'esempio no |
| «camminiditalia ha 2,3 GB liberi su 7,6» | Il margine è una scelta, non un dato: il container Horizon di uno shard occupa 2,85 GiB che sono **47 worker fermi** a 51 MB di PSS ciascuno, più 8 supervisor. Vengono da `--max-processes=30` su `default`, altri 30 su `aws`, 10 su `sardegnasentieri-import`. Abbassandoli si liberano circa 2 GB |
| «il contenimento si ottiene con `prlimit`» | Vero per `xelatex`, non per il renderer: `RLIMIT_AS` limita lo spazio di indirizzamento virtuale, non la RAM fisica, e un modulo con contesto GL e più thread ne riserva molto più di quanto usi. Inoltre il limite di memoria di Horizon **non copre i processi figli**: `Illuminate\Queue\Worker::memoryExceeded` guarda `memory_get_usage(true)`, cioè la sola memoria PHP del worker |

**La terza strada.** Il rendering delle mappe non deve stare in nessuno shard: va in un **servizio
unico**, sull'host che serve già le tile, dove stanno i dati che il rendering legge e dove il
codice esiste già — `wm-tiles` ha `@maplibre/maplibre-gl-native` fra le dipendenze,
`pipeline/render-png.mjs` che disegna un frame, `src/raster/stile-nativo.js` che adatta lo stile
Webmapp e `src/raster/tiles.js` con il rendering a metatile. Si riusa quel codice, non un servizio
che gira: oggi quella pipeline si lancia a mano da un'altra macchina e il server distribuisce PNG
precalcolati. Il servizio è il ticket **oc:9011**.

**La decisione:**

- **`map`** — fuori dallo shard, chiesta via HTTP al servizio di oc:9011. Lo shard non installa
  né il modulo nativo né le librerie grafiche.
- **`pdf`** — **soluzione A**: `xelatex` nell'immagine PHP, che il container `php` e quello
  `horizon` condividono (stesso `build:`, stessa `image: wm-phpfpm:8.4`, cambia solo il comando).
  Il Job compila dove i file già sono: nessun trasporto di sorgente, foto e font.

**Cosa cade della domanda sopra.** Il contro della soluzione A — le dipendenze nel Dockerfile di
ogni shard — si riduce a `texlive-xetex`, e si chiude mettendo l'installazione in uno script del
package (`docker/render/install-deps.sh`) che il Dockerfile dello shard richiama: le dipendenze
restano versionate nello stesso commit del codice che le usa, che era il solo motivo serio di B.
Lo script rifiuta la build su una base Debian inadatta, altrimenti uno shard su bookworm
installerebbe un TeX più vecchio senza errori.

**Condizioni che la risposta porta con sé:**

- **Build context alla root** nello shard (`context: .` più `dockerfile: docker/configs/phpfpm/Dockerfile`),
  altrimenti la `COPY` dello script dal package non è possibile: oggi è `build: ./docker/configs/phpfpm`.
- **Un marcatore di versione** scritto dallo script nell'immagine e controllato dal Job: il deploy
  fa `git submodule update` e poi `docker exec` nel container esistente, **senza ricostruire
  l'immagine**, quindi un aggiornamento del package lascerebbe le dipendenze vecchie.
- **Il picco di `xelatex`** con le foto va misurato prima di fissare `prlimit` e il numero di
  worker della coda dedicata; le foto vanno ridimensionate prima della compilazione.
- **`maxProcesses` di Horizon** va abbassato, per lasciare margine.

**Cosa resta non provato**, e vale per oc:9011 più che per qui: il rendering a formato pagina,
circa 2480×3508 px a `pixelRatio` alto. L'unico caso provato è l'opposto, tessere piccole a
`PIXEL_RATIO` 0.5.

## Requisiti

### Motore di rendering

Valgono in entrambe le soluzioni della «Domanda aperta»; dove una voce dipende dalla scelta, lo
dice.

- <a id="r1"></a>**R1** — Interfaccia nel package con due operazioni, usata da tutto il resto del codice:
  - `map`: riceve geometrie GeoJSON, dimensioni in mm, `pixelRatio` e lo stile; restituisce un PNG
    alla risoluzione di stampa (circa 300 dpi). Lo stile di default è
    `https://tiles.maphub.it/style.json`;
  - `pdf`: riceve il sorgente `.tex` e i file allegati (mappa, foto, font); restituisce il PDF o
    l'errore di compilazione.
- <a id="r2"></a>**R2** — XeLaTeX (o LuaLaTeX) e `@maplibre/maplibre-gl-native`, con TeX Live **ridotto ai pacchetti
      usati dai template**, non lo schema completo: la produzione di camminiditalia ha 11 GB liberi
      su 38.
- <a id="r3"></a>**R3** — Compilazione senza shell escape e con `openin_any=p` / `openout_any=p`.
- <a id="r4"></a>**R4** — Limite di tempo e di memoria per ogni rendering, e un errore leggibile quando scatta (soluzione
      A: per processo; soluzione B: per richiesta e `mem_limit` sul container).
- <a id="r5"></a>**R5** — Soluzione B: Dockerfile e server in `docker/render/`, nessun segreto nell'ambiente del
      container, nessuna porta pubblicata.
- <a id="r6"></a>**R6** — Il motore si attiva dalla configurazione del package; spento, le funzioni di stampa non ci
      sono e il JSON di dettaglio non espone link.

### Generazione e salvataggio

- <a id="r7"></a>**R7** — Struttura come in osm2cai2 (`GeneratesPdfTrait`, `PdfService`, `GeneratePdfJob`), portata nel
      package con il motore LaTeX: il modello dichiara view, percorso del file e lingue.
  - **Da fare invece (18/11): descrivere qui la struttura, senza prenderla da osm2cai2.** Quel repo
    è molto indietro sul package e la sua stampa è custom da eliminare, non un modello: il motore è
    **dompdf** su una view Blade HTML in A4 verticale (`dompdf/dompdf: ^2.0`), le **lingue non sono
    gestite da nessuna parte**, e la mappa non è renderizzata ma è uno **screenshot PNG** salvato
    dal campo Nova `FeatureCollectionMap` con `enableScreenshot()` e riletto in base64. Delle tre
    cose che il requisito dice di prendere da lì — view, percorso del file, lingue — la terza non
    esiste. La forma che serve è: un trait con cui il modello dichiara la view del sorgente `.tex`,
    il percorso del file e le lingue; un servizio che compone e salva; un Job che li mette insieme.
- <a id="r8"></a>**R8** — Il Job raccoglie i dati, chiede la mappa al motore (`map`), scarica le foto da S3, compone il
      `.tex` dalla view Blade, chiede il PDF al motore (`pdf`) e lo salva con `StorageService` su `wmfe`, un file per
      lingua.
- <a id="r9"></a>**R9** — I Job di stampa girano su una coda Horizon dedicata.
- <a id="r10"></a>**R10** — Il testo libero (descrizione, nomi) passa da HTML a LaTeX con l'escape di tutti i caratteri
      speciali: tutte le 769 descrizioni di `EcTrack` di Forestas hanno HTML, 457 hanno caratteri
      speciali per LaTeX (conteggio locale del 17/11).
- <a id="r11"></a>**R11** — Le immagini in formati che XeLaTeX non legge (per esempio WebP) si convertono prima della
      compilazione.
- <a id="r12"></a>**R12** — Le etichette fisse del template vengono da `resources/lang/*.json` del package (chiave in
      inglese, voce in tutte e cinque le lingue `de`, `en`, `es`, `fr`, `it`); lo shard le può
      sovrascrivere. Per una lingua senza testo, i campi tradotti usano il fallback di
      `spatie/laravel-translatable`.

### Rotte e pagina d'attesa

- <a id="r13"></a>**R13** — Rotta web della scheda di `EcTrack` e di `EcPoi`, con la lingua nell'URL (`?lang=`). PDF
      presente e aggiornato: `302` al file su `wmfe`. Assente: accoda il Job e mostra la pagina
      d'attesa.
- <a id="r14"></a>**R14** — Rotta di stato, interrogata dalla pagina d'attesa, che dice se il file è pronto.
- <a id="r15"></a>**R15** — La pagina d'attesa fa il redirect da sola quando il file è pronto, e mostra un messaggio
      leggibile se la generazione fallisce.
- <a id="r16"></a>**R16** — Un PDF già in coda non si accoda una seconda volta se arrivano più richieste insieme.
- <a id="r17"></a>**R17** — L'URL della scheda (della rotta, non del file) è nel JSON di dettaglio di `EcTrack` ed `EcPoi`.

### Aggiornamento

- <a id="r18"></a>**R18** — Un Job nei `publicationJobs` di `EcTrack` (`src/Services/Models/EcTrackService.php:416`)
      rigenera i PDF **delle sole lingue già presenti**. Un elemento mai stampato non genera nulla.
- <a id="r19"></a>**R19** — Per `EcPoi` la catena di oggi (`EcPoiService::updateDataChain`) parte solo quando cambia la
      geometria: serve un aggancio sul salvataggio che rigeneri i PDF già presenti del POI.
- <a id="r20"></a>**R20** — La modifica di un `EcPoi` rigenera i PDF già presenti degli `EcTrack` che lo mostrano nella
      scheda.
- <a id="r21"></a>**R21** — La cancellazione dell'elemento cancella i suoi PDF (`StorageService::deleteModelFiles`
      cancella già la cartella `files/{type}/{id}`: da verificare che il percorso dei PDF ci stia
      dentro).

### Action Nova

- <a id="r22"></a>**R22** — Action su `EcTrack` ed `EcPoi` che genera le schede degli elementi selezionati, con le lingue
      scelte fra quelle dell'app; di default solo l'italiano.

### ZIP delle schede

- <a id="r23"></a>**R23** — Rotta che riceve gli id di più `EcTrack` e la lingua, e restituisce uno ZIP con un PDF per
      sentiero. Riusa i PDF salvati; compila solo quelli mancanti.
- <a id="r24"></a>**R24** — Stesso flusso della scheda singola: link, pagina d'attesa, redirect.
- <a id="r25"></a>**R25** — Lo ZIP è un file temporaneo, con scadenza; non si conserva.
- <a id="r26"></a>**R26** — Un tetto tecnico al numero di sentieri per ZIP.

### Mappa d'insieme

- <a id="r27"></a>**R27** — Rotta che riceve gli id di più `EcTrack` e la lingua, e produce un PDF A4 orizzontale con una
      mappa vettoriale che li contiene tutti, con i codici dei sentieri.
- <a id="r28"></a>**R28** — Scala automatica, con un **tetto**: se per contenere la selezione servirebbe una scala
      peggiore della soglia (indicativamente 1:50.000), il PDF non si genera e la pagina d'attesa
      spiega di ridurre la selezione.
- <a id="r29"></a>**R29** — Il PDF è un file temporaneo, con scadenza; non si conserva.

### Grafica di default e personalizzazione

- <a id="r30"></a>**R30** — Template di default (scheda neutra): dati principali, descrizione, mappa, foto, crediti della
      mappa (© OpenStreetMap e contributori, Webmapp), colore primario da `primary_color` dell'app.
- <a id="r31"></a>**R31** — Generatore che legge un `tokens.json` nel formato del design system (liste
      `{"name","value","usage"}` per famiglia, alias `{nome}`) e produce lo `.sty`: colori del tema
      `light`, famiglie e scala tipografica, spazi, raggi, bordi; px convertiti in pt.
- <a id="r32"></a>**R32** — Macro LaTeX per i componenti ricorrenti, con il nome del componente del design system
      (`BadgeStato`, `PillDifficolta`, `TitoloSezione`, `Tabella`, `FilettoOro`).
- <a id="r33"></a>**R33** — Lo shard sostituisce l'impaginazione con l'override delle viste
      (`resources/views/vendor/wm-package/…`) e lo stile con il proprio `tokens.json`.

### Interfaccia

- <a id="r34"></a>**R34** — [UX] La pagina d'attesa mostra che il lavoro è in corso (non una pagina vuota) e annuncia lo
      stato agli screen reader (`aria-busy` / regione `aria-live`).
- <a id="r35"></a>**R35** — [UX] Nel PDF, corpo del testo non sotto i 9 pt e contrasti dei token rispettati anche su
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
  **Quel ticket deve mettere `rel="nofollow"` sul link «Stampa» e un `Disallow` in `robots.txt`**:
  la rotta della scheda accoda un Job quando il PDF manca, e un crawler che segue il link su ogni
  scheda accoda una compilazione per ogni sentiero (765 su Forestas) e altrettanti rendering, senza
  che nessuno abbia chiesto un PDF.
- La scala selezionabile e le pagine multiple ad atlante (WEBSITE-23, nelle bozze del capitolato).
- L'uso dell'operazione `map` fuori dal PDF (per esempio la condivisione dei cammini su
  camminiditalia, o le immagini di condivisione di `UgcTrack`): il motore lo permette,
  l'integrazione è di altri ticket.
- Il collegamento dell'app al nuovo PDF al posto della stampa ereditata da GeoHub.
- L'installazione del motore su shard diversi da Forestas.
- **La rimozione della stampa custom di osm2cai2**, che quando questa sarà pronta diventa codice
  superato: `app/Traits/GeneratesPdfTrait.php`, `app/Services/PdfService.php`,
  `app/Jobs/GeneratePdfJob.php`, gli override di `TrailSurvey`, il campo `pdf_url` e lo screenshot
  della mappa salvato da Nova. Va fatta quando osm2cai2 verrà allineato al package, insieme alle
  altre soluzioni custom che il package nel frattempo ha assorbito.

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
