> Ticket: oc:8953

# [WM-PACKAGE] il download GPX e KML delle tracce risponde sempre con un errore

## Cosa cambia

Le rotte GPX e KML di `EditorialContentController` smettono di rispondere HTTP 500 e restituiscono
un **documento completo e valido**, che le app GPS e Google Earth aprono:

| Rotta | Oggi | Dopo |
|---|---|---|
| `/api/ec/track/download/{id}.gpx`, `/api/ec/track/{id}.gpx` | 500 | GPX 1.1: un `<trk>` con un `<trkseg>` per ogni linea, quota in `<ele>` |
| `/api/ec/track/download/{id}.kml`, `/api/ec/track/{id}.kml` | 500 | KML 2.2: un `<Placemark>` con una `<MultiGeometry>` di `<LineString>`, quota nelle `<coordinates>` |
| `/api/ec/poi/download/{id}.gpx`, `/api/ec/poi/{id}.gpx` | 500 | GPX 1.1: un `<wpt>` con quota e nome |
| `/api/ec/poi/download/{id}.kml`, `/api/ec/poi/{id}.kml` | 500 | KML 2.2: un `<Placemark>` con un `<Point>` |

Le rotte `download/` restituiscono il file come allegato, con il nome del contenuto come nome del
file. Le rotte senza `download/` restituiscono lo stesso documento senza `Content-Disposition`: con
questi `Content-Type` il browser di solito lo scarica comunque, con il nome preso dall'URL
(`103.gpx`).

Il codice è tutto nel `wm-package`. Forestas riceve il fix con l'aggiornamento del puntatore del
submodule, dopo il merge nel package.

## Perché

Il nuovo sito di Sardegna Sentieri deve offrire il download della traccia di ogni sentiero
leggendola dallo shard forestas. Oggi il sito Drupal serve un file GPX caricato a mano, che sparirà
con lo spegnimento di Drupal; riparare la generazione copre anche i sentieri creati in Nova dopo lo
spegnimento, senza copiare gli 815 file di Drupal.

Le rotte non hanno mai funzionato, per tre cause verificate sul codice e nel log locale del
09/11/2026:

1. **Le macro di risposta `gpx` e `kml` non esistono.** Il controller chiama `response()->gpx()` e
   `response()->kml()` (`EditorialContentController.php:114` e `:132`), ma nessuno le registra:
   `Method Illuminate\Routing\ResponseFactory::gpx does not exist`.
2. **La libreria `ndamiens/gisconverter` non produce un documento.** `toGPX()` manca per
   `MultiLineString` (tutte le tracce lo sono, per la migration del package) e lancia
   `UnimplementedMethod`; per le geometrie che gestisce restituisce solo un frammento
   (`<trkseg>…</trkseg>`, `<wpt …></wpt>`, `<MultiGeometry>…</MultiGeometry>`) senza `<gpx>` o
   `<kml>` intorno, e scarta la quota (`Point.php:21-22` tiene solo lon e lat).
3. **`createDownloadFileName()` legge `$headers['Content-Disposition']` senza controllarlo**
   (`EditorialContentController.php:180`). Le rotte senza `download/` gli passano header vuoti, e su
   ogni contenuto con un nome falliscono con `Undefined array key "Content-Disposition"`.

**Perché anche i POI**, che il ticket non nomina: le stesse quattro azioni del controller servono
anche le rotte `/api/ec/poi/…` (il modello si sceglie dall'URL in `_getEcModelFromType()`), e anche
quelle rispondono 500 per le cause 1 e 3 (verificato in locale sul POI 1). Riparare le cause 1 e 3
cambia comunque il comportamento delle rotte dei POI: senza completare anche il loro documento,
passerebbero da 500 a un `<wpt>` senza `<gpx>` intorno, che le app non aprono. Il costo in più è un
ramo per il punto e i casi di test corrispondenti.

## Requisiti

- [ ] **GPX completo.** `<?xml version="1.0" encoding="UTF-8"?>` e radice
  `<gpx version="1.1" creator="Webmapp" xmlns="http://www.topografix.com/GPX/1/1">`.
  - Traccia: un `<trk>` con `<name>` e un `<trkseg>` per ogni linea della `MultiLineString`; ogni
    punto è `<trkpt lat=".." lon=".."><ele>..</ele></trkpt>`.
  - POI: un `<wpt lat=".." lon=".."><ele>..</ele><name>..</name></wpt>` (ordine degli elementi
    imposto dallo schema GPX 1.1).
  - lat, lon ed ele si scrivono sempre in notazione decimale, mai esponenziale (`1.0E-5`), che
    `xsd:decimal` non accetta.
- [ ] **KML completo.** `<?xml version="1.0" encoding="UTF-8"?>`,
  `<kml xmlns="http://www.opengis.net/kml/2.2"><Document><name>..</name><Placemark><name>..</name>`
  con, per la traccia, una `<MultiGeometry>` di `<LineString><coordinates>lon,lat,ele …</coordinates>`
  e, per il POI, un `<Point><coordinates>lon,lat,ele</coordinates>`.
- [ ] **Quota.** I punti si costruiscono dalle coordinate di `ST_AsGeoJSON(geometry)`, che conserva
  la Z, e non dalla libreria, che la scarta. **Una Z uguale a 0 vale come quota mancante**, come una Z
  assente: `<ele>` si omette nel GPX e nel KML si scrivono solo lon e lat. Sul DB locale di forestas
  la Z vale 0 su tutti i 926 POI e su parte dei punti di 36 tracce (la 24 ha 1713 punti a 0 su 4810,
  la 427 li ha tutti): scriverla produrrebbe profili con salti fino al livello del mare. Un punto
  davvero a 0 m perde la quota, caso raro e innocuo. Nel KML la stessa `<coordinates>` può quindi
  contenere tuple a 2 e a 3 valori, cosa che lo schema consente; senza `<altitudeMode>` Google
  Earth appoggia comunque la linea al suolo, ed è voluto: con quote parziali una linea sospesa
  sarebbe peggio.
- [ ] **Nome.** `<name>` e nome del file vengono da `$ec->name`, cioè nella lingua dello shard
  (`APP_LOCALE`; su forestas italiano). Il testo è codificato per l'XML (un `&` in un nome non deve
  rompere il documento). Con un nome vuoto il file si chiama `{id}.gpx` / `{id}.kml`, come fa già
  il codice attuale.
- [ ] **Contenuto senza geometria → 404.** Se `getModelGeometryAsGeojson()` restituisce `null`
  (geometria assente, o errore di PostGIS), le rotte GPX e KML rispondono
  `{"code": 404, "error": "Geometry not found"}` con stato 404, invece di un 200 con un corpo
  vuoto o con `{}` (`GeometryComputationService.php:352`). Si usa lo stesso meccanismo del
  contenuto inesistente: `Exception(…, 404)` raccolta dal `catch` già presente nelle azioni
  (`EditorialContentController.php:39-42`, `:106-110`), e lo stesso formato JSON usato in
  `AppController.php:69`.
- [ ] **Macro di risposta** `gpx` e `kml` registrate in `WmPackageServiceProvider::boot()`, con
  `Content-Type` `application/gpx+xml` e `application/vnd.google-earth.kml+xml`. Le azioni
  `download` non sovrascrivono più il tipo con `application/xml`.
- [ ] **Nome del file scaricato.** `createDownloadFileName()` non legge `Content-Disposition` se
  l'header non c'è (rotte senza `download/`). Quando c'è, l'header si costruisce con
  `HeaderUtils::makeDisposition()` di Symfony: `filename` in ASCII e `filename*` in UTF-8
  (RFC 6266), così i nomi con accenti o lineette di forestas (139 tracce e 67 POI) arrivano
  integri. `makeDisposition()` lancia un'eccezione se il fallback contiene caratteri non ASCII o
  `%`, o se uno dei due nomi contiene `/` o `\` (`HeaderUtils.php:176-187`): il fallback va
  traslitterato (`Str::ascii()`) e ripulito da `%`, e `/` e `\` vanno sostituiti in entrambi (su
  forestas c'è il POI 540, `…(Via Don Giordi/Via della Musica)`). `createDownloadFileName()` serve
  anche `download/{id}.geojson` (`EditorialContentController.php:94`), che oggi risponde 200: il suo
  `Content-Disposition` cambia nello stesso modo, e la rotta entra nei test come non-regressione.
- [ ] **Test feature** nel package, sulle otto rotte (traccia e POI, GPX e KML, `download/` e
  visualizzazione), più `download/{id}.geojson` come non-regressione. Le geometrie del test sono
  esplicite, con quote fissate, e non quelle casuali delle factory: `EcPoiFactory` oggi mette la
  latitudine fino a ±180 (`EcPoiFactory.php:19-20`) e PostGIS la riporta nel range in silenzio
  (verificato: `POINT Z(10 120 5)` diventa `POINT Z(10 60 5)`), quindi il punto salvato non sarebbe
  quello scritto; e le quote casuali possono valere 0.00. La factory non si tocca in questo ticket,
  e mai si adatta il codice di produzione ai suoi valori.
  - un caso con un punto a Z = 0 verifica che `<ele>` manchi, uno con Z ≠ 0 che ci sia;
  - un caso senza geometria verifica il 404: la colonna `geometry` non è nullable
    (`create_ec_tracks_table.php.stub:22`), quindi il `null` si ottiene sostituendo
    `GeometryComputationService` nel container con un mock;
  - un caso con un nome come `Via Don Giordi/Via della Musica – 100%` verifica `filename` in ASCII
    e `filename*` in UTF-8;
  - risposta 200, `Content-Type` corretto, `Content-Disposition` con il nome sulle rotte `download/`;
  - struttura letta con `DOMDocument`: radice, un `<trkseg>` per ogni linea, `<ele>` su ogni punto
    con Z ≠ 0;
  - il GPX valida contro lo schema XSD di GPX 1.1, salvato fra i fixture dei test perché il test
    non dipenda dalla rete. Il KML si controlla solo nella struttura: lo schema OGC importa altri
    schemi e non è praticabile senza rete.
- [ ] **Prova a mano dopo il deploy su dev**: scaricare da `https://forestas.dev.maphub.it` il GPX
  della traccia 103 e di una traccia con più linee (sul DB locale la 427, 12 linee, o la 779, 3
  linee; gli id vanno ricontrollati su dev) e aprirli in un'app vera (OsmAnd o gpx.studio), per
  cogliere ciò che lo schema non vede, come lat e lon invertite. Il KML della traccia 103 si apre in
  Google Earth (va bene la versione web), perché per il KML non c'è validazione con lo schema.

## Rischi

- **Le rotte cambiano comportamento su tutti gli shard che montano il package.** Oggi rispondono
  sempre 500, quindi nessun client può dipendere dal comportamento attuale; né forestas né
  `wp-forestas` le chiamano. Gli altri shard non sono stati verificati.
- **Ordine dei merge.** Il fix va mergiato prima nel `wm-package`, poi forestas aggiorna il
  puntatore del submodule, come da regola del package.
- **Tracce molto lunghe.** Il documento si costruisce in memoria come stringa, come fa oggi il
  GeoJSON; non c'è un limite di dimensione. Non risulta un problema con le tracce di forestas, ma non
  è stato misurato sulla traccia più lunga.

## Out of scope

- **Il `ref` nel nome del file.** `createDownloadFileName()` legge `$ec->ref`, che su `EcTrack` non
  esiste (il ref sta in `properties['ref']`, presente su 573 tracce di forestas): il nome del file
  resta quello del contenuto, come oggi.
- **Un parametro per la lingua** (es. `?lang=en`): il nome resta nella lingua dello shard.
- **Quote ricalcolate dal DEM** al posto delle Z a 0: è un altro lavoro, e su forestas l'import non
  calcola il DEM per scelta.
- **Confronto con i GPX di Drupal e loro archiviazione prima dello spegnimento**: riguarda la
  dismissione di Drupal (oc:8932), non la riparazione delle rotte.

## Moduli toccati

| File | Repo | Cosa |
|---|---|---|
| `src/Services/GeometryComputationService.php` | `wm-package` | `getModelGeometryAsGpx()` e `getModelGeometryAsKml()` costruiscono il documento completo dalle coordinate GeoJSON, per `MultiLineString` e `Point` |
| `src/WmPackageServiceProvider.php` | `wm-package` | macro di risposta `gpx` e `kml` |
| `src/Http/Controllers/Api/EditorialContentController.php` | `wm-package` | `createDownloadFileName()` e i `Content-Type` delle azioni `download` |
| `tests/Feature/Api/…` (nuovo) | `wm-package` | test sulle otto rotte e sulla non-regressione di `download/{id}.geojson` |
| `tests/fixtures/…/gpx.xsd` (nuovo) | `wm-package` | schema GPX 1.1 per la validazione offline (cartella in minuscolo: nel container Linux `Fixtures` non la troverebbe) |
| puntatore del submodule `wm-package` | `forestas` | aggiornamento dopo il merge nel package |
