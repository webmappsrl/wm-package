> Ticket: oc:8982

# Notes — Stampa PDF di sentieri e POI (package)

Deviazioni dal piano, bug trovati e decisioni prese durante l'esecuzione. Riporta solo lo stato finale:
dove una decisione ne ha sostituita un'altra, c'è una riga «sostituisce».

## Deviazioni dal piano

- **Test HTTP 404/200 del Task 1 spostati al Task 9.** Le rotte nascono al Task 9, prima non c'era nulla da chiamare.
- **`MapRenderer` / `MapRenderRequest` (Task 5).** Task 7 e 14 chiedono la mappa con `MapRenderRequest`, non con parametri sciolti: un solo contratto per scheda e mappa d'insieme.
- **`PrintableModel` (Task 7).** `Model&Printable` non è un tipo PHP valido: interfaccia `Services\Print\Contracts\PrintableModel`, implementata da `EcTrack` ed `EcPoi`.
- **`print_url` del POI allo shard (Task 10).** Il package non ha un endpoint di dettaglio POI (`EcPoiController` ha solo `feature_image` ed export). Tolto da `EcPoiResource` per non gonfiare i POI correlati nel JSON della traccia; lo aggiunge lo shard con `PrintService::sheetUrl('ec-poi', $id)`.
- **ZIP a Job brevi (Task 13).** Il piano generava i PDF in un solo Job lungo. `BuildPrintZipJob` accoda un `GeneratePrintPdfJob` per ogni scheda non fresca e si rilascia (`release`) finché sono tutte fresche, con tetto di tentativi: ogni esecuzione sta sotto `retry_after`.
- **Mappa d'insieme 200×170 mm e legenda (Task 14).** Spec R27 «una pagina sola»: mappa a sinistra, legenda in colonna a destra nella stessa pagina. Stesse costanti (`OVERVIEW_MAP_WIDTH_MM/HEIGHT_MM`) per il controllo di scala e per il render.
- **Correzioni della review finale (C1, I1-I7).**
  - ZIP e mappa d'insieme sotto `getShardBasePath()`, shard nella chiave, cleanup con lo stesso prefisso.
  - `uniqueVia(redis)` sui tre job unici.
  - Race di freschezza: `ShouldBeUniqueUntilProcessing` + `WithoutOverlapping(uniqueId)`, dispatch degli observer `afterCommit`.
  - Modello POI da `config('wm-package.ec_poi_model', EcPoi::class)`.
  - Rotte di stato con throttle `print-status` 60/min, polling a 5 s.
  - Cleanup locale di `print-tmp/*` oltre 24 h.
  - Timeout del Job = 2×compile + mappa + budget foto (download 60 s in tutto), ≤ 600; pagina d'attesa allineata.
  - Seconda passata: deadline del download delle foto (I7, solo corpo della risposta).
- **Stile dello shard caricato dopo `wm-print`.** Il piano faceva sostituire `wm-print.sty` da `style_path`, ma le view usano le macro di base (`\PiePagina` indefinita). Poi **sostituito**: con la revisione del 20/11 (R46-R50, al posto di R31, R33 «stile dal token» e R40-R45) sono tolti `TokensStyleGenerator`, `wm:print-style`, `style_path`, il caricamento di stili dello shard e le fixture dei token. Resta `styleFiles()` che restituisce solo `wm-print` (elenco tenuto per compatibilità con `$styleFiles` nel contratto delle view). Uno shard personalizza con la **sua view**, non con uno stile.
- **Logo dell'App (non nel piano).** Media `icon_small` con ripiego su `icon`, letto dal disco della media library (non via HTTP), ridimensionato a ≤ 800 px; ogni errore dà un warning sul canale `print` e nessun logo, mai una scheda in errore. Non nella mappa d'insieme (le tracce possono essere di App diverse).
- **Colori per ruolo.** `wmtitle`/`wmaccent`/`wmrule`/`wmtext`/`wmmuted`/`wmline` con `\WmDefineRoles`: il tema o la view dello shard definiscono i ruoli, il valore di ripiego va solo a quelli indefiniti. Colori da tab `theme` dell'App (R46), font da «Font Family Content/Header» solo con `\IfFontExistsTF` (R47), mappa da `features.print.map.width_mm/height_mm` (R49).
- **Tema per App scritto e poi tolto (Task 17-18).** Pagina «Tema di stampa» (R40-R45) e `PrintTheme` implementati, poi rimossi (R50). Task 17 completato prima della revisione, Task 18 rimosso, review sospesa. Task 21 chiude la revisione.

## Bug trovati

- **`\@ifundefined{color@x}`**: xcolor registra i colori con il backslash nel nome; il controllo giusto è `\@ifundefined{\string\color@x}` (ora in `wm-print.sty`). Il primo era sempre «definito» e il ripiego non scattava.
- **Test tautologico sul colore primario** (Task 17): passava per qualunque valore; corretto con un colore distinto (`1565C0`).
- **`style_path` sostituiva `wm-print.sty`** → `Undefined control sequence \PiePagina` (trovato dal Task 3 di forestas).
- **Task 4:** `\endgroup@if` — `@` attaccato a una lettera non è direttiva Blade, il codice Blade finiva nel LaTeX. Corretto con `\endgroup{}@if`; il test controlla che nel `.tex` non resti nessuna direttiva.
- **`EcPoiServiceUpdateDataChainTest`** fallisce per `App::factory()->create()` senza `createQuietly` (l'`AppObserver` scrive su S3 senza region): preesistente.
- **`PrintService.php:340`** con `properties.ref` array → «Array to string conversion» (trovato dal Task 6 di forestas). Oggi `ref` è sempre stringa.
- Verificati con xelatex reale: 151 test Print al Task 16, 175 a fine lavoro.

## Decisioni con il dev e rischi accettati

- **19/11** — Correggere solo I7 della re-review. N1 (modifica durante il primo Job non riaccodata) resta **rischio accettato**.
- **19/11** — Header lenti, redirect e gzip nel download foto: **rischio accettato** per ora.
- **19/11** — Le customizzazioni dello shard non stanno nel package. Fixture e nomi nei test con dati Forestas vanno bene; i commenti «660 s su forestas» in `src` restano (solo testo).
- **19/11** — Logo dell'App nella scheda di default in questo ticket.
- **20/11** — Approvata la revisione R46-R50 (package) e R16-R19 (forestas): niente token, tema dell'App, view dello shard dal design system. Sostituisce la decisione del 19/11 su pagina del tema e anteprima.
- **Parcheggiati (ancora validi):**
  - Legenda della mappa d'insieme in `\scriptsize`, sotto i 9 pt di R35 (R35 vale per il corpo del testo).
  - N2: `show` durante una generazione accoda un secondo Job (doppio xelatex).
  - N3: la pagina d'attesa (450 s) può dire «ci mette più del previsto» per un Job in attesa di sovrapposizione.
  - N4: `computeTimeout()` senza tetto rispetto a `retry_after`; conta solo se uno shard alza `compile.timeout` oltre ~250 s.
  - N5: `Print.md` descrive in modo impreciso il rischio della catena (`EcTrackObserver` è già `afterCommit`): da correggere.
  - Fuori scope: chiavi divergenti con un modello POI sottoclasse; `shard_name` vuoto porta `print-tmp` alla radice del bucket.
  - Default senza stile dello shard in Latin Modern Sans perché «TeX Gyre Heros» non si trova per nome nel container (solo estetico).

## Aperti

- **Chiavi di traduzione generiche** (`Description`, `Length`…) cambiano le etichette Nova anche a dominio spento: decide il dev.
- **Chi può lanciare l'Action Nova di generazione:** oggi chiunque acceda alla Resource, come le altre action del package.
- **Ticket a parte non più necessario:** la pagina del tema di stampa (tokens + ruoli per App, sezione Nova «Files» accanto a Icons) è **abbandonata** con la revisione del 20/11.

## Allineamento con develop (23/11)

- Develop ha separato le suite: `vendor/bin/pest` in CI lancia solo «package» (Testbench); i test con
  `Tests\TestCase` (il TestCase di uno shard) vanno nella suite «shard». I 16 file di test della stampa usavano
  `Tests\TestCase` e avrebbero fatto morire la suite in caricamento: ora usano il TestCase del package, che
  `tests/Pest.php` applica a tutta la cartella. Girano tutti sotto Testbench (175 su 175, otto giri con semi
  diversi), quindi non serve aggiungerli all'elenco «shard».
- `PrintZipTest` registrava `wm:print-cleanup` con `Application::starting()`, che sotto Testbench non scatta
  (console già partita): ora con `Artisan::registerCommand()`. Senza, la pulizia non girava e lasciava in
  `print-tmp` una cartella che faceva fallire `OverviewMapTest` nell'ordine casuale.

## Review del 23/11: due bloccanti corretti

- **Un cambio di termine non invalidava la scheda.** `isFresh()` guarda `updated_at`, ma validazione e
  percorribilità (`TrailStatusService::set()`) e i termini salvati in Nova scrivono solo il pivot: la
  scheda con lo stato vecchio restava servita. Ora `TaxonomyTermableObserver::touchForPrint()`, con il
  dominio acceso, aggiorna `updated_at` del contenuto con una UPDATE senza observer. Costo: una UPDATE
  per legame anche negli import (alcune decine di migliaia nel reset notturno di forestas).
- **Un salvataggio durante la generazione lasciava un PDF vecchio «fresco».** La garanzia descritta nel
  docblock del Job non reggeva: alla prima generazione `RegeneratePrintPdfsJob` non rifà niente, e sulle
  tracce degli shard l'observer del modello del package non scatta. Ora `GeneratePrintPdfJob` confronta
  `updated_at` prima e dopo `generate()` e si riaccoda invece di segnare `done`. Resta il caso del
  salvataggio nello stesso secondo della lettura (timestamp al secondo): non coperto.
- **Nome del file scaricato (dev, 23/11):** lo slug del nome tradotto, senza lingua né id, in
  `Content-Disposition`; la chiave su wmfe resta `print-{locale}.pdf`. Nuovo metodo
  `printDownloadName()` in `PrintableModel` e nel trait `Printable`.

## Pulizia dopo la review del 23/11

- Foto e logo della scheda passano in `PrintImages` (`preparePhotos()`, `prepareLogo()`), con il controllo
  dei limiti e il ridimensionamento comuni in `resizeTo()`; `PrintService` lo riceve nel costruttore con un
  default, ed è sceso da 688 a 447 righe. Le costanti restano su `PrintService`.
- Le tracce dei test della stampa vengono da `tests/Support/PrintHelpers.php` (`printUnsavedTrack()`,
  `printSavedTrack()`), caricato da `tests/Pest.php`, al posto di cinque helper con nomi diversi.
- **View per singola App (dev, 23/11):** `printView()` cerca prima `print/apps/<app_id>/<tipo>`, poi la
  view dello shard, poi quella del package. Chiave scelta dal dev: l'id dell'App (lo `sku` avrebbe
  i punti, che per Laravel separano le cartelle).


## Terzo giro (scrum del 24/11)

- **L'action Nova falliva sempre con «Il campo Lingue deve essere una lista».** Nella richiesta di
  un'action il `MultiSelect` è una stringa JSON, decodificata da Nova solo nel fill
  (`MultiSelect::fillAttributeFromRequest()`): la regola `array` sul valore grezzo non passava mai.
  Il test chiamava `handle()` direttamente e saltava la validazione. Ora le regole sono `json` e una
  closure (lista non vuota), con un test su `validateFields()` e il payload di Nova. Trovato da
  Giuseppe provando il rilascio su dev.
- **La stampa è un'opzione della singola App (dev e Giuseppe, 24/11).** Si riusa la colonna
  `apps.print_track_enable` di Geohub (campo «Print PDF» nel tab `frontend`), che vale per tracce e
  POI: un solo controllo, `PrintService::enabledFor()`, usato da `dispatchFor()`, dalla
  rigenerazione, dalle rotte, da `sheetUrl()` (ora riceve il modello) e dal `canRun()` dell'action.
  Il frontend nasconde già il pulsante con `OPTIONS.print_track_enable`. Il flag per traccia di
  Geohub (`allow_print_pdf`) non si usa. L'import da Geohub porta già la colonna (copia per schema
  in `ImportAppJob::transformData()`), con un test che lo verifica.
- Il link del frontend Webmapp punta ancora a `geohub.webmapp.it/track/pdf/{id}`: deve leggere
  `properties.print_url`. È una modifica di `wm-core`, fuori da questo ticket.

## Quarto giro (scrum del 24/11, 12:48)

Decisioni della call, applicate:

- **`print_url` esce dal JSON** di traccia e POI, e `PrintService::sheetUrl()` è tolto. Frontend e plugin
  WordPress mostrano il pulsante se `OPTIONS.print_track_enable` è acceso e costruiscono l'URL dalla rotta
  `print.show`: accendere o spegnere l'opzione non chiede più di riscrivere su wmfe il JSON di tutte le tracce.
- **L'opzione dell'App va in `properties.ec_pdf_enabled`**, non nella colonna `print_track_enable` («le ultime
  sono tutte property»). Vale per tracce e POI; il flag per traccia di Geohub (`allow_print_pdf`) si ignora e
  Geohub non si tocca. Nel `config.json` la chiave resta `OPTIONS.print_track_enable`, che la web app legge
  già. L'import porta la colonna Geohub nella property con una rinomina in `ImportedAppProperties`.
- **Rigenerazione solo a richiesta**: tolto `RegeneratePrintPdfsJob` da `publicationJobs()` e da
  `EcPoiObserver::saved()`, e il job stesso. La freschezza resta `isFresh()` su `updated_at`. Limite
  documentato in `Print.md`: una modifica che non sposta `updated_at` dell'elemento (un POI collegato) non
  rende vecchia la scheda della traccia.
- **Data di generazione nel piè di pagina** (`PrintService::generatedOn()`, `$generatedOn`), data estesa nella
  lingua della scheda.

Verifica su Geohub di produzione, app 33: `OPTIONS.print_track_enable` è `false`; delle 504 tracce dei suoi
layer, 388 hanno `allow_print_pdf` acceso (tutte quelle create prima del 2/6/2023 e 29 dopo) e 116 spento.
Dopo che la web app leggerà solo l'opzione dell'App, su Geohub va accesa quella generica dell'app 33.

Fuori da questo ticket: in `wm-core` la web app deve leggere solo l'opzione dell'App e costruire il link
dalla rotta dello shard; il servizio delle mappe con token e IP è oc:9011.

## Quinto giro: la mappa dal servizio di oc:9011

- `HttpMapRenderer` parla con `POST https://origine.tiles.maphub.it/v1/print/map` (schema `PrintMapRequest`,
  che rifiuta i campi non previsti): corpo da `MapRenderRequest::toServiceBody()`, token Bearer obbligatorio,
  risposta PNG controllata dalla firma.
- Il servizio rende solo pagine intere A5/A4: l'area in mm della mappa si traduce nella pagina più piccola che
  la contiene, con l'orientamento dell'area (`page()`). Forestas (210 x 297) chiede un A4 verticale, esatto.
- Il colore della traccia è il primario dell'App (`mapStroke()`); nome, id e ref non arrivano al servizio.
- `429`/`503`/`504`/timeout → `MapServiceBusyException`, e i due Job si rimettono in coda con il `Retry-After`.
  `BuildOverviewMapJob` passa da `$tries = 1` a 10 per poterlo fare.
- Prova del 24/11 con il servizio vero, traccia 119 di Forestas: A4 verticale 300 dpi in 5,9 s (8,4 MB), A5
  orizzontale in 3,3 s.
- `map_service.max_zoom` e `padding_mm` configurabili (`maxZoom` e `padding` del servizio, il padding da mm a pixel al dpi chiesto). Forestas usa `max_zoom` 18: traccia 119 da 1:21.510 (16) a 1:5.378 (18); 17 dà 1:10.755, 19 dà 1:2.936. Il padding non cambia nulla finché è lo zoom massimo a fermare l'inquadratura.
- Colore della traccia sulla mappa: `map_service.stroke_color` dello shard (`WM_PRINT_MAP_STROKE_COLOR`) vince su tutto, poi il colore del layer (`getInheritedTrackColorHex()`, primo layer per rank), poi `EcTrack::DEFAULT_COLOR_HEX`. Prima era il primario dell'App.

## Sesto giro: niente interruttore per shard

- Tolto `WM_PRINT_ENABLED` (decisione del dev, 24/11): la stampa non è più un dominio opzionale. La configurazione
  passa da `wm-package.features.print.*` a `wm-package.print.*`; rotte (`routes/print.php`, prima
  `routes/domains/print.php`), comando `wm:print-cleanup`, pulizia notturna, action Nova e `touchForPrint()` dei
  termini ci sono su ogni shard. Il gate è solo l'opzione dell'App (`PrintService::enabledFor()`).
- Conseguenza: lo shard che accende l'opzione deve avere TeX Live nell'immagine, il supervisor `print` e il token
  del servizio mappe; nessun controllo lo verifica (elenco in `Print.md`, «L'opzione dell'App»). A oggi solo
  Forestas installa TeX Live: nei repo locali di maphub, camminiditalia e osm2cai2 lo script non c'è.
- `TaxonomyTermableObserver` aggiorna `updated_at` di tracce e POI a ogni cambio di termine su tutti gli shard,
  non più solo dove la stampa era accesa.
- Nel `config.json` l'opzione esce come `OPTIONS.ec_pdf_enabled` e, con lo stesso valore, come `OPTIONS.print_track_enable`, deprecata (`@deprecated` in `AppConfigService`): resta finché web app e app non leggono la nuova.

## Settimo giro: copertina con la mappa nel template di default, prova su camminiditalia

- Template di default di traccia e POI su due pagine: pagina 1 la mappa a tutta pagina (A4 al vivo) con logo e
  titolo in un riquadro bianco in alto a sinistra (`\WmCopertina`, `\WmLogoTitolo` in `wm-print.sty`, con
  `eso-pic`); pagina 2 il resto, foto comprese, con il titolo ripetuto in piccolo. Idea di Alessio, chiesta dal
  dev il 24/11.
- `print.map` passa di serie a 210 x 297 e ha un margine proprio, `print.map.padding_mm` = 42, passato con la
  richiesta (`MapRenderRequest::withPadding()`): tiene la traccia fuori dal riquadro senza toccare la mappa
  d'insieme. Il servizio accetta solo un margine uguale sui quattro lati. Forestas lo azzera (la sua pagina
  della mappa non ha riquadro).
- `supervisor-print` aggiunto a `config/wm-horizon.php`: senza interruttore, gli shard senza un proprio
  `config/horizon.php` (camminiditalia) non avevano un worker per la coda.
- Prova su camminiditalia in locale (traccia 1, «Via Vandelli - Tappa 03»): copertina con la traccia fuori
  dal riquadro, nord e scala liberi, 20 s di generazione con il servizio vero.
- POI: zoom massimo della mappa 18 di serie (`print.map.poi_max_zoom`, passato con la richiesta solo per le
  geometrie `Point`). Mura poligonali di Amelia (camminiditalia, POI 617): da circa 1:21.000 a scala 200 m.
- `\TitoloSezione` non resta solo in fondo a una pagina (`needspace`, `\nobreak`, `\@afterheading`). La scheda
  di default non ha un limite di pagine. Tolto un `\makeatother` a metà di `wm-print.sty`, che rendeva `@` non
  più lettera per le macro sotto.

## Ottavo giro: l'action scarica

- L'action Nova («Download print PDF») non accoda più le generazioni: con un elemento apre in una nuova scheda
  `print.show`, con più elementi `print.zip`. Le pagine generano, aspettano e portano al file. Lingua singola
  (`Select`, proposta la lingua di default dell'App): ogni esecuzione apre una pagina sola. Oltre
  `zip.max_tracks` si ferma con un messaggio. Decisione del dev, 24/11.
- Lo ZIP accetta anche i POI (`?type=ec-poi`, `BuildPrintZipJob` con il tipo, `PrintService::modelClass()`); la
  chiave delle tracce non cambia. Prova sulla Forestas locale: ZIP dei POI 1 e 2 con le due schede.
- Conseguenza: l'action non forza più la rigenerazione di una scheda fresca (per esempio dopo un cambio di logo).
- La pagina d'attesa dello ZIP non restava aperta sull'attesa: ora avvia il download, dice che si può chiudere e prova a chiudersi (`$download` della view `print/waiting`).

## Nono giro: opzione solo al superAdmin, action solo dove la stampa è accesa (25/11)

Richiesta del dev dopo il merge di #347, fatta sullo stesso ticket (terzo merge di oc:8982, scelta del
dev).

- L'opzione «Print PDF» della Resource App (`properties->ec_pdf_enabled`) la vede e la modifica solo
  il superAdmin (`RolesAndPermissionsService::allows()`, email in `wm-package.super_admin_emails`): il
  campo è in `App::printPdfField()` con `canSee`, e Nova non salva un campo non visibile.
- L'action «Download print PDF» si vede nel dettaglio solo se l'App dell'elemento ha la stampa accesa,
  nell'index solo se almeno un'App ce l'ha; `canRun` per elemento resta. Per riconoscere l'elemento
  aperto l'action riceve il modello della Resource: `new GeneratePrintPdfAction(static::$model)` in
  `EcTrack` ed `EcPoi`.
- Test: `tests/Feature/Print/PrintVisibilityTest.php` (3). Il campo sta in un metodo suo anche perché
  `app_tab()` costruisce un `Multiselect` che nel `vendor` del package non c'è, e il test non potrebbe
  leggerlo.
