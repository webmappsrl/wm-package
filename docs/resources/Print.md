# Stampa PDF delle schede

> Introdotta con oc:8982. Fonte di verità sulle decisioni:
> [`docs/features/8982-stampa-scheda-pdf-di-sentieri-e-punti-di-interesse-generata-dallo-shard/overview.md`](../features/8982-stampa-scheda-pdf-di-sentieri-e-punti-di-interesse-generata-dallo-shard/overview.md).

La stampa produce tre file stampabili: la **scheda PDF** di un `EcTrack` o di un `EcPoi` in una
lingua, lo **ZIP delle schede** di più `EcTrack`, la **mappa d'insieme** in PDF di più `EcTrack`. Il
PDF si compone in LaTeX (`xelatex` nell'immagine PHP dello shard); la mappa è un PNG chiesto a un
servizio di stampa di wm-tiles (oc:9011). Non è un dominio opzionale (scrum del 24/11): il codice c'è su ogni
shard che monta il package, e la stampa vale solo per gli elementi di un'App con l'opzione accesa (vedi
«L'opzione dell'App»). Fino al 24/11 c'era anche un interruttore per shard, `WM_PRINT_ENABLED`, tolto.

## Configurazione

`config/wm-package.php`, sezione `print`:

| Chiave | Env | Default |
|---|---|---|
| `queue` | `WM_PRINT_QUEUE` | `print` |
| `map_service.driver` | `WM_PRINT_MAP_DRIVER` | `fake` (l'altro valore è `http`) |
| `map_service.url` | `WM_PRINT_MAP_SERVICE_URL` | `null` |
| `map_service.token` | `WM_PRINT_MAP_SERVICE_TOKEN` | `null` |
| `map_service.timeout` | | 60 s: oltre, il Job si rimette in coda |
| `map_service.dpi` | | 300 |
| `map_service.style_id` | | `webmapp` (l'unico stile del servizio) |
| `map_service.map_info` | | `true`: scala, nord e attribuzioni disegnati sulla mappa |
| `map_service.stroke_width` | | 4 (pixel css, il servizio li moltiplica per la stampa) |
| `map_service.stroke_color` | `WM_PRINT_MAP_STROKE_COLOR` | `null`. Un `#rrggbb` vale per tutte le tracce dello shard e vince sul colore del layer |
| `map_service.max_zoom` | | `null`: quello del servizio, 16. Ferma l'ingrandimento su una traccia corta o un POI: su un A4 un sentiero di 1 km esce a 1:21.500 con 16, a 1:5.400 con 18 |
| `map_service.padding_mm` | | `null`: quello del servizio, 8 mm di carta (convertiti in pixel al dpi chiesto) |
| `compile.timeout` | | 120 s |
| `compile.memory_mb` | | 1024 |
| `photos.max_bytes`, `photos.max_pixels` | | 30 MB, 50.000.000 px (oltre, la foto si salta) |
| `photos.total_timeout` | | 60 s: budget del download di tutte le foto di una scheda (esaurito, le restanti si saltano con un warning); ogni foto ha una scadenza sull'intero download pari al residuo, mai oltre 30 s |
| `map.width_mm`, `map.height_mm` | | 210, 297: dimensioni (mm) della mappa della scheda, chieste al servizio mappe e passate alla view (`$mapWidthMm`, `$mapHeightMm`). Di serie è la pagina 1 intera |
| `map.poi_max_zoom` | | 18: zoom massimo della mappa della scheda di un POI, che senza resterebbe a 16 (circa 1:21.000 su A4). Vince su `map_service.max_zoom`; `null` lascia quello |
| `map.padding_mm` | | 42: margine attorno alla traccia nella sola mappa della scheda, che la tiene fuori dal riquadro del titolo della copertina. Vince su `map_service.padding_mm`; `null` lascia quello |
| `zip.max_tracks` | | 50 |
| `zip.max_wait_seconds` | | 1800 |
| `overview_map.max_scale` | | 50000 (1:50.000) |
| `deps_version` | | contenuto di `docker/render/VERSION`, `null` se il file non c'è |

Su ogni shard ci sono:

- le rotte di `routes/print.php`, caricate al boot da `WmPackageServiceProvider`:

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
- la data di generazione nel piè di pagina (`$generatedOn`, `PrintService::generatedOn()`).

Nel JSON della traccia e del POI non c'è il link alla scheda (scrum del 24/11): frontend e plugin
WordPress mostrano il pulsante se `OPTIONS.ec_pdf_enabled` del `config.json` è acceso, e
costruiscono da sé l'URL della rotta, `<shard>/print/ec-track/{id}` o `<shard>/print/ec-poi/{id}`.
Così accendere o spegnere l'opzione non chiede di riscrivere su `wmfe` il JSON di tutte le tracce.

### L'opzione dell'App

La stampa vale per un elemento solo se la sua App la accende: `properties.ec_pdf_enabled` dell'App, campo «Print PDF»
nel tab `frontend` della Resource `App`, spenta se manca. Il controllo è uno,
`PrintService::enabledFor($model)`, e vale per tracce e POI.

Con l'opzione spenta non esiste nulla per gli elementi di quell'App:

| Punto | Comportamento |
|---|---|
| `GeneratePrintPdfJob::dispatchFor()` | non accoda nulla (è l'unico ingresso dei Job di generazione) |
| `print.show`, `print.status` | 404, come per un id inesistente |
| `print.zip`, `print.overview-map` | le tracce dell'App si scartano; 404 se non ne resta nessuna |
| Action Nova | non eseguibile su quegli elementi (`canRun()`) |
| `config.json` | `OPTIONS.ec_pdf_enabled` (e la deprecata `print_track_enable`) è `false`: il frontend nasconde il pulsante |

Su Geohub l'opzione è la colonna `apps.print_track_enable` («Print Track»). Qui è una property, come
le opzioni recenti: la colonna `print_track_enable` esiste ancora su `apps` ma non si usa. L'import
porta il valore di Geohub in `properties.ec_pdf_enabled` (`ImportedAppProperties`, rinomina
dichiarata). Nel `config.json` la chiave è `OPTIONS.ec_pdf_enabled`; c'è anche
`OPTIONS.print_track_enable`, **deprecata**, con lo stesso valore: la leggono la web app e l'app, ferme alla
chiave di Geohub, e si toglie quando sono passate alla nuova. Il flag per singola traccia di Geohub (`allow_print_pdf`,
link «Print PDF» in `related_url`) non è usato: la scheda vale per tutti gli elementi dell'App.

Uno shard che passa a questa versione del package deve accendere l'opzione sulle App che stampavano
già, altrimenti la stampa si spegne.

**Prima di accendere l'opzione** lo shard deve essere pronto, perché nessun interruttore lo verifica:

1. TeX Live nell'immagine PHP: nel Dockerfile dello shard `COPY --from=wmpackage install-deps.sh VERSION
   /tmp/wm-print/` e `RUN bash /tmp/wm-print/install-deps.sh`, con il contesto `wmpackage` verso
   `wm-package/docker/render` nel compose (`docker/render/README.md`). Senza, la compilazione fallisce;
2. il worker della coda `print`: il package lo dichiara in `config/wm-horizon.php` (`supervisor-print`, in
   tutti gli ambienti), che si fonde con il `config/horizon.php` dello shard. Va alzato `retry_after` della
   connessione Redis (`REDIS_QUEUE_RETRY_AFTER`, 660 su Forestas): con i 90 s di serie un PDF lento viene
   riconsegnato mentre è ancora in compilazione;
3. URL e token del servizio mappe nel `.env` (`WM_PRINT_MAP_DRIVER=http`). Senza, la mappa è il segnaposto.

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

**Nome del file.** La chiave su `wmfe` è `print-{locale}.pdf` nella cartella del contenuto
(`printFileName()`): stabile, ci si appoggiano freschezza, rigenerazione e cancellazione. Il nome con cui
il file si scarica è un'altra cosa: lo slug del nome nella lingua della scheda (`printDownloadName()`,
es. `oliena-monte-corrasi-b-401.pdf`), scritto nell'intestazione `Content-Disposition: inline`
dell'oggetto da `StorageService::storeFileContents()`. Lo spostamento dal temporaneo è una copia S3
che conserva i metadati, verificato su MinIO.

**I termini contano come modifiche.** La freschezza guarda `updated_at`, ma un termine aggiunto o
tolto (validazione e percorribilità da `TrailStatusService::set()`, termini salvati in Nova) si scrive
solo nel pivot. `TaxonomyTermableObserver` aggiorna `updated_at` di tracce e POI
a ogni legame che cambia, con una UPDATE senza observer, anche dentro un import: la scheda risulta
vecchia e la richiesta successiva la rigenera.

**Unicità e sovrapposizioni della scheda.** `GeneratePrintPdfJob` è `ShouldBeUniqueUntilProcessing`:
il lock di unicità vale mentre il Job è in coda e si libera quando l'esecuzione parte, così la
generazione si può riaccodare mentre è in corso. Un salvataggio che arriva durante la generazione
(dati letti prima, PDF scritto dopo) lascerebbe un PDF vecchio scritto dopo `updated_at`, cioè fresco
finché qualcuno non salva di nuovo, perché le schede si rigenerano solo a richiesta. Per questo
il Job confronta `updated_at` prima e dopo la generazione e, se è cambiato, si riaccoda invece di
segnare `done`. Due esecuzioni per lo stesso modello e lingua non
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

**Rigenerazione solo a richiesta** (scrum del 24/11). Un salvataggio non rigenera nulla: sposta
`updated_at`, la scheda già scritta non risulta più fresca (`isFresh()`) e si rifà alla prossima
richiesta. Così una traccia modificata dieci volte di seguito non produce dieci generazioni, e una
scheda che nessuno chiede più non costa nulla. Non si distingue fra mappa e dati: la mappa non si
conserva a parte, e a ogni generazione si rifanno entrambe.

Il limite: conta solo `updated_at` dell'elemento (più i termini, sopra). Una modifica che non lo
sposta, per esempio un POI collegato a una traccia che cambia nome, non rende vecchia la scheda della
traccia: resta quella di prima finché la traccia non cambia o non si rigenera con l'Action Nova.

La cancellazione dell'elemento cancella i PDF con la cartella dei file del modello.

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
(`ScheduleServiceProvider`, `daily()`): lo shard non deve fare nulla.

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
  Serve allo sviluppo e ai test;
- `http`: `HttpMapRenderer`, il servizio di stampa di wm-tiles (oc:9011). `map_service.url` è
  `https://origine.tiles.maphub.it/v1/print/map`, il token è uno per shard e lo dà chi gestisce wm-tiles.
  Documentazione e schema del servizio, aperti senza token: `/v1/print/docs` e `/v1/print/openapi.json` sullo
  stesso host. Lo schema è la validazione del servizio: un campo non previsto è un `400`.

La richiesta è `MapRenderRequest`: `feature_collection` (una feature per sentiero o punto) e l'area in mm in
cui la mappa va nel PDF. `toServiceBody()` la traduce nel corpo del servizio:

- **la pagina**: il servizio rende solo pagine intere A5 o A4. L'orientamento segue l'area, il formato è il più
  piccolo che la contiene (`page()`): 210 x 297 → A4 verticale (scheda, di serie), 174 x 98 → A5
  orizzontale, 200 x 170 → A4 orizzontale (mappa d'insieme). La view la mette nell'area con `keepaspectratio`:
  dove le proporzioni non coincidono resta un margine bianco;
- **il disegno**: `stroke` e `stroke-width` nelle `properties` di ogni feature (`PrintService::mapStroke()`).
  Il colore è, in quest'ordine: `map_service.stroke_color` dello shard; il colore del layer della traccia (il
  primo per rank, `EcTrack::getInheritedTrackColorHex()`, lo stesso che usano le app); `EcTrack::DEFAULT_COLOR_HEX`
  (`#FF0000`) se la traccia non ha un layer con un colore, e per i POI. Nome, id e `ref` restano nella richiesta per il renderer finto, ma al servizio
  non arrivano: le tracce non pubblicate non escono dallo shard con il loro nome;
- **le coordinate** perdono la quota e si arrotondano a 6 decimali (circa 10 cm): il servizio rifiuta un
  corpo oltre 2 MiB e oltre 100.000 coordinate.

La scala non si chiede: esce dall'inquadratura (un sentiero di 10 km su un A4 viene circa 1:35.000).

**Servizio occupato.** Due rendering alla volta, 4-6 s ciascuno per un A4 a 300 dpi. `429`, `503`, `504` e un
timeout danno `MapServiceBusyException` con il `Retry-After` del servizio (30 s se manca): `GeneratePrintPdfJob`
e `BuildOverviewMapJob` si rimettono in coda con `release()`, senza fallire, e lo stato resta `queued`. Ogni
altro errore (`400`, `401`, `413`, un corpo che non è un PNG) è un difetto della richiesta o della
configurazione: la scheda non si genera e il PDF esistente resta.

## Personalizzazione per shard e per App

**Impaginazione.** Ogni view è un sorgente LaTeX in Blade: `print/ec-track`, `print/ec-poi`,
`print/overview-map` (più `print/waiting`, `print/error` e `print/partials/footer`). Lo shard le
sostituisce con una view omonima in `resources/views/vendor/wm-package/print/`. **Il contratto
(le variabili ricevute e quali sono già escaped) è nel docblock in testa a ogni view**: lì va letto,
non qui. Le stringhe già LaTeX-sicure si stampano con `{!! !!}`; in LaTeX non si scrive `{{` letterale.
Le etichette fisse vengono da `resources/lang/{de,en,es,fr,it}.json` (chiave in inglese), che lo shard
può sovrascrivere.

**View per singola App.** Sopra la view dello shard ce n'è una per App, che la sovrascrive solo per i
contenuti di quell'App: `resources/views/vendor/wm-package/print/apps/<app_id>/ec-track.blade.php` (e
`ec-poi`). `Printable::printView()` sceglie il primo livello che esiste: la view dell'App, poi quella
dello shard (`print/ec-track`), poi quella del package. La view dell'App riceve le stesse variabili e
può includere i partial dello shard, cambiando solo una parte. La chiave è l'id dell'App, che negli
shard con più App importate può cambiare fra un ambiente e l'altro: va verificato prima di aggiungere
una cartella. La mappa d'insieme non ha la view per App, perché una selezione può contenere sentieri
di App diverse. Cambiare una view non rende vecchi i PDF già generati (la freschezza guarda i dati):
dopo il deploy si rigenerano con l'Action Nova.

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

**Impaginazione di default.** Scheda di traccia e POI su due pagine (24/11):

- **pagina 1, copertina**: la mappa a tutta pagina, A4 al vivo, come sfondo (`\WmCopertina` di
  `wm-print.sty`, con `eso-pic`); sopra, in alto a sinistra, un riquadro bianco con logo e titolo
  (`\WmLogoTitolo`). Il riquadro lascia libero l'angolo in alto a destra, dove il servizio disegna il nord, e
  il fondo, dove mette scala e attribuzioni. La traccia non ci finisce sotto perché la mappa è chiesta con
  `map.padding_mm` (42 mm) di margine, più alto del riquadro. Niente piè di pagina;
- **pagina 2**: il titolo ripetuto in piccolo (`\TitoloPagina`), dati, descrizione, punti di interesse e foto
  (due per riga), con il piè di pagina e la data.

Senza mappa la scheda parte dalla pagina 1 con logo e titolo in testa, come prima. La scheda di default
non ha un numero massimo di pagine: il contenuto scorre, e un titolo di sezione (`\TitoloSezione`) non resta
mai da solo in fondo a una pagina con il contenuto nella successiva (`\needspace` prima, `\nobreak` e
`\@afterheading` dopo, che valgono anche prima di un elenco o di una foto).

**Dimensioni della mappa.** `print.map.width_mm` e `height_mm` (default 210 x 297) vanno sia al servizio
mappe sia alla view (`$mapWidthMm`, `$mapHeightMm`): uno shard che vuole un'altra impaginazione chiede la
mappa della misura che gli serve. La view di default non le usa, perché la copertina è sempre la pagina
intera. La mappa d'insieme ha un'area propria (200 x 170 mm) e non usa `map.padding_mm`.

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
- la coda `print` (o il valore di `WM_PRINT_QUEUE`) ha il suo supervisor in `config/wm-horizon.php` del
  package, con pochi worker (il picco di `xelatex` con le foto è alto); se lo shard cambia il nome della
  coda, deve dichiarare il supervisor da sé;
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
  lo rispetterebbe all'infinito. `PrintImages::preparePhoto()` riceve una scadenza (residuo del
  budget, mai oltre 30 s), usa il tempo che resta come `timeout` della richiesta, stacca il resource
  del corpo e prima di ogni lettura ricontrolla la scadenza e imposta `stream_set_timeout()` al tempo
  che resta. Oltre la scadenza la foto si salta con un warning, come per i limiti di byte e pixel.

## Trappole

- In `wm-print.sty` `@` è già una lettera: non scrivere `\makeatother`, che la toglie alle macro definite
  dopo. Una macro con `\@afterheading` definita sotto un `\makeatother` si legge come `\@` seguito da testo,
  e la compilazione fallisce con «You can't use `\spacefactor' in vertical mode».
- Un test della stampa deve creare l'App con `properties.ec_pdf_enabled` acceso (`printApp()` in
  `tests/Support/PrintHelpers.php`): altrimenti
  `PrintService::enabledFor()` è falso, la rotta risponde 404 e nessun Job parte, senza errori.
- Nella richiesta di un'Action Nova il `MultiSelect` è una stringa JSON, decodificata solo nel
  fill: una regola `array` sul campo fallisce sempre («Il campo Lingue deve essere una lista»).
  `GeneratePrintPdfAction` valida con `json` e una closure; il test usa `validateFields()` con il
  payload come lo manda Nova.
- I test di compilazione (`XelatexCompilerTest` e quelli che compilano) si saltano con
  `skip(! is_executable('/usr/bin/xelatex'))`: succede solo dove `xelatex` manca (per esempio in CI).
- La rotta della scheda accoda un Job quando il PDF manca: il sito deve mettere `rel="nofollow"` sul
  link «Stampa» e un `Disallow` per `/print/` in `robots.txt`, o un crawler accoda una compilazione per
  ogni scheda.
- Con `map_service.driver=fake` i PDF hanno la mappa segnaposto: in produzione serve `http`, con URL e token.
