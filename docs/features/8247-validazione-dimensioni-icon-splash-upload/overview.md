> Ticket: oc:8247

# Validazione dimensioni minime icon/splash screen in upload backend (wm-package)

## Cosa cambia

Al momento del caricamento delle immagini `icon` e `splash` nel tab "App release data" della Nova Resource `App` (`wm-package/src/Nova/App.php`), il backend valida che l'immagine rispetti la dimensione minima e l'aspect ratio richiesti dalla pipeline di build nativa (cordova-res / capacitor-assets), rifiutando l'upload con un messaggio esplicito se non conforme.

Le rule di validazione (attualmente commentate nel codice) vengono attivate con soglie **minime** anziché esatte, e con un messaggio d'errore custom che indica la soglia richiesta.

## Perché

Oggi le dimensioni insufficienti di `splash.png` vengono scoperte solo in fase di build Android (gulp/cordova-res), quando è tardi per correggerle facilmente — la build fallisce e blocca anche la generazione delle icone nello stesso batch (root cause di oc:8246). Spostare la validazione a monte, al momento dell'upload da parte del gestore dell'app, evita che un'immagine non conforme arrivi mai in fase di build.

## Requisiti

- [ ] Il campo `icon` (Nova, `Images::make(__('Icon'), 'icon')`) valida dimensione minima 1024×1024px e aspect ratio 1:1 (quadrato) tramite `->singleMediaRules(['image', 'mimes:png', 'dimensions:min_width=1024,min_height=1024,ratio=1/1'])` — **non** `->rules()`
- [ ] Il campo `splash` (Nova, `Images::make(__('Splash image'), 'splash')`) valida dimensione minima 2732×2732px e aspect ratio 1:1 (quadrato) con lo stesso meccanismo `->singleMediaRules(...)`
- [ ] Il campo `icon_small` non riceve alcuna validazione dimensionale (non consumato da nessuna pipeline di build nativa)
- [ ] `icon`, `splash` e `icon_small` diventano collection `singleFile()` in `App::registerMediaCollections()` — un solo file per campo, coerente con l'uso reale (`AppController::getOrDownloadIcon()` assume sempre un singolo media)
- [ ] Il messaggio d'errore mostrato in caso di validazione fallita è custom e indica esplicitamente la soglia richiesta (es. "Lo splash deve essere almeno 2732×2732px e quadrato"), non il messaggio generico Laravel ("has invalid image dimensions") — implementato via `resources/lang/{it,en}/validation.php` (chiave `custom.icon.dimensions` / `custom.splash.dimensions`), unico canale disponibile dato che il field non passa un array `$messages` custom al Validator interno
- [ ] La validazione avviene esclusivamente tramite il field Nova (`->singleMediaRules(...)`), nessun Observer aggiuntivo sulla Media Library

## Rischi

- **`->rules()` vs `->singleMediaRules()`** — `Ebess\AdvancedNovaMediaLibrary\Fields\Media::fillAttributeFromRequest` valida `->rules()` contro l'intero array della collection (mix di ID esistenti + nuovi file), non contro il singolo file: la rule `dimensions` fallirebbe sempre perché un array non è mai un'istanza `File`, bloccando ogni salvataggio del form con icon/splash già valorizzate. Confermato dalla storia git: le rule furono commentate nel commit `43d01c49` esattamente nel momento in cui i campi furono migrati a Media Library, senza fix successivo. Mitigato usando `->singleMediaRules(...)`, che valida solo i nuovi file effettivamente caricati (istanze `UploadedFile`), lasciando intatti i media già esistenti — questo risolve anche il rischio di retroattività su altri progetti consumer di wm-package con App esistenti sotto le nuove soglie: continuano a salvare senza problemi finché non sostituiscono il file.
- **Duplicazione soglie cross-repo** — 1024×1024 (icon) e 2732×2732 (splash) sono duplicati qui e nella configurazione cordova-res/capacitor-assets di `pap`/`webmapp-app`, senza meccanismo di sync. Se quei repo cambiano soglia in futuro, questa validazione diventa silenziosamente disallineata. Rischio accettato, non risolvibile in questo ciclo senza un contratto condiviso tra repo.
- **Falso positivo su `ratio=1/1`** — la tolleranza numerica della rule Laravel è minima; un export con un pixel di scarto (es. 2732×2731) potrebbe essere rifiutato in modo poco intuitivo per l'admin. Rischio minore accettato, da rivalutare come ticket separato se si presenta in pratica.
- **File corrotto/non decodificabile** — `getimagesize()` fallito fa fallire `dimensions` con lo stesso messaggio custom ("deve essere almeno..."), potenzialmente fuorviante per un file semplicemente corrotto. Edge case raro, accettato senza azione correttiva.
- **Rollback su submodule condiviso** — nessuna migration/breaking change, rollback di codice banale, ma essendo `wm-package` condiviso da più progetti un eventuale bug si propaga a tutti i consumer che aggiornano la dipendenza; il rollback andrebbe coordinato su più repo se scoperto tardi. Caratteristica strutturale del pattern submodule, non introdotta da questo ticket.

## Out of scope

- Validazione dimensionale su `icon_small` — non impatta nessuna pipeline di build nativa trovata (verificato su `pap` e `webmapp-app`, entrambi alimentano cordova-res/capacitor-assets solo con `icon.png` e `splash.png`)
- Validazione o modifica dei campi `my_paths` e `my_downloads` (stesso tab Nova, help text con dimensioni consigliate 2214×1013 ma nessuna rule) — esplicitamente lasciati fuori scope
- Controllo retroattivo delle immagini già caricate su App esistenti (in questo progetto è presente un solo record App — impatto minimo, verifica manuale se necessaria)
- Un secondo livello di validazione a livello di Observer sulla Media Library, per coprire eventuali upload futuri non passanti da Nova — non necessario oggi perché il form Nova è l'unico punto di ingresso upload verificato (`AppController` espone solo endpoint di download)

## Moduli toccati

- `wm-package/src/Nova/App.php` — attivazione `->singleMediaRules(...)` (minime, non esatte) sui campi `icon` e `splash`
- `wm-package/src/Models/App.php` — `->singleFile()` su `icon`, `splash`, `icon_small` in `registerMediaCollections()`
- `wm-package/resources/lang/{it,en}/validation.php` (nuovo) — messaggi di validazione custom per `dimensions` su `icon`/`splash`
- `wm-package/src/WmPackageServiceProvider.php` — aggiunta `$this->loadTranslationsFrom(__DIR__.'/../resources/lang')` (senza namespace, accanto all'esistente `loadJsonTranslationsFrom`) per mergiare `validation.php` nel gruppo `validation` di Laravel
