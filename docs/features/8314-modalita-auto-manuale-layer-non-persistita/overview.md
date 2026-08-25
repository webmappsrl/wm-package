> Ticket: oc:8314

# Modalità auto/manuale del layer non persistita lato backend

## Cosa cambia

Il campo Nova `LayerFeatures` comunica al backend la modalità scelta dall'utente,
e `LayerFeatureController::sync()` la persiste sul layer in
`configuration->track_mode` / `configuration->poi_mode`.

Frontend (`resources/js/`):

- il passaggio auto → manuale (`handleToggleClick`, oggi puramente locale) invia
  una richiesta al backend con **solo** `manual: true`, senza `features`: il
  pivot `layerables` contiene già le tracce/POI assegnati dall'ultima
  sincronizzazione automatica, quindi non serve inviarli né ricalcolarli — sono
  già l'init della selezione manuale;
- il salvataggio della selezione (`handleSave`) invia `manual: true`, riaffermando
  la modalità a ogni salvataggio;
- il ritorno manuale → auto (`handleModeChange`) continua a inviare `auto: true`,
  ora effettivamente persistito.

Backend: la persistenza è estratta in un metodo protetto riusabile
`persistMode(Layer $layer, string $relationName, Request $request): void`, chiamato
da `sync()`:

- `manual: true` → `Layer::setTrackMode('manual')` / `setPoiMode('manual')`
- `auto: true` → `setTrackMode('auto')` / `setPoiMode('auto')` **esplicito**
- nessuno dei due → modalità non toccata (retrocompatibilità)

Il metodo è `protected` perché i progetti che sovrascrivono `sync()` senza chiamare
`parent::sync()` — come camminiditalia (oc:8311) — devono poter riusare la stessa
logica con una sola chiamata, invece di duplicarla. La persistenza della modalità è
ortogonale al calcolo degli ID e ai controlli di autorizzazione, quindi è
estraibile senza toccare le parti che i progetti personalizzano.

## Perché

`Layer::setTrackMode()` e `Layer::setPoiMode()` (`src/Models/Layer.php:158,171`)
esistono ma non sono invocati da nessun punto del codice: sono metodi morti.
`sync()` aggiorna il pivot `layerables` ma non ha mai scritto la modalità, quindi
il default `?? 'auto'` prevale sempre e la UI torna su "auto" a ogni reload,
qualunque cosa l'utente abbia fatto.

Il bug è del package e riguarda tutti i progetti che usano il campo
`LayerFeatures`, non solo camminiditalia dove è stato riprodotto (oc:8311).

Il frontend Vue del campo esiste **solo** in questo repo: senza la modifica qui,
nessun progetto può inviare il flag di modalità, e il fix è impossibile a valle.

## Requisiti

- [ ] `sync()` valida un parametro `manual` (boolean, opzionale) accanto ad `auto`,
      mutuamente esclusivi via `prohibits` (`auto: true` + `manual: true` nella
      stessa richiesta → 422, nessuna scrittura eseguita)
- [ ] La persistenza vive in un unico metodo `protected persistMode()`, riusabile
      dalle sottoclassi che riscrivono `sync()` senza delegare al parent
- [ ] Con `manual: true` la modalità `manual` è persistita sulla chiave corretta
      in base alla relazione: `track_mode` per `ecTracks`, `poi_mode` per `ecPois`
- [ ] Con `auto: true` la modalità `auto` è persistita **esplicitamente**
- [ ] Una chiamata senza né `auto` né `manual` lascia la modalità invariata
- [ ] `persistMode()` non contiene logica di autorizzazione né di calcolo degli ID:
      resta utilizzabile da override con regole di ownership proprie
- [ ] Nel ramo `auto` di `sync()`, `persistMode()` viene chiamato **prima**
      dell'assign da tassonomia (`assignTracksByTaxonomy()` /
      `assignPoisByTaxonomy()`): quest'ultimo legge `isAutoTrackMode()`/
      `isAutoPoiMode()` sull'istanza già aggiornata in memoria da `persistMode()`,
      altrimenti trova ancora la modalità precedente e non ricalcola il pivot,
      lasciando `track_mode`/`poi_mode` = `auto` con un pivot stantio
- [ ] `handleToggleClick` sul verso auto → manuale invia **solo** `manual: true`
      (nessun `features` nel payload): il pivot resta quello già scritto
      dall'ultima sincronizzazione automatica, senza bisogno di rileggerlo o
      ritrasmetterlo dal frontend
- [ ] Il backend, quando riceve `manual: true` in una richiesta senza `features`,
      persiste solo la modalità e non esegue alcun `sync()` sul pivot
- [ ] `handleSave` include `manual: true` nel payload esistente
- [ ] `handleModeChange` continua a inviare `auto: true` (nessuna regressione)
- [ ] Il fallimento della chiamata di persistenza della modalità non lascia la UI
      in uno stato divergente dal backend: il toggle torna al valore precedente
- [ ] `configuration` è aggiornata in merge, preservando le altre chiavi
- [ ] Un layer con `configuration` `NULL` accetta la prima scrittura senza errori
- [ ] `Layer::setTrackMode()`/`setPoiMode()` scrivono la singola chiave con
      `jsonb_set` a livello SQL (colonna `configuration` è `jsonb` su
      PostgreSQL), non con un read-modify-write in PHP sull'intero oggetto: due
      scritture concorrenti su chiavi diverse (`track_mode` da un pannello,
      `poi_mode` dall'altro, sulla stessa pagina Nova) non devono perdersi a
      vicenda (lost update)
- [ ] Dopo la scrittura via `jsonb_set`, l'istanza in memoria del layer è
      ricaricata (`refresh()`) prima di qualunque lettura successiva di
      `isAutoTrackMode()`/`isAutoPoiMode()` nello stesso ciclo di richiesta —
      necessario per l'ordine corretto in `sync()` (vedi rischio "Dipendenza
      dall'ordine di chiamata")
- [ ] Il dist del campo Nova è ricompilato con `npm run prod` (Laravel Mix)
- [ ] `[Aggiuntivo, emerso in verifica]` Il frontend (`handleModeChange`) mostra
      il messaggio d'errore specifico ricevuto dal backend (es. `error.response
      .data.error`) invece di un messaggio generico, quando la persistenza
      della modalità fallisce
- [ ] `[Aggiuntivo, emerso in verifica]` Il default della modalità quando
      `configuration` non ha `track_mode`/`poi_mode` è configurabile tramite
      `wm-package.default_layer_mode` (default `'auto'`, invariato per gli
      altri progetti); camminiditalia lo sovrascrive a `'manual'` via
      `DEFAULT_LAYER_MODE` in `.env`

## Rischi

- **`manual` disattiva automatismi reali su altri progetti.** Il flag è consumato
  da `src/Nova/Fields/LayerFeatures/src/LayerFeatures.php:75` (stato iniziale del
  toggle), `src/Observers/EcTrackObserver.php:137` (filtra i layer `auto` per
  dispatchare `SyncAutoLayerAfterTrackTaxonomyChangeJob`), l'equivalente lato POI,
  e `src/Models/Layer.php:358` (fallback in lettura da tassonomia). Su un progetto
  con tassonomie configurate sui layer, il primo salvataggio manuale **spegne** il
  ricalcolo automatico per quel layer. È la semantica corretta ("manuale = non
  toccarmi automaticamente") ma è un cambio di comportamento osservabile su
  installazioni diverse da camminiditalia, dove il flag oggi non viene mai scritto.
  *Mitigazione:* il cambio avviene solo su azione esplicita dell'utente, mai per
  migrazione o backfill; nessun layer esistente cambia comportamento fino al primo
  toggle manuale. Va comunicato nel changelog del package.

- **Asimmetria dei due versi del toggle.** Oggi manual → auto passa dal backend,
  auto → manual no. Introdurre una chiamata sul verso mancante crea una possibile
  race tra la richiesta di cambio modalità e un `fetchFeatures()` concorrente.
  *Mitigazione:* attendere la risposta della persistenza prima di ricaricare le
  features, e ripristinare `isManual` al valore precedente in caso di errore —
  pattern già presente in `handleModeChange`.

- **Il dist compilato è versionato.** Dimenticare `npm run prod` produce un fix
  che funziona in locale e non in produzione, senza alcun errore visibile.
  *Mitigazione:* task esplicito e verificabile nel piano (il dist modificato deve
  comparire nel diff).

- **Il flag inviato da un frontend aggiornato verso un backend non aggiornato
  viene ignorato in silenzio.** *Mitigazione:* frontend e controller sono
  modificati nello stesso commit di questo repo, mai separatamente.

- **`persistMode()` è un nuovo punto di estensione pubblico di fatto.** Renderlo
  `protected` lo espone alle sottoclassi: una firma sbagliata ora è un breaking
  change per i progetti a valle in futuro. *Mitigazione:* firma minimale basata su
  ciò che il metodo ha realmente bisogno di sapere (layer, nome relazione,
  request), senza parametri che anticipino usi ipotetici.

- **Read-modify-write concorrente su colonna JSON condivisa.** La resource Nova
  `Layer` ha due pannelli `LayerFeatures` sulla stessa pagina (Tracce e POI),
  entrambi scrivono sulla stessa colonna `configuration` chiamando lo stesso
  endpoint. Un `setTrackMode()`/`setPoiMode()` che legge l'intero oggetto in PHP,
  modifica una chiave e riscrive tutto perde l'aggiornamento dell'altro pannello
  se le due richieste si sovrappongono anche solo per una finestra breve (due
  click ravvicinati sui due toggle). *Mitigazione:* scrittura atomica via
  `jsonb_set` direttamente nell'`UPDATE` SQL (query builder, non cast Eloquent),
  che aggiorna una sola chiave senza mai passare per uno snapshot completo letto
  in anticipo; Postgres serializza le due `UPDATE` sulla stessa riga. Prima del
  fix questo rischio non esisteva perché `configuration` non veniva mai scritta;
  introducendo scritture reali a ogni toggle, la finestra si apre per la prima
  volta.

- **Dipendenza dall'ordine di chiamata in `sync()`.** `assignTracksByTaxonomy()`/
  `assignPoisByTaxonomy()` iniziano con un guard sulla modalità corrente
  (`isAutoTrackMode()`/`isAutoPoiMode()`). Chiamare `persistMode()` **prima**
  dell'assign nello stesso metodo `sync()` è sufficiente e corretto; l'ordine
  inverso produce una modalità persistita ma un pivot non ricalcolato.
  *Mitigazione:* requisito esplicito sopra + test dedicato che verifica il pivot
  dopo un salvataggio in `auto`.
  *Nota di implementazione (aggiornata a fine ciclo):* la correttezza
  dell'ordine ora dipende dal `refresh()` esplicito chiamato alla fine di
  `setTrackMode()`/`setPoiMode()` (vedi sotto), non da un evento Eloquent —
  `DB::statement()` con `jsonb_set` non passa per il ciclo di vita del model e
  non emette `saved()`.

- **Side-effect cross-relazione via `LayerObserver::saved()` — NON APPLICABILE
  a questa implementazione finale, nota storica.** Nella fase di design si era
  identificato un rischio ipotetico: se `setTrackMode()`/`setPoiMode()` avessero
  usato `$this->save()` (Eloquent), l'evento `saved()` avrebbe potuto far
  ripartire `assignPoisByTaxonomy()` per l'altra relazione (via `wasChanged()`
  generico in `hasTaxonomyActivitiesChanged()`). L'implementazione finale usa
  invece `DB::statement()` con `jsonb_set` (scrittura atomica a livello SQL, per
  il rischio di lost-update descritto sopra) seguito da `$this->refresh()`:
  **nessuna delle due operazioni passa per il ciclo di vita Eloquent**, quindi
  `LayerObserver::saved()` non scatta mai al cambio di modalità. Il rischio
  descritto in origine non si manifesta con questo codice. Resta comunque vero,
  indipendentemente da questo ticket, che `hasTaxonomyActivitiesChanged()` usa
  `wasChanged()` generico per altri percorsi di scrittura del layer (es. salvare
  altre proprietà via Nova) — non è stato introdotto né rimosso da oc:8314.

## Out of scope

- Modifica del comportamento distruttivo di `auto: true`
  (`assignTracksByTaxonomy` / `sync($ownedIds)` sovrascrive la selezione manuale):
  intenzionale e presidiato da `ConfirmModal`
- Migrazione dati per popolare `configuration` sui layer esistenti
- Estrazione in chiavi i18n dei messaggi Vue hardcoded in italiano
  (`Nova.success("Modalità automatica attivata")`, `"Features salvate con
  successo"`): debito preesistente, tracciato in `notes.md`
- Annotazioni generiche sulle relazioni Eloquent del package mancanti a PHPStan
  (già rimandate a ticket dedicato in oc:8312)
- Rimozione del fallback tassonomico in lettura di `getFeatures()`

## Moduli toccati

| File | Modifica |
|---|---|
| `src/Nova/Fields/LayerFeatures/src/Http/Controllers/LayerFeatureController.php` | nuovo `protected persistMode()`; `sync()`: validazione `manual` + chiamata a `persistMode()` |
| `src/Nova/Fields/LayerFeatures/resources/js/composables/useFeatures.ts` | `handleSave`: invio `manual: true` |
| `src/Nova/Fields/LayerFeatures/resources/js/components/LayerFeature.vue` | `handleToggleClick`: chiamata di persistenza sul verso auto → manuale, con rollback su errore |
| `src/Nova/Fields/LayerFeatures/dist/` | Rebuild con `npm run prod` |
| `config/wm-package.php` | Nuova chiave `default_layer_mode` (default `'auto'`) |

I test della logica risiedono nel repo principale
(`camminiditalia/tests/Feature/LayerFeatureControllerTest.php`): i test di questo
package non possono referenziare `Tests\TestCase` del repo principale, e
`Wm\WmPackage\Tests\TestCase` non è in `autoload-dev` di camminiditalia
(precedente: oc:8140).
