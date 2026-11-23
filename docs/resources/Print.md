# Stampa PDF delle schede (dominio `print`)

> Introdotta con oc:8982. Fonte di verità sulle decisioni:
> [`docs/features/8982-stampa-scheda-pdf-di-sentieri-e-punti-di-interesse-generata-dallo-shard/overview.md`](../features/8982-stampa-scheda-pdf-di-sentieri-e-punti-di-interesse-generata-dallo-shard/overview.md).

Il dominio produce tre file stampabili: la **scheda PDF** di un `EcTrack` o di un `EcPoi` in una
lingua, lo **ZIP delle schede** di più `EcTrack`, la **mappa d'insieme** in PDF di più `EcTrack`. Il
PDF si compone in LaTeX (`xelatex` nell'immagine PHP dello shard); la mappa è un PNG chiesto a un
servizio esterno (oc:9011). È un [dominio opzionale](OptionalDomains.md): spento, nulla di tutto
questo esiste.

## Attivazione

`config/wm-package.php`, sezione `features.print`:

| Chiave | Env | Default |
|---|---|---|
| `enabled` | `WM_PRINT_ENABLED` | `false` |
| `queue` | `WM_PRINT_QUEUE` | `print` |
| `map_service.driver` | `WM_PRINT_MAP_DRIVER` | `fake` (l'altro valore è `http`) |
| `map_service.url` | `WM_PRINT_MAP_SERVICE_URL` | `null` |
| `map_service.token` | `WM_PRINT_MAP_SERVICE_TOKEN` | `null` |
| `map_service.timeout` | | 60 s |
| `compile.timeout` | | 120 s |
| `compile.memory_mb` | | 1024 |
| `photos.max_bytes`, `photos.max_pixels` | | 30 MB, 50.000.000 px (oltre, la foto si salta) |
| `photos.total_timeout` | | 60 s: budget del download di tutte le foto di una scheda (esaurito, le restanti si saltano con un warning); ogni foto ha una scadenza sull'intero download pari al residuo, mai oltre 30 s |
| `map.width_mm`, `map.height_mm` | | 174, 98: dimensioni (mm) della mappa della scheda, chieste al servizio mappe e usate dalla view (`$mapWidthMm`, `$mapHeightMm`) |
| `zip.max_tracks` | | 50 |
| `zip.max_wait_seconds` | | 1800 |
| `overview_map.max_scale` | | 50000 (1:50.000) |
| `deps_version` | | contenuto di `docker/render/VERSION`, `null` se il file non c'è |

Con il dominio acceso compaiono:

- le rotte di `routes/domains/print.php` (caricate da `registerEnabledDomains()`):

  | Nome | Percorso | Throttle |
  |---|---|---|
  | `print.show` | `GET /print/{type}/{id}?lang=` (`type` è `ec-track` o `ec-poi`) | 30/min |
  | `print.status` | `GET /print/{type}/{id}/status` | 60/min (prefisso `print-status`) |
  | `print.zip` | `GET /print/zip?ids[]=&lang=` | 5/min (prefisso `print-zip`) |
  | `print.zip.status` | `GET /print/zip/status` | 60/min (prefisso `print-status`) |
  | `print.overview-map` | `GET /print/overview-map?ids[]=&lang=` | 5/min (prefisso `print-overview`) |
  | `print.overview-map.status` | `GET /print/overview-map/status` | 60/min (prefisso `print-status`) |

  Le tre rotte di stato condividono il contatore `print-status`, separato da quello senza prefisso
  di `print.show` e delle altre rotte dell'app: la pagina d'attesa le interroga ogni 5 s.
  `ec-poi` si risolve con `wm-package.ec_poi_model` (default `EcPoi`), `ec-track` con
  `wm-package.ec_track_model`: lo shard che ha sottoclassi le dichiara lì.

- il comando `wm:print-cleanup`;
- l'Action Nova `GeneratePrintPdfAction` su `EcTrack` ed `EcPoi`: genera le schede degli elementi
  selezionati nelle lingue scelte (MultiSelect fra quelle dell'app, default `it`);
- `properties.print_url` nel dettaglio di `EcTrack` (`getDetailGeojson()`), senza `lang`: la lingua
  la sceglie la rotta. Il package non ha un endpoint di dettaglio POI: lo shard aggiunge la chiave
  al proprio con `PrintService::sheetUrl('ec-poi', $id)`, che dà `null` a dominio spento;
- il Job di rigenerazione nei `publicationJobs` di `EcTrack` e nel `saved` di `EcPoiObserver`.

Il log va sul canale `print` (`storage/logs/print.log`, da `config/wm-logging.php`).

## Flusso

**Scheda singola.** `print.show` valida `type`, `id` e `lang` (che deve essere fra le lingue
dell'app, altrimenti `404` senza accodare nulla; se manca vale `default_language` dell'app). Poi:

1. `PrintService::isFresh()`: il PDF è «aggiornato» se esiste su `wmfe` e il suo `lastModified` è
   `>= updated_at` del modello. Il controllo sta nella rotta perché alcune strade non sollevano
   eventi (import con `saveQuietly()`, reset notturno con `TRUNCATE ... RESTART IDENTITY`).
2. Aggiornato: `302` al file su `wmfe`.
3. Altrimenti `GeneratePrintPdfJob::dispatchFor()` (unico per modello e lingua finché è in coda,
   quindi più richieste insieme accodano una volta sola), stato `queued` in cache (`PrintStatus`) e
   pagina d'attesa (`print/waiting`, `aria-busy` e regione `aria-live`). La pagina interroga
   `print.status` ogni 5 s e fa il redirect quando lo stato è `done`; se è `failed` mostra un
   messaggio generico e un link `?retry=1`. Smette di aspettare dopo il timeout del Job più 60 s.

**Unicità e sovrapposizioni della scheda.** `GeneratePrintPdfJob` è `ShouldBeUniqueUntilProcessing`:
il lock di unicità vale mentre il Job è in coda e si libera quando l'esecuzione parte. Un salvataggio
che arriva durante la generazione (dati letti prima, PDF scritto dopo) accoda quindi una nuova
generazione: con il lock tenuto fino alla fine la richiesta andrebbe persa e il PDF vecchio, scritto
dopo `updated_at`, risulterebbe fresco per sempre. Due esecuzioni per lo stesso modello e lingua non
si sovrappongono: il middleware `WithoutOverlapping` (chiave = `uniqueId()`) rimette in coda la
seconda ogni 30 s, con lock che scade dopo il timeout del Job. Per questo `$tries = 20` (ogni attesa
conta come tentativo) e le eccezioni sono limitate da `$maxExceptions = 2`; un timeout è terminale
(`$failOnTimeout`). Il timeout è `2 x compile.timeout + map_service.timeout + photos.total_timeout + 30`
(`GeneratePrintPdfJob::computeTimeout()`, 390 s con i default): due passate di `xelatex`, la mappa, le
foto e un margine.

I lock di unicità dei tre Job (scheda, ZIP, mappa d'insieme) stanno su Redis (`uniqueVia()`): l'Action
Nova li accoda dentro la transazione di Nova, e con `CACHE_STORE=database` un lock già presente
farebbe fallire la transazione con `25P02` (oc:8564). Il lock di `WithoutOverlapping` si prende nel
worker, fuori da transazioni, sullo store di default.

**Rigenerazione.** `RegeneratePrintPdfsJob` rigenera **solo le lingue che hanno già** un
`print-{locale}.pdf`: un elemento mai stampato non genera nulla. Parte nei `publicationJobs` di
`EcTrack` e a ogni salvataggio di un `EcPoi` (che già innesca anche la catena dei sentieri
collegati). Dal `saved` di `EcPoiObserver` parte con `afterCommit()`: partito prima del commit,
leggerebbe i dati vecchi e scriverebbe un PDF più recente del nuovo `updated_at`, quindi «fresco».
Nei `publicationJobs` è invece l'ultimo anello di `Bus::chain()` in `updateDataChain()`, che **non** è
accodata dopo il commit: il rischio resta se l'intera catena finisce prima del commit del salvataggio,
cosa improbabile perché la precedono i Job AWS e delle relazioni. La cancellazione dell'elemento
cancella i PDF con la cartella dei file del modello.

**ZIP.** `print.zip` riceve fino a `zip.max_tracks` id (interi, senza duplicati; vuoto o troppi
danno una pagina `422`). La lingua deve essere comune a tutte le App delle tracce. Stesso flusso della
scheda, ma `BuildPrintZipJob` è un assemblatore breve: accoda i `GeneratePrintPdfJob` mancanti e si
rilascia (`release()`, ogni 15 s) finché sono pronti, poi costruisce lo ZIP in
`{shard}/print-tmp/zip/` su `wmfe`. Basta una scheda fallita perché lo ZIP fallisca. Fresco se ha meno di 24 ore e non è
precedente all'ultima modifica delle tracce.

**Mappa d'insieme.** `print.overview-map`, stessi id e stessa lingua dello ZIP. Prima di accodare la
rotta calcola la scala (`PrintService::overviewScale()` sul bbox PostGIS): oltre `overview_map.max_scale`
niente Job, pagina `422` che chiede di ridurre la selezione. Il PDF è A4 orizzontale, **una pagina**:
mappa 200×170 mm (`OVERVIEW_MAP_WIDTH_MM`, `OVERVIEW_MAP_HEIGHT_MM`) più legenda in colonna. Il file
va in `{shard}/print-tmp/overview/`.

**File della selezione.** ZIP e mappa d'insieme condividono il trait
`Jobs\Print\Concerns\BuildsPrintSelectionFile` (chiave, percorso, `dispatchFor()`, `uniqueVia()`,
`failed()`). I file stanno sotto `StorageService::getShardBasePath()` come tutti gli altri, e la
chiave è `sha1(shard|id ordinati|lingua)`: `wmfe` è condiviso fra gli shard, e senza lo shard nella
chiave e nel percorso uno shard servirebbe i file di un altro e la sua pulizia li cancellerebbe.

**Pulizia.** `wm:print-cleanup` cancella da `wmfe` i file sotto `{shard}/print-tmp/` (ZIP e mappe
d'insieme) più vecchi di 24 ore, solo dello shard corrente, e ogni cartella sotto
`storage/app/print-tmp/` più vecchia di 24 ore: le cartelle di lavoro di ZIP (`zip-*`), mappa
d'insieme (`overview-*`), foto della scheda (`assets-*`) e del compilatore (uuid) lasciate da un Job
morto a metà. Lo schedula il package
(`ScheduleServiceProvider`, `daily()`), solo a dominio acceso: lo shard non deve fare nulla.

## Il logo

La scheda di default (traccia e POI) mostra in testata, a sinistra sopra il titolo, il logo dell'App
del modello: il media «Icon small» (collection `icon_small`), con ripiego su «Icon» (`icon`); senza
nessuno dei due la testata resta senza logo. `PrintService` legge il file **dal disco della media
library** (`Storage::disk($media->disk)->readStream($media->getPathRelativeToRoot())`), non via HTTP:
in locale l'URL pubblico (MinIO su `localhost:9000`) non è raggiungibile dal container. Valgono i
limiti delle foto (`photos.max_bytes`, `photos.max_pixels`); il file si salva come `logo.png` (PNG,
trasparenza conservata, lato lungo al massimo 800 px) nella cartella di compilazione e la view lo
riceve come `$logoFile` (null se manca), alto 12 mm con le proporzioni originali. Un formato che GD non
legge (per esempio SVG), un file corrotto o mancante sul disco si saltano con un warning `Logo saltato`
sul canale `print`: il logo non fa mai fallire la scheda.

Un cambio del logo o del `primary_color` dell'App **non rigenera** le schede già generate: la
freschezza guarda l'`updated_at` del modello (traccia o POI), non quello dell'App. I PDF restano con il
logo e il colore vecchi finché il sentiero o il POI non cambia, o finché non si rigenerano a mano
(Action Nova «Generate print PDF»). `wm:print-cleanup` non li tocca: cancella solo ZIP e mappe d'insieme.

La mappa d'insieme non ha logo: le tracce selezionate possono appartenere ad App diverse, quindi non
c'è un'App univoca da cui prenderlo.

## La mappa

`GeneratePrintPdfJob` e gli altri Job chiedono la mappa al contratto
`Services\Print\Contracts\MapRenderer`, risolto dal service provider in base a
`map_service.driver`:

- `fake` (default): `FakeMapRenderer` logga la richiesta e restituisce `resources/print/map-placeholder.png`.
  Resta il default finché il servizio non esiste (oc:9011);
- `http`: `HttpMapRenderer`, POST a `map_service.url` con token Bearer opzionale. È uno scheletro: il
  contratto (autenticazione, formato di ritorno) si completa con oc:9011.

La richiesta è `MapRenderRequest` con `shard` (da `wm-package.shard_name`), `feature_collection`
(una feature per sentiero o punto, con `id`, `ref`, `name`), `width_mm`, `height_mm`. Un errore del
servizio risale: la scheda non si genera e il PDF esistente resta.

## Personalizzazione per shard

**Impaginazione.** Ogni view è un sorgente LaTeX in Blade: `print/ec-track`, `print/ec-poi`,
`print/overview-map` (più `print/waiting`, `print/error` e `print/partials/footer`). Lo shard le
sostituisce con una view omonima in `resources/views/vendor/wm-package/print/`. **Il contratto
(le variabili ricevute e quali sono già escaped) è nel docblock in testa a ogni view**: lì va letto,
non qui. Le stringhe già LaTeX-sicure si stampano con `{!! !!}`; in LaTeX non si scrive `{{` letterale.
Le etichette fisse vengono da `resources/lang/{de,en,es,fr,it}.json` (chiave in inglese), che lo shard
può sovrascrivere.

**Due livelli.** La scheda di default si adatta all'App con il suo **tema** (tab `theme`); per
qualunque altra cosa lo shard scrive la **view**. Non ci sono token né stili dello shard da caricare.

1. *Tema dell'App.* `PrintService` legge da `properties->theme`: `primary_color` (`wmprimary`, da cui
   `wmtitle`), `secondary_color` (`wmaccent`; vuoto: come il titolo), `tertiary_color` (`wmrule`; vuoto:
   l'oro di `wm-print`), con la stessa validazione esadecimale (`#RRGGBB`, altrimenti il valore è
   ignorato); `font_family_content` (testo: `\setmainfont` e `\setsansfont`) e `font_family_header`
   (titoli, via `\WmHeaderFont`), solo dentro `\IfFontExistsTF`: un font non installato nell'immagine
   lascia quello di default e il PDF si genera comunque. I nomi dei font finiscono nel `.tex`, quindi
   ammettono solo lettere, cifre, spazi e `-` (max 60 caratteri), altrimenti si ignorano: un nome in stile CSS (`Roboto, sans-serif`, `'Open Sans'`) viene scartato, va scritto il solo nome della famiglia (`Roboto`). Testo, testo
   tenue e linee restano fissi. Lo applica il partial `print/partials/theme`.
2. *View dello shard.* Per tutto il resto (impaginazione, altri colori e font, altre dimensioni) lo
   shard sostituisce la view e definisce nel proprio preambolo colori, font e dimensioni, oppure
   ridefinisce i ruoli (vedi sotto). Il package passa solo le variabili del contratto e allega
   `wm-print.sty`, che ha le macro di base e si carica sempre.

`resources/print/wm-print.sty` definisce le macro dei componenti usate dalle view (vedi sotto) e i
default. `PrintService::styleFiles()` restituisce solo `wm-print`; resta un elenco e `$styleFiles` resta
nel contratto delle view, perché gli override esistenti lo scorrono con `@foreach`: nessun override si
rompe, ma non ci sono più altri stili da includere.

**Dimensioni della mappa.** `features.print.map.width_mm` e `height_mm` (default 174 x 98) vanno sia al
servizio mappe sia alla view (`$mapWidthMm`, `$mapHeightMm`): uno shard che vuole la mappa grande la
chiede grande, invece di ingrandire un PNG piccolo, e impagina con le stesse variabili. La view di default
usa la larghezza della colonna e l'altezza configurata: l'altezza passa da `0.38\textheight` a `height=98mm` (cambio voluto, a parità di resa su A4). La mappa d'insieme ha un'area propria (200 x 170 mm).

**Colori per ruolo.** Le macro di `wm-print` e le view di default colorano solo per ruolo, mai con
`wmprimary`/`wmgold`/`wmgray` (che restano definiti, per gli override degli shard):

| Ruolo | Dove | Senza tema vale |
|---|---|---|
| `wmtitle` | titoli (scheda, sezioni, mappa d'insieme) | `wmprimary` |
| `wmaccent` | etichette della tabella, badge e pillole, numeri in evidenza | `wmprimary` |
| `wmrule` | filetti (`\FilettoOro`) | `wmgold` |
| `wmtext` | testo | nero |
| `wmmuted` | didascalie, crediti del piè di pagina | `wmgray` |
| `wmline` | linee (filetto del piè di pagina, tabelle) | nero |

Il tema dell'App definisce `wmaccent` e `wmrule` con `\definecolor` nel partial, prima di `\WmDefineRoles`.
`\WmDefineRoles` (chiamata dalla view subito dopo `wmprimary`, e di nuovo a `\begin{document}` per le view
degli shard che non la chiamano) dà il valore di ripiego solo ai ruoli ancora indefiniti, quindi un ruolo
definito prima sopravvive. Il controllo è su `\string\color@<ruolo>`: xcolor registra i colori con il
backslash nel nome. Una view di shard che ridefinisce un ruolo con `\colorlet` dopo `\WmDefineRoles` vince
comunque.

Le macro dei componenti (`TitoloScheda`, `TitoloSezione`, `FilettoOro`, `BadgeStato`, `PillDifficolta`,
`Tabella`/`RigaTabella`, `PiePagina`) sono quelle di `wm-print.sty`; `\WmHeaderFont` (vuoto di default) è il
font dei titoli.

## Installazione nell'immagine dello shard

`docker/render/install-deps.sh` installa `texlive-xetex`, `texlive-fonts-recommended` e `fontconfig`,
rifiuta basi Debian precedenti alla 13 e scrive il marcatore `/usr/local/share/wm-print/version`.
Dettagli in [`docker/render/README.md`](../../docker/render/README.md). Nello shard:

- il compose passa la cartella come build context aggiuntivo (`additional_contexts`) e il Dockerfile
  fa `COPY --from=<nome> install-deps.sh VERSION /tmp/wm-print/` e `RUN bash /tmp/wm-print/install-deps.sh`;
- `docker/render/VERSION` si incrementa quando cambiano le dipendenze: `XelatexCompiler` confronta il
  marcatore con `deps_version` e fallisce con «immagine non allineata al package» se differiscono
  (serve quando un deploy aggiorna il submodule senza ricostruire l'immagine: lo shard dovrebbe
  ricostruirla nel deploy quando cambia il puntatore);
- la coda `print` (o il valore di `WM_PRINT_QUEUE`) **va dichiarata nello shard** come supervisor di
  Horizon, con pochi worker (il picco di `xelatex` con le foto è alto);
- `retry_after` della connessione Redis deve essere **maggiore del timeout dei Job**
  (`GeneratePrintPdfJob::computeTimeout()`, 390 s con i default, per la scheda; 300 per ZIP e mappa
  d'insieme), altrimenti un Job in corso
  viene rilanciato;

## Sicurezza

- Il testo degli editor non diventa mai comandi: `LatexText::escape()` per il testo semplice,
  `LatexText::fromHtml()` per l'HTML (escape di ogni nodo di testo, comandi emessi solo da un elenco
  chiuso di tag);
- `xelatex` gira con `-no-shell-escape`, `-halt-on-error` e `openin_any=p` / `openout_any=p` (solo la
  cartella di lavoro), sotto `/usr/bin/timeout` e `/usr/bin/prlimit --as=` (`compile.timeout`,
  `compile.memory_mb`): il limite di memoria di Horizon non conta i processi figli;
- la lingua è validata con `PrintService::LOCALE_PATTERN` prima di finire nel nome del file e nella
  view, e `lang` contro le lingue dell'app;
- all'utente arriva sempre il messaggio generico «PDF generation failed»; il dettaglio
  (percorsi, coda del log di `xelatex`) resta nel log `print`;
- le foto si scaricano a blocchi con tetto di byte e pixel e si ridimensionano (lato lungo 2000 px)
  prima della compilazione;
- il tempo di una foto è limitato sull'**intero download**, non sulla singola lettura: la richiesta è
  in streaming e Guzzle usa lo StreamHandler (nel container c'è anche curl, ma `stream` sceglie lo
  StreamHandler), dove `timeout` vale per ogni lettura, quindi un server che manda un byte ogni 29 s
  lo rispetterebbe all'infinito. `PrintService::preparePhoto()` riceve una scadenza (residuo del
  budget, mai oltre 30 s), usa il tempo che resta come `timeout` della richiesta, stacca il resource
  del corpo e prima di ogni lettura ricontrolla la scadenza e imposta `stream_set_timeout()` al tempo
  che resta. Oltre la scadenza la foto si salta con un warning, come per i limiti di byte e pixel.

## Trappole

- Le rotte del dominio si caricano al boot: un test che cambia `features.print.enabled` deve
  richiamare `registerEnabledDomains` del service provider con reflection (vedi `tests/Feature/Print/PrintRoutesTest.php`).
- I test di compilazione (`XelatexCompilerTest` e quelli che compilano) si saltano con
  `skip(! is_executable('/usr/bin/xelatex'))`: succede solo dove `xelatex` manca (per esempio in CI).
- La rotta della scheda accoda un Job quando il PDF manca: il sito deve mettere `rel="nofollow"` sul
  link «Stampa» e un `Disallow` per `/print/` in `robots.txt`, o un crawler accoda una compilazione per
  ogni scheda.
- Con `map_service.driver=fake` i PDF hanno la mappa segnaposto: in produzione serve `http` e oc:9011.
