> Ticket: oc:8241

# Notes — Campo Nova custom Poi/Traccia (Model + Search filtrata)

## Deviazioni dal piano

- **Quarto pivot di scope nello stesso ciclo**: v1 "box `base` separato" → v2 "box unico a 4 sotto-tipi con `dependsOn()`" → v3 "due box separati, campi sempre visibili" → v4 (attuale) "tassonomie ripristinate all'originale (fuori scope), Poi/Traccia con campo Nova custom". Ogni pivot tracciato con overview.md/plan.md riscritti e riapprovati.
- **Tassonomie ripristinate esattamente all'originale**: `HorizontalScrollItemRepeatable.php`, `HorizontalScrollRepeaterJsonPreset.php` e i metodi corrispondenti in `App.php` sono stati recuperati da `git show HEAD:...` (nessuna modifica manuale, fedeltà garantita) — il ticket oc:8241 riguarda solo Poi/Traccia.
- **Blocco ambientale nella build del campo custom**: `laravel/nova-devtool` (necessario per `npm run prod`) non è installabile in questo ambiente — la licenza Nova configurata rifiuta il download del pacchetto dist (`Your license is not allowed to download this release!`) e il fallback su `git clone git@github.com:laravel/nova.git` fallisce per mancanza di accesso SSH nel container. **Il codice sorgente (PHP + Vue) è completo e corretto, ma il `dist/` compilato non esiste** — va generato in locale.

## Bug trovati

- Nessuno introdotto da questa feature.

## Decisioni

- **Campo Nova custom `GeoReferenceField`** (`wm-package/src/Nova/Fields/GeoReferenceField/`): un solo campo con attributo virtuale `geo_ref`, che scrive due attributi reali (`poi_id`, `track_id`) sul Fluent del Repeatable item via `fillAttributeFromRequest()` overridden — shape JSON di output invariata, `ConfigHomeResolver` non modificato (verificato con gli stessi test di prima, tutti verdi).
- **Filtro Model→Search interamente client-side**: nessuna chiamata AJAX, coerente con la richiesta del ticket di gestire la select condizionata; le opzioni Poi e Traccia sono entrambe precaricate nel payload del campo (`meta.poiOptions`/`meta.trackOptions`).
- **Namespace del campo**: `Wm\WmPackage\Nova\Fields\GeoReferenceField\src` per la classe field (in `src/Nova/Fields/GeoReferenceField/src/GeoReferenceField.php`) e `Wm\WmPackage\Nova\Fields\GeoReferenceField` per il `FieldServiceProvider` (in `src/Nova/Fields/GeoReferenceField/FieldServiceProvider.php`, root della cartella) — pattern verificato su `OrderList`/`TrackColor` esistenti nel package (namespace derivato dalla PSR-4 generale `Wm\WmPackage\` → `src/`, nessuna voce aggiuntiva in `composer.json` necessaria).
- **`Nova::mix()` con manifest assente è sicuro**: verificato in `vendor/laravel/nova/src/Concerns/InteractsWithAssets.php` — se `dist/mix-manifest.json` non esiste, il metodo non fa nulla (nessuna eccezione). Il pannello Nova resta funzionante anche prima della build; il campo semplicemente non compare/non ha stile finché il dist non viene generato.
- **Nessun componente Vue riusabile trovato nel package** per una vera ricerca AJAX — confermato che andava costruito ad hoc, come anticipato dal testo del ticket.

## Aggiornamento — ereditarietà title/image_url (revisione 4)

- **Gap trovato ricontrollando il ticket parola per parola**: il paragrafo "`ConfigHomeResolver` deve gestire la risoluzione item con `poi_id`/`track_id`: `title`/`image_url` ereditati dal modello EcPoi/EcTrack se non specificati come override" non era mai stato implementato in nessuna delle revisioni precedenti — era stato scritto "out of scope" nella primissima versione senza mai confrontarlo esplicitamente col testo del ticket. Corretto ora.
- **Implementazione**: `ConfigHomeResolver::fromGeoRepeaterItems()` ora eredita `title` da `getTranslations('name')` di EcPoi/EcTrack (riusando `mergeItemTitle()`, già generico) e `image_url` da `getFirstMediaUrl()` (nuovo `mergeItemImage()`, stesso principio: override se valorizzato, altrimenti default). Applicato al salvataggio, non a lettura, perché `AppConfigService::config_section_home()` legge il JSON già salvato senza richiamare il resolver (limite noto, invariato).
- **Verifica `getFirstMediaUrl()`**: confermato funzionante su dati reali (verificato via tinker su un EcPoi con media già presente in produzione). Il test automatico che simulava un upload di media (`UploadedFile::fake()->addMedia()->toMediaCollection()`) falliva con un errore (`Illuminate\Foundation\Application::originalFileName does not exist`) specifico dell'ambiente di test di questo pacchetto Spatie MediaLibrary — non correlato alla logica implementata. Sostituito con un test unitario diretto su `mergeItemImage()` (override vs default), che copre la stessa logica senza dipendere dalla catena di upload di test.
- **Help text aggiornati** in `HorizontalScrollGeoItemRepeatable` per riflettere l'ereditarietà (non più "sempre testo libero").

## Aggiornamento post-build (verificato)

- L'utente ha compilato il dist in locale (`composer install` + `npm run prod`, aggirando il blocco licenza Nova con un `composer.json` che punta al repo GitHub di `laravel/nova-devtool` invece che al repo privato Nova) e **verificato in Nova che il campo custom funziona**: il toggle Model Poi/Traccia filtra correttamente la select.
- Su richiesta dell'utente, la select a tendina iniziale è stata sostituita con un campo di ricerca testuale (stesso pattern di `IconSelect`): scrivi per filtrare, click per selezionare, il nome scelto resta visibile. **Verificato funzionante dall'utente in Nova.**
- Il blocco MinIO/icons.json incontrato durante il primo salvataggio era un problema d'ambiente scollegato dal codice (crash del runtime Go di MinIO per mismatch di piattaforma amd64/arm64 su Apple Silicon) — risolto rimuovendo il pin `platform: linux/amd64` da `develop.compose.yml` (repo principale, fuori da questo submodule).

## Follow-up rimasti aperti

1. Il rischio "riferimenti orfani nel config pubblico" (`AppConfigService::config_section_home()` bypassa `ConfigHomeResolver`) resta aperto, invariato dai cicli precedenti — con l'ereditarietà ora implementata AL SALVATAGGIO questo rischio si estende anche a title/image_url: se EcPoi/EcTrack cambia nome o immagine dopo il salvataggio, il config pubblico non si aggiorna finché qualcuno non risalva l'App in Nova (stesso limite già noto e accettato per le tassonomie).
2. Le stringhe nel componente Vue (`FormField.vue`, `DetailField.vue`, `IndexField.vue`) sono hardcoded in inglese, non passate per `__()` — coerente con `IconSelect` esistente (stesso limite), da considerare se serve i18n completa.
3. Il `composer.json` del campo custom generato dall'utente punta a `laravel/nova-devtool` via repository `vcs` GitHub diretto (bypassa il repo privato Nova) — funziona, ma è un workaround da tenere a mente se altri sviluppatori clonano il progetto e provano a ricompilare senza sapere del blocco licenza.

## Revisione 5 — Follow-up post-testing

Riferimento: `overview.md`/`plan.md` sezione "Revisione 5". Il ticket è tornato `progress` (assegnato a Carla) dopo 3 correzioni emerse dal team in fase di test (Peppe, Rubens) + 1 bug critico trovato durante la Fase: challenge di questo stesso round.

### Deviazioni dal piano

- **Blocco ambientale nella build, diverso da quello già noto**: `npm run prod` in `GeoReferenceField/` falliva con `Error [ERR_REQUIRE_ESM]` su `postcss.config.js` — non il blocco di licenza Nova già documentato sopra, ma un problema distinto: **`GeoReferenceField` era l'unico campo custom del package senza un proprio `postcss.config.js` locale** (`IconSelect`, `TrackColor`, `OrderList`, `LayerFeatures`, `FeatureCollectionMap` lo hanno tutti). Senza quel file, `postcss-loader` risale l'albero delle cartelle e trova il `postcss.config.js` della root di Forestas (ESM `export default`), incompatibile col caricamento CommonJS del subpackage. **Gap pre-esistente della PR #246 originale**, non introdotto da questo round — corretto aggiungendo `postcss.config.js` (`module.exports = {}`, stesso identico contenuto degli altri campi). Dopo il fix, `npm run prod` ha compilato senza errori.
- **`HasFlexibleTranslatableFields` non più usato in questa classe**: rimosso il `use` del trait da `HorizontalScrollGeoItemRepeatable` insieme al blocco `translatableFields()` per il titolo (ora readonly, non più un `KeyValue` editabile) — il trait resta ancora usato altrove nel package.

### Bug trovati

- **Bug critico pre-esistente (PR #246), trovato in Fase: challenge di questo round**: cambiare il toggle Model (Poi↔Traccia) su un item già salvato azzerava `selectedId` a `null` in `FormField.vue::selectModelType()`; se l'admin salvava senza riselezionare un valore, `ConfigHomeResolver::fromGeoRepeaterItems()` scartava l'item silenziosamente (nessun errore, nessun item nel box home). Fix in questo ciclo: `FormField.vue::fill()` non invia più il campo se `selectedId` è vuoto; `GeoReferenceField` in `HorizontalScrollGeoItemRepeatable::fields()` ha ora `->rules('required')`, verificato che Nova propaga correttamente la regola dentro un Repeater grazie a `Repeater::formatRules()` (letto in `vendor/laravel/nova/src/Fields/Repeater.php`, righe 204-254 — merge automatico delle rules di ogni field annidato con chiave `"{indice}.{attributo}"`).

### Decisioni

- **`modelOptions()`/`modelLabel()`: da `$model->name` a `$model->getTranslations('name')`** — `$model->name` (accesso magico Eloquent) passa dall'accessor di Spatie `HasTranslations`, che risolve **sempre** una stringa per la locale corrente (mai l'array grezzo delle traduzioni); il vecchio ramo `is_array($name)` in `modelLabel()` era quindi **codice morto**, mai realmente eseguito. Corretto leggendo `getTranslations('name')` esplicitamente, poi applicando la stessa cascade `it→en→prima disponibile` usata per il titolo readonly (nuovo metodo condiviso `cascadeTranslation()`). Scoperto perché il test `test_model_options_label_falls_back_through_all_configured_locales` (con nome valorizzato solo in `es`) falliva prima del fix.
- **`resolveInheritedTitle()` legge `$resource` come array/Fluent del Repeatable, non il model App**: confermato leggendo `vendor/laravel/nova/src/Fields/Repeater/Repeatable.php::resolveFields()` — il `resolveUsing` di un field dentro un Repeatable riceve `$this->data` (i dati della riga corrente), esattamente lo stesso oggetto già letto da `GeoReferenceField::resolveAttribute()` per `poi_id`/`track_id`.

### Verifica

- Test unit: 27 passati (`HorizontalScrollGeoItemRepeatableTest.php` + `ConfigHomeResolverGeoTest.php`, quest'ultimo invariato) — girati con `docker exec php-forestas vendor/bin/pest` (DB `wm_package`, isolato)
- Pint e PHPStan (livello del package): puliti sui file modificati
- Dist ricompilato e verificato per contenuto (`fill()` col guard `selectedId &&`, nessuna proprietà spuria, `mix-manifest.json` con entrambe le chiavi)
- **Verifica manuale in browser NON eseguita in questo ciclo** (nessun tooling da browser disponibile in questo ambiente) — resta da fare, coerente col fatto che il ticket va a Rubens per il test

### Traduzioni mancanti (trovate in verifica manuale utente)

Screenshot del box in Nova ha mostrato testo in inglese non tradotto. Due chiavi `__()` senza voce in `resources/lang/it.json`/`en.json`, aggiunte: help text del campo Model ("Choose Poi or Track, then search among that model's records." — pre-esistente alla PR #246, mai stata tradotta) e help text del nuovo campo Titolo readonly. Rimangono **deliberatamente non tradotte** (decisione utente, fuori scope): bottoni "Poi"/"Track", placeholder "Search a poi…"/"Search a track…", label "Add Horizontal Scroll Geo Item Repeatable" — hardcoded nel componente Vue, non passano per `__()`; tradurli richiederebbe passare le label da PHP come meta del campo, stesso limite già noto di `IconSelect`.

### Fuori scope (deliberato, vedi overview.md)

- Riferimenti orfani mostrati come campo vuoto, preload dataset senza paginazione, duplicati non impediti tra righe del repeater — tutti emersi in Fase: challenge, lasciati come rischio noto non risolto
- Correzione `tester_id` del ticket oc:8241: **ancora da fare** — serve l'user_id Orchestrator di Rubens (nessun endpoint di ricerca utenti disponibile su Orchestrator per dedurlo)

## Revisione 6 — Fix box_type per compatibilità frontend (2026-07-20)

Riferimento: `overview.md`/`plan.md` sezione "Revisione 6". Il ticket è tornato bloccato dopo la review (`wm-review-ticket`) di Rubens Garofalo del 2026-07-15: codice PHP/Nova/test tutti corretti, ma bug bloccante sul valore di `box_type` scritto nel `config.json` pubblico.

### Deviazioni dal piano

- Nessun pivot di scope: fix puntuale su un valore di enum, nessuna modifica di struttura dati.

### Bug trovati

- **Bug bloccante segnalato da Rubens Garofalo (review)**: `ConfigHomeResolver::buildGeoElement()`/`finalizeGeoElement()` scrivevano `box_type: "horizontal_scroll_geo"`, valore inesistente nel front-end (`wm-core`) — il box configurato in Nova risultava invisibile in app, senza errori a schermo. Il valore corretto atteso dal front-end è `"base"`.
- **Effetto collaterale non coperto dal fix minimo suggerito in review**: cambiare solo il lato scrittura avrebbe rotto il lato lettura (`resolveLayoutName()`), perché quest'ultimo usa `$item['box_type']` direttamente come nome del layout Nova da cercare — con `box_type: 'base'` non avrebbe trovato nessun layout corrispondente (nessun layout Nova si chiama `'base'`), facendo sparire silenziosamente l'item dal form Nova alla riapertura. Stessa classe di bug della "perdita silenziosa dell'item" già corretta in Revisione 5. Corretto estendendo anche `resolveLayoutName()`, `getAttributesForItem()` e `previousGeoItemsForGroup()`.

### Decisioni

- **Retrocompatibilità sul lato lettura**: mantenuto il riconoscimento del vecchio valore `'horizontal_scroll_geo'` accanto al nuovo `'base'` in tutti i punti di lettura, per non rompere l'edit Nova dei `config_home` già salvati in produzione con il valore precedente. Nessuna migration attiva sui dati esistenti — il prossimo salvataggio in Nova riscrive automaticamente il valore corretto.
- **Verifica indipendente del contratto frontend prima di applicare il fix**: letto direttamente il codice sorgente di `wm-core` via GitHub (`gh api`) invece di fidarsi solo del testo della review — confermato che `IBOX.box_type` non include mai `'horizontal_scroll_geo'`, che `home-landing.component.html` monta `wm-features-box` solo su `box_type === 'base'`, e che l'intera catena di rendering (`FeaturesBoxComponent` → `BoxComponent`) legge solo `title`/`image_url`/`poi_id`/`track_id` — nessun altro campo richiesto nonostante il tipo TS `IHOMEITEMFEATURE` ne dichiari altri come obbligatori (non utilizzati da questa catena).

### Verifica

- Test unit: 27 passati (`ConfigHomeResolverGeoTest.php` + `HorizontalScrollGeoItemRepeatableTest.php`, invariati) — girati con `docker exec php-forestas vendor/bin/pest` (DB `wm_package`, isolato)
- Verifica manuale in browser: non eseguita in questo ciclo (nessun tooling da browser disponibile in questo ambiente) — resta da fare da parte di Rubens in re-review
- Commit: `5bc2e50a` su branch `oc_8241`
- Ticket Orchestrator aggiornato: status → `testing`, nota dev con dettagli del fix

### Follow-up rimasti aperti

- Nessuno nuovo introdotto da questo round, oltre a quelli già tracciati in Revisione 5.

## Revisione 7 — Rename "Geo" → "PoiTrack" (2026-07-22)

Riferimento: `overview.md` sezione "Revisione 7". Innescato da feedback diretto dell'utente in re-review: il naming interno "Geo" leakava nella UI Nova (pulsante "Add Horizontal Scroll Geo Item Repeatable").

### Deviazioni dal piano

- Nessun piano formale scritto per questo round (rename mirato, eseguito interattivamente durante la re-review su richiesta esplicita dell'utente) — documentato qui a posteriori invece che in un `plan.md` dedicato, coerente con la scala ridotta del cambiamento.

### Decisioni

- **Perché "PoiTrack" e non un'altra parola**: chiesto esplicitamente all'utente tra 3 opzioni (`PoiTrack`, `Reference`, "nessuna parola" con collisione da risolvere sul repeatable delle tassonomie) — scelto `PoiTrack` perché descrive esplicitamente cosa referenzia il box.
- **Rinominato anche `GeoReferenceField`** (il campo Nova custom, non solo il Repeatable): chiesto esplicitamente all'utente se limitare il rename al solo Repeatable o estenderlo anche al campo — confermato "sì, rinomina anche" nonostante il costo di un rebuild dist obbligatorio.
- **Distinzione dato persistito vs identificatore di codice**: verificato riga per riga in `ConfigHomeResolver.php` quali occorrenze di "Geo" fossero (a) il valore letterale `box_type: 'horizontal_scroll_geo'` già scritto nei `config_home` di produzione (revisione 6) — intoccabile, o (b) nomi di classi/metodi/chiavi di registro Nova puramente interni, mai persistiti — rinominabili senza rischio di rottura sui dati esistenti. Solo la categoria (b) è stata rinominata.
- **Sblocco ambientale della build dist**: il blocco licenza Nova documentato nel ciclo originale (`laravel/nova-devtool` non scaricabile) non si è ripetuto in questo round — `auth.json` con credenziali Nova valide era già presente nella root del progetto maphub. Cambiato temporaneamente `composer.json` del campo custom da repository `vcs` GitHub (workaround del round originale) a repository `composer` privato Nova (`https://nova.laravel.com`), eseguito `composer install` dal container Docker (PHP 8.4, il `composer`/`php` di host è 7.4 e non risolve i requisiti), poi `npm install && npm run prod`. `vendor/`, `node_modules/`, `auth.json` locale e `composer.lock` restano ignorati da `.gitignore`, coerente con gli altri campi custom.

### Bug trovati

- Nessuno introdotto da questo round — rename puro, nessuna logica di business toccata. Il dispatcher `ConfigHomeResolver::buildElement()` (match su `$layout->name()`) e `resolveLayoutName()` sono stati aggiornati in coppia per usare la stessa nuova chiave di registro `'horizontal_scroll_poi_track'`, altrimenti si sarebbe ripetuta esattamente la classe di bug già corretta in revisione 6 (mismatch tra chiave di scrittura e chiave di lettura).

### Verifica

- `php -l`, Pint (10/10 file), autoload via tinker, dist ricompilato e verificato via grep, Nova boot OK — vedi `overview.md` per il dettaglio completo
- **Test automatici del package non eseguiti** (nessun DB isolato `wm_package` disponibile in questo ambiente) — da eseguire prima del merge finale
- PHPStan non eseguibile in questo ambiente per un problema di cache pre-esistente e non correlato (`build/phpstan/cache` — permessi/worker paralleli), confermato non causato da questo rename

### Follow-up rimasti aperti

- Eseguire la suite di test del package (`ConfigHomeResolverPoiTrackTest`, `HorizontalScrollPoiTrackItemRepeatableTest`, `PoiTrackReferenceFieldTest`) in un ambiente con DB `wm_package` isolato, prima del merge
- Verifica manuale in browser del rename (invariato dal limite già noto delle revisioni precedenti)
