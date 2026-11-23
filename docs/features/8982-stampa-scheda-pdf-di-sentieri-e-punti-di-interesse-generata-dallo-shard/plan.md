> Ticket: oc:8982

# Stampa PDF di sentieri e punti di interesse — piano del package

> **Per chi esegue:** sotto-skill richiesta `superpowers:subagent-driven-development` (consigliata)
> oppure `superpowers:executing-plans`. Gli step usano le checkbox (`- [ ]`). **Nessun commit
> automatico**: gli step «Commit» sono istruzioni per il dev, da eseguire solo su sua richiesta.

**Obiettivo:** il package produce, per qualunque shard, la scheda PDF di `EcTrack` ed `EcPoi` in una
lingua, lo ZIP delle schede e la mappa d'insieme, composti in LaTeX e tenuti aggiornati.

**Architettura:** un dominio opzionale `print` (meccanismo di `FeaturesService`, come
`trail_registry`). Una view Blade produce il sorgente `.tex`; `xelatex` lo compila nel worker Horizon
dello shard; la mappa arriva come PNG dal servizio di rendering di oc:9011, e finché quel servizio
non c'è si usa un segnaposto. I PDF si salvano su `wmfe` con `StorageService`.

**Stack:** Laravel 12, PHP 8.4, Pest, Horizon, Nova 5, XeLaTeX (`texlive-xetex`), Symfony Process.

**Spec:** [overview.md](overview.md) di questa cartella; per la parte dello shard,
`forestas/docs/features/8982-…/overview.md` e il suo [plan.md](../../../../docs/features/8982-stampa-scheda-pdf-di-sentieri-e-punti-di-interesse-generata-dallo-shard/plan.md).

## Vincoli globali

- Identificatori in inglese; commenti, docblock e documentazione in italiano.
- Etichette in `resources/lang/*.json` (chiave in inglese, voce in `de`, `en`, `es`, `fr`, `it`),
  mai in `lang/`.
- Dominio spento (default): nessuna rotta, nessuna Action, nessun URL nel JSON di dettaglio (R6).
- `xelatex` sempre con `-no-shell-escape -interaction=nonstopmode -halt-on-error`, ambiente
  `openin_any=p`, `openout_any=p`, sotto `timeout` e `prlimit` (R3, R4).
- Nome del file su `wmfe`: `print-{locale}.pdf` nella cartella del modello di
  `StorageService::buildFilePath`, così `deleteModelFiles` lo cancella (R21).
- «Aggiornato» = `lastModified` del file ≥ `updated_at` del modello (R13).
- Mappa: stile di default `https://tiles.maphub.it/style.json`, circa 300 dpi.
- Mappa d'insieme: tetto di scala 1:50.000 (R28).
- Test: leggi prima `wm-package/.claude/rules/test.md`; `Http::fake()` per il servizio delle mappe,
  `Storage::fake('wmfe')` per i file, `Bus::fake()`/`Queue::fake()` per i Job.

## Review Focus

1. Elemento senza descrizione, senza foto o senza geometria: la scheda si genera con le parti presenti (Task 6, 7).
2. Descrizione lunga, oltre una pagina: il PDF va a capo su più pagine, senza errore di compilazione (Task 6).
3. Lingua dell'app senza traduzione per quell'elemento: fallback di `spatie/laravel-translatable`, non campo vuoto (Task 7).
4. Foto in WebP o URL non raggiungibile: la foto si converte o si salta, la scheda esce comunque (Task 7).
5. Servizio delle mappe giù: errore leggibile nella pagina d'attesa, PDF esistente non sovrascritto (Task 5, 7).

---

### Task 1: Dominio opzionale `print`

**Files:**
- Modify: `config/wm-package.php` (chiave `features.print`)
- Create: `routes/domains/print.php`
- Modify: `docs/resources/OptionalDomains.md`
- Test: `tests/Feature/Print/PrintDomainRegistrationTest.php`

**Interfaces:**
- Produces: `config('wm-package.features.print')` con `enabled` (`WM_PRINT_ENABLED`, default
  `false`), `queue` (`WM_PRINT_QUEUE`, default `print`), `map_service.driver` (`WM_PRINT_MAP_DRIVER`, default `fake`), `map_service.url`
  (`WM_PRINT_MAP_SERVICE_URL`), `map_service.token` (`WM_PRINT_MAP_SERVICE_TOKEN`),
  `map_service.timeout` (60), `compile.timeout` (120), `compile.memory_mb` (1024),
  `zip.max_tracks` (50), `overview_map.max_scale` (50000), `deps_version` (stringa attesa dal
  marcatore, Task 15). `FeaturesService::isEnabled('print')`.

- [ ] **Step 1:** test `print routes are not registered when the domain is disabled` (GET
  `/print/ec-track/1` → 404) e `print routes are registered when enabled`.
- [ ] **Step 2:** eseguire `vendor/bin/pest tests/Feature/Print/PrintDomainRegistrationTest.php`, atteso FAIL.
- [ ] **Step 3:** aggiungere la chiave in config e il file di rotte vuoto (riempito al Task 9).
- [ ] **Step 4:** rieseguire, atteso PASS.
- [ ] **Step 5 (dev):** `git commit -m "feat(oc:8982): dominio opzionale print"`

### Task 2: Conversione HTML → LaTeX con escape

**Files:**
- Create: `src/Services/Print/LatexText.php`
- Test: `tests/Unit/Print/LatexTextTest.php`

**Interfaces:**
- Produces: `LatexText::escape(string $text): string`, `LatexText::fromHtml(?string $html): string`.

- [ ] **Step 1:** test con le asserzioni:
  - `escape('50% & #1 $x_y {a} ~ ^ \\')` non contiene nessun carattere speciale non preceduto da escape (`\%`, `\&`, `\#`, `\$`, `\_`, `\{`, `\}`, `\textasciitilde{}`, `\textasciicircum{}`, `\textbackslash{}`);
  - `fromHtml('<p>\\input{/etc/passwd} \\write18{ls}</p>')` contiene `\textbackslash{}input` e `\textbackslash{}write18`;
  - `<p>`, `<br>`, `<strong>`/`<b>`, `<em>`/`<i>`, `<ul>/<ol>/<li>`, `<a href>` (solo testo + URL in `\url{}` con escape) convertiti; tag sconosciuti (`<table>`, `<img>`, `<script>`) rimossi tenendo il testo, `<script>` senza testo;
  - entità HTML decodificate prima dell'escape (`&amp;` → `\&`, `&egrave;` → `è`);
  - `fromHtml(null)` → `''`.
- [ ] **Step 2:** eseguire, atteso FAIL.
- [ ] **Step 3:** implementare con `DOMDocument` (caricato in UTF-8), visita ricorsiva dei nodi; l'escape si applica solo ai nodi di testo.
- [ ] **Step 4:** eseguire, atteso PASS.
- [ ] **Step 5 (dev):** `git commit -m "feat(oc:8982): conversione HTML-LaTeX con escape"`

### Task 3: Generatore dello `.sty` dai token del design system

**Files:**
- Create: `src/Services/Print/TokensStyleGenerator.php`, `src/Commands/GeneratePrintStyleCommand.php`
- Create: `tests/fixtures/print/tokens.json` (copia del `tokens.json` di SARDEGNA Sentieri)
- Test: `tests/Unit/Print/TokensStyleGeneratorTest.php`

**Interfaces:**
- Produces: `TokensStyleGenerator::generate(array $tokens, string $packageName): string`; comando
  `wm:print-style {tokens : percorso del tokens.json} {output : percorso dello .sty}`, registrato
  fra i `commands` del dominio `print`.

- [ ] **Step 1:** test sul fixture:
  - `sar-blu` → `\definecolor{sar-blu}{HTML}{254877}` (valore del tema `light`);
  - alias `{nome}` risolti, alias circolare → eccezione;
  - `sp-2` (`8px`) → lunghezza `6pt` (1 px = 0,75 pt); `raggio-controllo` → `1.5pt`;
  - `families.sans` (`'Work Sans', system-ui, sans-serif`) → `\setmainfont{Work Sans}` (solo il primo nome, senza apici);
  - per ogni stile di `type.groups[].styles[]` un comando `\wmtype<Nome>` con `fontsize` in pt, interlinea, peso (`fontWeight` ≥ 600 → `\bfseries`, 500 → `\mdseries` con la variante Medium se disponibile), `letterSpacing` in em;
  - ombre, breakpoint e tema `dark` ignorati;
  - l'output inizia con `\ProvidesPackage{<packageName>}`.
- [ ] **Step 2:** eseguire, atteso FAIL.
- [ ] **Step 3:** implementare; i nomi dei token passano per `LatexText::escape` dove finiscono in testo.
- [ ] **Step 4:** eseguire, atteso PASS.
- [ ] **Step 5 (dev):** `git commit -m "feat(oc:8982): generatore dello stile LaTeX dai token"`

### Task 4: Compilazione `pdf`

**Files:**
- Create: `src/Services/Print/XelatexCompiler.php`, `src/Exceptions/PrintCompilationException.php`
- Test: `tests/Unit/Print/XelatexCompilerTest.php`

**Interfaces:**
- Produces: `XelatexCompiler::compile(string $tex, array $files): string` — `$files` è
  `nome => percorso locale`; restituisce il contenuto binario del PDF; lancia
  `PrintCompilationException` con le ultime righe del `.log` (errore, timeout, memoria, marcatore).
  `XelatexCompiler::command(string $workDir): array` (pubblico per il test).

- [ ] **Step 1:** test:
  - `command()` contiene `timeout`, `prlimit --as=<memory_mb in byte>`, `xelatex`, `-no-shell-escape`, `-halt-on-error`, `-interaction=nonstopmode`;
  - l'ambiente del processo ha `openin_any=p` e `openout_any=p`;
  - marcatore `/usr/local/share/wm-print/version` diverso da `config('wm-package.features.print.deps_version')` → `PrintCompilationException` con il messaggio «immagine non allineata al package» (R5);
  - test di integrazione `compiles a minimal document` con `->skip(! is_executable('/usr/bin/xelatex'))`: il risultato inizia con `%PDF`;
  - test `does not execute write18`: un `.tex` con `\immediate\write18{touch pwned}` compila senza creare il file.
- [ ] **Step 2:** eseguire, atteso FAIL.
- [ ] **Step 3:** implementare con `Symfony\Component\Process\Process` in una cartella temporanea sotto `storage_path('app/print-tmp')`, sempre cancellata in `finally`; due passate di `xelatex` solo se il log chiede `Rerun`.
- [ ] **Step 4:** eseguire, atteso PASS (il test di integrazione passa nel container dello shard).
- [ ] **Step 5 (dev):** `git commit -m "feat(oc:8982): compilazione xelatex con limiti"`

### Task 5: Client della mappa (`map`), con mock finché oc:9011 non c'è

Dalla call delle 14:42 del 18/11 (Giuseppe): la mappa la fa lui in oc:9011; lo shard gli passa
«una feature collection» — una feature per sentiero o punto, N se sono più tracce — e lo shard,
«perché tiles di webmap in base allo shard dà cose diverse»; per ora «la mappa la ignori o la
mocki». Endpoint, autenticazione, dimensioni e formato di ritorno li comunica Giuseppe quando
chiude oc:9011.

**Files:**
- Create: `src/Services/Print/Contracts/MapRenderer.php`, `src/Services/Print/FakeMapRenderer.php`,
  `src/Services/Print/HttpMapRenderer.php`, `resources/print/map-placeholder.png`
- Modify: `config/wm-package.php` (`features.print.map_service.driver`: `fake` di default, `http`)
- Test: `tests/Unit/Print/MapRendererTest.php`

**Interfaces:**
- Produces:
  - `MapRenderer::render(MapRenderRequest $request): string` (contenuto PNG);
  - `MapRenderRequest` (readonly): `shard` (da `config('wm-package.shard_name')`), `featureCollection`
    (`array`, GeoJSON `FeatureCollection` con le proprietà `id`, `ref`, `name` per feature),
    `widthMm`, `heightMm`; `toArray()` produce `{shard, feature_collection, width_mm, height_mm}`;
  - binding nel service provider del dominio: `fake` → `FakeMapRenderer`, `http` → `HttpMapRenderer`.
  - `FakeMapRenderer` scrive la richiesta nel log `print` (canale `wm-logging`) e restituisce il
    segnaposto; `HttpMapRenderer` resta uno scheletro che manda `toArray()` in POST a
    `map_service.url`, da completare con il contratto di oc:9011.

- [ ] **Step 1:** test:
  - una track → `FeatureCollection` con una feature; mappa d'insieme con 3 track → 3 feature;
  - `shard` uguale a `config('wm-package.shard_name')`;
  - driver `fake` → nessuna richiesta HTTP (`Http::assertNothingSent()`), PNG del segnaposto restituito, richiesta loggata;
  - driver `http` con `Http::fake()`: POST a `map_service.url` con il corpo di `toArray()`; risposta 500 o timeout → `PrintCompilationException` con messaggio leggibile.
- [ ] **Step 2:** FAIL. **Step 3:** implementare. **Step 4:** PASS.
- [ ] **Step 5 (dev):** `git commit -m "feat(oc:8982): richiesta della mappa con mock in attesa di oc:9011"`

### Task 6: Template di default e stile di default

**Files:**
- Create: `resources/print/wm-print.sty` (macro `\BadgeStato`, `\PillDifficolta`, `\TitoloSezione`, `\Tabella`, `\FilettoOro`, colore primario `wmprimary`)
- Create: `resources/views/print/ec-track.blade.php`, `resources/views/print/ec-poi.blade.php`, `resources/views/print/partials/footer.blade.php`
- Modify: `resources/lang/{de,en,es,fr,it}.json`
- Test: `tests/Feature/Print/PrintViewsTest.php`

**Interfaces:**
- Consumes: `LatexText`.
- Produces: view `wm-package::print.ec-track` / `wm-package::print.ec-poi`, che ricevono **esattamente**
  `$model`, `$locale`, `$name` (già escaped), `$descriptionLatex`, `$stats` (lista
  `{label, value}` già escaped), `$mapFile` (nome file o `null`), `$photos` (lista nomi file),
  `$relatedPois` (solo track: lista `{name, typeLabel}`), `$primaryColor` (hex senza `#`),
  `$styleFile` (nome dello `.sty` da includere), `$labels` (array chiave → testo tradotto). Il
  contratto è nel docblock in testa a ogni view (R33).

- [ ] **Step 1:** test che fa il render della view in stringa:
  - contiene `\usepackage{wm-print}` e `\definecolor{wmprimary}{HTML}{<primary_color dell'app>}`;
  - il nome `Sentiero 50% & co` compare escaped;
  - con `$mapFile = null`, `$photos = []`, descrizione vuota: nessun `\includegraphics` e nessuna sezione vuota;
  - piè di pagina con «© OpenStreetMap e contributori, Webmapp»;
  - etichette dalla lingua richiesta (`it` → «Lunghezza», `en` → «Length»).
- [ ] **Step 2:** FAIL. **Step 3:** scrivere view e `.sty`; corpo testo ≥ 9 pt (R35). **Step 4:** PASS.
- [ ] **Step 5 (dev):** `git commit -m "feat(oc:8982): template LaTeX di default"`

### Task 7: Servizio di stampa e salvataggio

**Files:**
- Create: `src/Services/Print/PrintService.php`, `src/Traits/Printable.php`
- Modify: `src/Services/StorageService.php` (nuovo `storeFileContents`, `getFileLastModified`)
- Modify: `src/Models/EcTrack.php`, `src/Models/EcPoi.php` (usano `Printable`)
- Test: `tests/Feature/Print/PrintServiceTest.php`

**Interfaces:**
- Consumes: Task 2, 4, 5, 6.
- Produces:
  - trait `Printable`: `printView(): string`, `printViewData(string $locale): array`, `printFileName(string $locale): string` (`print-{locale}`);
  - `StorageService::storeFileContents(Model $model, string $filename, string $ext, string $contents): string` (URL), scrittura su chiave temporanea e poi spostamento, per non lasciare un 404 fra `delete` e `put`; `StorageService::getFileLastModified(Model $model, string $filename, string $ext): ?int`;
  - `PrintService::generate(Model&Printable $model, string $locale): string` (URL del PDF);
  - `PrintService::isFresh(Model $model, string $locale): bool`;
  - `PrintService::existingLocales(Model $model): array`.

- [ ] **Step 1:** test con `Storage::fake('wmfe')`, `Http::fake()` e `XelatexCompiler` mockato:
  - `generate()` salva `files/ec-track/{id}/print-it.pdf` e restituisce l'URL;
  - le foto WebP vengono convertite in JPEG e ridimensionate (lato lungo 2000 px) prima della compilazione; un URL di foto che risponde 404 viene saltato;
  - campo tradotto mancante in `de` → testo del fallback;
  - eccezione del servizio mappe → il PDF esistente non viene sovrascritto e l'eccezione risale;
  - `isFresh()` falso se `updated_at` > `lastModified`, vero altrimenti, falso se il file manca;
  - `existingLocales()` restituisce le lingue dei file `print-*.pdf` presenti.
- [ ] **Step 2:** FAIL. **Step 3:** implementare (conversione immagini con Intervention o GD, già nelle dipendenze: verificare in `composer.json`). **Step 4:** PASS.
- [ ] **Step 5 (dev):** `git commit -m "feat(oc:8982): servizio di stampa e salvataggio su wmfe"`

### Task 8: Job di generazione

**Files:**
- Create: `src/Jobs/Print/GeneratePrintPdfJob.php`
- Test: `tests/Unit/Print/GeneratePrintPdfJobTest.php`

**Interfaces:**
- Produces: `GeneratePrintPdfJob(Model $model, string $locale)`, `ShouldQueue` + `ShouldBeUnique`,
  `uniqueId()` = `{morphClass}:{id}:{locale}`, `uniqueFor` 600 s (R16), `onQueue(config(...queue))`,
  `tries` 2. Stato in cache `print:{morphClass}:{id}:{locale}` con valori `queued|done|failed` e messaggio.

- [ ] **Step 1:** test: due dispatch uguali → uno solo in coda; coda = `print`; `failed()` scrive lo stato `failed` con il messaggio dell'eccezione.
- [ ] **Step 2–4:** FAIL, implementare, PASS.
- [ ] **Step 5 (dev):** `git commit -m "feat(oc:8982): job di generazione del PDF"`

### Task 9: Rotte, pagina d'attesa e stato

**Files:**
- Modify: `routes/domains/print.php`
- Create: `src/Http/Controllers/PrintController.php`, `resources/views/print/waiting.blade.php`
- Test: `tests/Feature/Print/PrintRoutesTest.php`

**Interfaces:**
- Produces: `GET /print/{type}/{id}?lang=` (`print.show`, `type` ∈ `ec-track`, `ec-poi`),
  `GET /print/{type}/{id}/status?lang=` (`print.status`, JSON `{status, url?, message?}`), con
  `throttle:30,1` (R37).

- [ ] **Step 1:** test:
  - PDF fresco → `302` all'URL su `wmfe`;
  - PDF assente o non fresco → `200` con la pagina d'attesa e `GeneratePrintPdfJob` in coda;
  - `lang` non fra le lingue dell'app (`wm-app-languages`) → `404` e nessun Job (R36); `lang` assente → lingua di default dell'app;
  - id inesistente → `404`;
  - `status` restituisce `done` con `url`, oppure `failed` con `message`;
  - la pagina d'attesa ha `aria-busy="true"` e una regione `aria-live="polite"` (R34) e funziona anche senza JavaScript, con un `<meta http-equiv="refresh">`.
- [ ] **Step 2–4:** FAIL, implementare, PASS.
- [ ] **Step 5 (dev):** `git commit -m "feat(oc:8982): rotte della scheda e pagina d'attesa"`

### Task 10: URL della scheda nel JSON di dettaglio

**Files:**
- Modify: `src/Models/EcTrack.php` (dove si riempie `$geojson['properties']`), `src/Http/Controllers/Api/EcPoiController.php`
- Test: `tests/Feature/Print/PrintUrlInDetailTest.php`

- [ ] **Step 1:** test: dominio acceso → `properties.print_url` = `route('print.show', ['type' => 'ec-track', 'id' => $id])` senza `lang`; spento → chiave assente (R6, R17).
- [ ] **Step 2–4:** FAIL, implementare, PASS.
- [ ] **Step 5 (dev):** `git commit -m "feat(oc:8982): URL della scheda nel dettaglio"`

### Task 11: Rigenerazione

**Files:**
- Create: `src/Jobs/Print/RegeneratePrintPdfsJob.php`
- Modify: `src/Services/Models/EcTrackService.php` (`publicationJobs`), `src/Observers/EcPoiObserver.php` (`saved`)
- Test: `tests/Feature/Print/PrintRegenerationTest.php`

**Interfaces:**
- Produces: `RegeneratePrintPdfsJob(Model $model)`: accoda `GeneratePrintPdfJob` per ogni lingua di
  `PrintService::existingLocales()`; nessuna lingua → niente.

- [ ] **Step 1:** test:
  - `publicationJobs()` contiene `RegeneratePrintPdfsJob` solo a dominio acceso;
  - track con `print-it.pdf` e `print-en.pdf` → due Job; track mai stampata → nessuno (R18);
  - salvataggio di un `EcPoi` con PDF → rigenera il POI e i PDF esistenti degli `EcTrack` collegati (R19, R20);
  - cancellazione di un `EcTrack` → i suoi `print-*.pdf` spariscono (R21, via `deleteModelFiles`).
- [ ] **Step 2–4:** FAIL, implementare, PASS.
- [ ] **Step 5 (dev):** `git commit -m "feat(oc:8982): rigenerazione dei PDF nella catena"`

### Task 12: Action Nova

**Files:**
- Create: `src/Nova/Actions/GeneratePrintPdfAction.php`
- Modify: `src/Nova/EcTrack.php`, `src/Nova/EcPoi.php` (`actions()`, solo se `FeaturesService::isEnabled('print')`)
- Test: `tests/Feature/Print/GeneratePrintPdfActionTest.php`

- [ ] **Step 1:** test: campo `MultiSelect` delle lingue dell'app con default `['it']`; l'esecuzione su 3 elementi con `it,en` accoda 6 Job; dominio spento → Action assente (R22).
- [ ] **Step 2–4:** FAIL, implementare, PASS.
- [ ] **Step 5 (dev):** `git commit -m "feat(oc:8982): Action Nova di generazione delle schede"`

### Task 13: ZIP delle schede

**Files:**
- Create: `src/Jobs/Print/BuildPrintZipJob.php`; Modify: `PrintController.php`, `routes/domains/print.php`
- Test: `tests/Feature/Print/PrintZipTest.php`

**Interfaces:**
- Produces: `GET /print/zip?ids[]=…&lang=` (`print.zip`, `throttle:5,1`) e stato su
  `print.status` con `type=zip`; chiave dello ZIP = hash degli id ordinati + lingua; file in
  `print-tmp/zip/` su `wmfe` con scadenza 24 h (pulizia schedulata nel dominio).

- [ ] **Step 1:** test: più di `zip.max_tracks` id → `422` (R26); riusa i PDF freschi e genera solo i mancanti (R23); stessa selezione due volte → un solo Job; pagina d'attesa e redirect come la scheda (R24); file più vecchi di 24 h rimossi dal comando di pulizia (R25).
- [ ] **Step 2–4:** FAIL, implementare, PASS.
- [ ] **Step 5 (dev):** `git commit -m "feat(oc:8982): ZIP delle schede"`

### Task 14: Mappa d'insieme

**Files:**
- Create: `src/Jobs/Print/BuildOverviewMapJob.php`, `resources/views/print/overview-map.blade.php`; Modify: controller e rotte
- Test: `tests/Feature/Print/OverviewMapTest.php`

**Interfaces:**
- Produces: `GET /print/overview-map?ids[]=…&lang=` (`print.overview-map`, `throttle:5,1`);
  `PrintService::overviewScale(array $bbox, float $widthMm, float $heightMm): int` (denominatore).

- [ ] **Step 1:** test: A4 orizzontale (area mappa 277×180 mm); `overviewScale` su un bbox noto restituisce il valore atteso; scala oltre 50.000 → nessun Job e messaggio «riduci la selezione» nella pagina (R28); i codici dei sentieri passano al servizio come proprietà `ref` delle feature (R27); file temporaneo come lo ZIP (R29).
- [ ] **Step 2–4:** FAIL, implementare, PASS.
- [ ] **Step 5 (dev):** `git commit -m "feat(oc:8982): mappa d'insieme"`

### Task 15: Script delle dipendenze di sistema

**Files:**
- Create: `docker/render/install-deps.sh`, `docker/render/VERSION`
- Test: `tests/Unit/Print/InstallDepsScriptTest.php`

- [ ] **Step 1:** test: lo script ha `set -euo pipefail`, installa `texlive-xetex` e `texlive-fonts-recommended` con `--no-install-recommends`, esce con errore se `/etc/os-release` non è Debian ≥ 13, scrive `/usr/local/share/wm-print/version` con il contenuto di `VERSION`; `config('wm-package.features.print.deps_version')` legge lo stesso file.
- [ ] **Step 2–4:** FAIL, implementare, PASS. Verifica manuale: `docker build` di prova sull'immagine di forestas e `xelatex --version`.
- [ ] **Step 5 (dev):** `git commit -m "feat(oc:8982): script di installazione di TeX Live"`

### Task 16: Documentazione

**Files:**
- Create: `docs/resources/Print.md` (come si attiva, contratto delle view, override, generatore dello stile, coda dedicata da dichiarare nello shard)
- Modify: `CLAUDE.md` (riga in «Conoscenza»), `CHANGELOG` tramite release-please (nessuna modifica a mano)

- [ ] **Step 1:** scrivere la pagina; controllare i link.
- [ ] **Step 2 (dev):** `git commit -m "docs(oc:8982): documentazione della stampa"`

## Ampliamento del 20/11 (R39-R45)

R38, il logo dell'App, è già implementato. Interfacce comuni ai task 17-20:
- `PrintTheme` (`src/Services/Print/PrintTheme.php`): `for(App $app): ?array` (il tema salvato,
  tokens.json con gli alias `print-*`), `save(App $app, array $tokens, array $roles): void`,
  `delete(App $app): void`, `savedAt(App $app): ?int`, `validate(array $tokens): array` (errori),
  costante `ROLES = ['title','accent','rule','text','muted','line']`. Disco `wmfe`, percorso
  `{shard}/json/print-theme/{app_id}.json`.
- `PrintService::sampleTrack(App $app): EcTrack` (modello non salvato con dati d'esempio) e
  `PrintService::preview(App $app, array $tokens, array $roles): string` (bytes del PDF).

### Task 17: Colori per ruolo nelle view di default (R39)

- [ ] **Step 1:** test in `PrintViewsTest`: il `.tex` di scheda, POI e mappa d'insieme non usa più
  `wmprimary`/`wmgold`/`wmgray` direttamente ma i sei ruoli; senza tema `wmtitle` vale il colore
  primario dell'App e `wmrule` l'oro; con uno stile che definisce `wmrule` vince quello.
- [ ] **Step 2:** `wm-print.sty` e le tre view: i ruoli si definiscono dopo `wmprimary` solo se lo
  stile non li ha già definiti; le macro usano i ruoli. Docblock del contratto aggiornato.
- [ ] **Step 3:** suite Print verde; un PDF di prova a vista uguale a prima.

### ~~Task 18-20 (tema per App, pagina, anteprima)~~ — superati dalla revisione del 20/11

Il Task 18 è stato scritto e va tolto (Task 21); 19 e 20 non si fanno.

### Task 21: Tema dell'App, mappa configurabile, via i token (R46-R50)

- [ ] **Step 1:** test: con «Primary», «Secondary», «Tertiary color» dell'App il `.tex` definisce
  `wmtitle`, `wmaccent`, `wmrule` da quei colori; con i campi vuoti i default di oggi; con
  «Font Family Content/Header» `\IfFontExistsTF` attorno a `\setmainfont`/font dei titoli, e un
  font inesistente produce comunque il PDF (compilazione reale, saltata senza xelatex); la mappa
  della scheda chiede al renderer `features.print.map.width_mm`/`height_mm` (default 174 × 98).
- [ ] **Step 2:** rimuovere `TokensStyleGenerator`, `GeneratePrintStyleCommand` (`wm:print-style`),
  `style_path` e `styleFiles()` dello shard (resta solo `wm-print.sty`), `PrintTheme`, i loro test e
  la fixture `tests/fixtures/print/tokens.json`; `$styleFiles` nel contratto resta (contiene solo
  `wm-print`) oppure si toglie, segnalandolo agli override.
- [ ] **Step 3:** colori e font dal tab `theme` dell'App in `PrintService` (accanto a
  `primaryColor()`), con la validazione esadecimale esistente; mappa con dimensioni da config.
- [ ] **Step 4:** `docs/resources/Print.md`: tema dell'App per la scheda di default; view dello
  shard per tutto il resto (contratto, preambolo proprio, ruoli); dimensioni della mappa.
- [ ] **Step 5 (dev):** `git commit -m "feat(oc:8982): scheda di default dal tema dell'App"`

## Verifica finale

- `vendor/bin/pest --filter=Print` (dopo aver letto `.claude/rules/test.md`), atteso tutto verde.
- `vendor/bin/phpstan analyse`, nessun errore nuovo.
- Nel container di forestas, con il dominio acceso: `/print/ec-track/<id>?lang=it` produce un PDF leggibile, con la mappa segnaposto finché oc:9011 non è pronto.
