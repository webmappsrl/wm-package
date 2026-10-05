> Ticket: oc:8675

# Notes — Title tradotti dei box Flexible che si svuotano in edit

## Divergenze dal piano, task per task

### Task 1: test che riproduce la causa

La causa è confermata, con due effetti diversi dello stesso meccanismo (oggetti dei sotto-campi
condivisi fra i gruppi dello stesso layout e il template, serializzati solo al `json_encode`
finale):

- **più gruppi dello stesso layout**: ogni gruppo riceve i valori dell'ultimo gruppo risolto;
- **un solo gruppo per layout, il caso del cliente**: i valori arrivano vuoti, perché la risposta
  di edit di Nova contiene lo stesso oggetto campo due volte, in `fields` e nei `panels`
  (`ResolvesFields::resolvePanelsFromFields()`, `Panel::mutate()`), e la copia dei pannelli viene
  serializzata dopo il `resolve(true)` di `Layout::jsonSerialize()` del template.

Il secondo effetto è emerso in review: il primo helper serializzava il campo una volta sola, e il
test con un box per tipo passava anche senza fix. Confermato nel browser dal dev il 05/10/2026: con
il fix tolto, l'edit dell'app 3 (un box per tipo) mostra vuoti i title di Titolo, Slug, External URL
e Horizontal Scroll Activities. Una richiesta HTTP vera a `update-fields` dentro un test non è
usabile: risponde 500 («Unable to parse incoming Flexible content, data should be an array»), con o
senza fix. L'helper `flexibleLayoutSerializeForEdit()` serializza quindi come la risposta di Nova
(`fields` più `panels`) e legge la copia dei pannelli.

Il setup costruisce l'`App` con `setRawAttributes(..., true)`: `ConfigHomeResolver::get` legge
`getRawOriginal()`, che su `new App([...])` è vuoto.

Gli helper hanno nomi diversi da quelli del piano, con il prefisso `flexibleLayout` per evitare
collisioni fra le funzioni globali di Pest: `flexibleLayoutAppField()` (cerca il Flexible fra i
campi reali della risorsa `App`, invece di prendere i layout via Reflection),
`flexibleLayoutUnsavedApp()`, `flexibleLayoutSerializeForEdit()`, `flexibleLayoutValuesByGroup()`,
`flexibleLayoutFormAttributes()`, `flexibleLayoutSubmit()` (le modifiche ai gruppi passano da una
callback invece che da un array `$overrides`) e `flexibleLayoutSaveUntouched()`, non previsto.

### Task 3: giro completo del form su home e overlays

Il test degli `horizontal_scroll` e quello del riordino sono stati rinforzati con due gruppi dello
stesso layout, così vedono anche la contaminazione fra gruppi. Controprova finale, sui 12 test del
file: senza fix ne falliscono 9; passano solo «mantiene i due formati del title vuoto» (svuota
comunque tutte le lingue) e i due Repeater, che devono passare anche senza fix. Con il fix passano
tutti e 12.

Il test `richText` controlla due lingue e fa anche il salvataggio, non solo la serializzazione.
`FlexibleTranslatableTest` (21 test) resta verde con il fix.

### Task 5: le due versioni di kongulov, suite e analisi statica

- Suite eseguita con la 2.1.7 e con la 2.2.5, dopo le correzioni della review: in tutti e due i
  casi 37 test verdi su 38, e l'unico fallimento è `AppConfigHomeHorizontalScrollTest` (vedi «Bug
  trovati»).
- La 2.2.5 è stata installata con `composer update kongulov/nova-tab-translatable --with=…:2.2.5`
  invece di `composer require`, per non toccare `composer.json` di maphub. Ripristino:
  `git checkout composer.lock` e `composer install`; verificato che il vendor è tornato alla
  2.1.7 e che `git status` di maphub non mostra `composer.*`.
- `AppConfigHomePoiTrackLayoutTest` non gira dalla suite di maphub: estende
  `Wm\WmPackage\Tests\TestCase`. Escluso dal giro; il file non è toccato da questo lavoro.
- PHPStan di maphub: 0 errori. Pint sui due file toccati: ok.

### Task 6: prova a mano in Nova locale

Fatta dal dev sull'app 3 il 05/10/2026, con un box per tipo: `title` (legacy stringa), `slug`,
`external_url`, `horizontal_scroll` activities. Esito ok. Controllo successivo su `config_home` e
sul config generato in sola lettura (`AppConfigService::config()`, senza scrivere su S3): i due
coincidono, il title legacy è convertito nelle 5 lingue, gli altri title hanno solo le lingue
inserite, il box `base` è invariato.

Questa prova copre proprio il caso del cliente (un box per tipo): con il fix tolto, lo stesso edit
mostra i title vuoti (vedi task 1). La contaminazione fra due box dello stesso tipo è coperta dai
test automatici, non da questa prova. Nell'`external_url` dell'app 3 l'URL inizia
con uno spazio: è un dato inserito a mano, non legato al fix.

## Bug trovati

- `ConfigHomeTitleBoxLegacyStringTest` dalla suite di maphub fallisce con `Target class [config]
  does not exist`: non dichiara `uses(Tests\TestCase::class)`. Preesistente, non corretto qui.
- `AppConfigHomeHorizontalScrollTest` («stores a horizontal scroll activities item…») fallisce sul
  DB di sviluppo copiato da dev.maphub: crea l'attività `hiking`, che lì esiste già («The inserted
  'identifier' field already exists»). Fallisce anche senza il fix: dipende dai dati.
- I 4 errori PHPStan su `FlexibleTranslatable::simple()`/`richText()` (`new static`, tipo di
  ritorno) c'erano già prima del fix.

## Decisioni

- Tag Orchestrator del ticket gestiti dal dev: nessuna proposta di tag in questo lavoro.
- Nessuna protezione al salvataggio per i title vuoti: nel `config_home` salvato i box non hanno un
  identificativo stabile, e un abbinamento per posizione e tipo sbaglierebbe quando l'admin
  aggiunge, toglie o riordina box.
- Code review (wm-review-ticket) sul working tree prima del commit: nessun bloccante sul codice;
  corretti i test, che non riproducevano il caso del cliente, la documentazione del meccanismo, i
  nomi delle funzioni globali dei test e il docblock di classe, che ora cita anche il meta `fields`
  di kongulov fra le cose da ricontrollare a ogni bump. Non unificata la ricerca del Flexible fra i
  test (`flexibleLayoutAppField()` è la quinta variante): richiederebbe di toccare quattro file di
  test fuori da questo lavoro.
- Il docblock di `jsonSerialize()` è in italiano, come chiede il `CLAUDE.md` del package, anche se
  il resto della classe ha docblock in inglese.
- La ricerca delle call per cartella su Drive non restituisce risultati: le call sono state
  cercate per titolo.

## Follow-up

- Correggere `ConfigHomeTitleBoxLegacyStringTest` perché giri anche dalla suite del consumer.
- `AppConfigHomeHorizontalScrollTest` non dovrebbe dipendere dall'assenza di `hiking` nel DB.
