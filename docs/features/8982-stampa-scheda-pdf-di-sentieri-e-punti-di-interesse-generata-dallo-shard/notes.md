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

