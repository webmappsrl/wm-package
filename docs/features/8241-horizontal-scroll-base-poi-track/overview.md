> Ticket: oc:8241

# Horizontal Scroll: campo Poi/Traccia custom (Model + Search filtrata)

**Revisione 3:** ristretta esattamente al testo del ticket oc:8241, che riguarda **solo** Poi/Traccia. Le tassonomie (`horizontal_scroll_activities`/`horizontal_scroll_poi_types`) sono state **ripristinate esattamente come erano prima di questo ticket** (non menzionate nel ticket, la loro unificazione era una richiesta emersa durante i test ma non necessaria) — ripristinate da `git show HEAD:...` per garantire fedeltà all'originale.

## Perché un campo Nova custom

Il ticket chiede letteralmente:

> 1. Campo "Model" (Poi/Track) per scegliere il tipo di riferimento
> 2. Due campi Search condizionati dal Model scelto
> 3. Helper text mutua esclusività
> Impatto lato frontend Nova (JS/dist): da verificare se esiste già un componente Search riusabile nel package o va costruito ad hoc.

Il meccanismo nativo di Nova per campi condizionati (`dependsOn()`) non funziona per campi annidati dentro un Repeater di un Flexible content (verificato in `vendor/laravel/nova/src/Http/Controllers/UpdateFieldController.php` — cerca il campo dipendente solo tra i field di primo livello della risorsa). L'unica strada per ottenere il comportamento richiesto dal ticket (seleziona Model → la select si filtra) è un **campo Nova custom con componente Vue proprio**, che gestisce il filtro interamente lato client (tutte le opzioni Poi/Traccia sono già precaricate in pagina, nessuna chiamata al server necessaria per il filtro).

**Revisione 4 (correzione):** rileggendo il testo del ticket per una verifica finale, è emerso che il paragrafo "`ConfigHomeResolver` deve gestire la risoluzione item con `poi_id`/`track_id`: `title`/`image_url` ereditati dal modello EcPoi/EcTrack se non specificati come override — stessa logica già introdotta in oc:8223 per le tassonomie" non era mai stato implementato né discusso esplicitamente nelle revisioni precedenti (era stato scritto "out of scope" senza confronto col ticket). Aggiunto ora: vedi sezione "Ereditarietà title/image_url" sotto.

## Ereditarietà title/image_url (revisione 4)

- `EcPoi`/`EcTrack` hanno entrambi un campo `name` translatable (Spatie `HasTranslations`) — fonte per il title di default, tramite `getTranslations('name')` (dict `{it, en}`), stesso pattern già usato per le tassonomie.
- Nessuna media collection dedicata "featured" su EcPoi/EcTrack (solo una collection generica `default`, registrata da `GeometryModel`) — fonte per l'image_url di default tramite `getFirstMediaUrl()` (default collection, pattern già in uso altrove nel package, es. oc:7480).
- L'ereditarietà va applicata **al salvataggio**, dentro `ConfigHomeResolver::fromGeoRepeaterItems()`, riusando `mergeItemTitle()` (già generico, non specifico alle tassonomie) e un nuovo `mergeItemImage()` analogo per l'image_url — **non** a lettura, perché `AppConfigService::config_section_home()` legge il JSON già salvato senza mai richiamare il resolver (stesso comportamento bypassato già noto per le tassonomie, oc:8223). Il valore risolto va quindi "cotto" nel JSON al momento del save, esattamente come già succede per `mergeItemTitle()` sulle tassonomie.
- Se il modello collegato non ha un nome/immagine disponibile (caso raro), il comportamento è analogo alle tassonomie: titolo vuoto → item scartato, coerente con `fromRepeaterItems()` esistente.

## Cosa cambia

- Nuovo campo Nova custom `GeoReferenceField` (nome provvisorio), sostituisce i due `Select` sempre visibili (`poi_id`/`track_id`) in `HorizontalScrollGeoItemRepeatable`.
- UI: due pulsanti/radio "Poi" / "Traccia" (il "Model") + un'unica select filtrata che mostra solo le opzioni del tipo scelto (dataset Poi e Traccia entrambi precaricati lato client, filtro reattivo Vue, nessuna richiesta AJAX).
- Il campo scrive **due attributi separati** (`poi_id`, `track_id`) sul Fluent del Repeatable item, esattamente come oggi — shape JSON di output invariata (`{title, image_url, poi_id}` o `{title, image_url, track_id}`); lo scoping `app_id` in `ConfigHomeResolver` resta invariato, ma la risoluzione ora applica anche l'ereditarietà title/image_url (vedi sopra).
- In edit di un item esistente, il campo deduce automaticamente se mostrare "Poi" o "Traccia" da quale dei due attributi è valorizzato nel dato salvato.
- Validazione mutua esclusività: gestita nel componente stesso (un solo widget, non due campi separati che potrebbero essere entrambi valorizzati) — la guardia server-side in `ConfigHomeResolver::fromGeoRepeaterItems()` resta comunque come rete di sicurezza.

## Requisiti

- [ ] Nuovo campo Nova custom in `wm-package/src/Nova/Fields/GeoReferenceField/` (struttura standard dei campi custom del package: `src/GeoReferenceField.php`, `src/FieldServiceProvider.php`, `resources/js/field.js`, `resources/js/components/{FormField,DetailField,IndexField}.vue`, `webpack.mix.js`, `package.json`, `dist/` compilato)
- [ ] PHP: il campo espone metodi per configurare le opzioni Poi/Traccia (`->poiOptions(array)->trackOptions(array)`) e gestisce `fillInto()` per scrivere `poi_id`/`track_id` sul Fluent a partire dal valore JSON `{type, id}` inviato dal componente Vue
- [ ] PHP: `resolve()` legge `poi_id`/`track_id` dalla riga idratata e li serializza in `{type, id}` per il componente Vue
- [ ] Vue: toggle Model (Poi/Traccia) + select filtrata client-side, nessuna chiamata AJAX
- [ ] Registrare il `FieldServiceProvider` del nuovo campo (composer.json `extra.laravel.providers` o discovery automatico, verificare pattern esistente in `IconSelect`/`BboxField`)
- [ ] `HorizontalScrollGeoItemRepeatable`: sostituire i due `Select::make('poi_id')`/`Select::make('track_id')` con il nuovo campo custom
- [ ] Build `npm run prod` nella cartella del nuovo campo, dist committata (verificare che non contenga proprietà spurie, prassi già seguita per gli altri campi custom del package — vedi decisione oc:8093 in `wm-package/CLAUDE.md`)
- [ ] Verifica manuale in browser: toggle Model cambia le opzioni mostrate, salvataggio scrive l'attributo corretto, edit di un item esistente mostra il Model corretto pre-selezionato
- [ ] Test: PHP-side (fillInto/resolve), se ragionevolmente testabili senza montare Vue; per la parte Vue nessun test automatico (fuori scope, coerente con gli altri campi custom del package che non hanno test JS)
- [ ] `ConfigHomeResolver::fromGeoRepeaterItems()`: ereditare `title` (da `getTranslations('name')`) e `image_url` (da `getFirstMediaUrl()`) da EcPoi/EcTrack quando l'item non ha un override, riusando `mergeItemTitle()` e un nuovo `mergeItemImage()`
- [ ] Help text dei campi `title`/`image_url` in `HorizontalScrollGeoItemRepeatable` aggiornato per riflettere l'ereditarietà (non più "sempre testo libero")
- [ ] Test per l'ereditarietà: title/image ereditati quando vuoti, override quando valorizzati, item scartato se il modello non ha nome risolvibile

## Rischi

- **Primo campo Nova custom di questo ciclo usato dentro un Repeater annidato in un Flexible content**: nessun precedente diretto nel package per questa combinazione specifica (gli altri campi custom — `BboxField`, `FeatureCollectionMap`, `IconSelect` — sono usati come campi diretti di risorsa, non dentro Repeatable). Il meccanismo di resolve/fill di un Field generico (`Repeatable::resolveFields()`, `JSON::set()`) non fa distinzioni sul tipo di campo, quindi dovrebbe funzionare, ma va verificato in browser per primo, prima di considerare la feature completa.
- **Manutenzione futura**: un campo custom richiede build JS (`npm run prod`) a ogni modifica — costo di manutenzione più alto di un campo Nova nativo, accettato perché richiesto esplicitamente dal ticket.
- **Dist non ricompilato correttamente**: rischio noto e già capitato nel package (oc:8093) — verificare sempre che il dist committato corrisponda esattamente alla sorgente Vue prima di considerare il task completo.

## Out of scope

- Ricerca AJAX asincrona reale (il filtro resta client-side su dataset precaricato, coerente con la decisione già presa)
- Applicare lo stesso campo custom al box Tassonomie (non richiesto dal ticket, tassonomie restano con la UI originale pre-esistente)
- Test automatici del componente Vue (nessun precedente nel package per gli altri campi custom)

## Moduli toccati

- `wm-package/src/Nova/Fields/GeoReferenceField/**` (nuovo, struttura completa campo custom)
- `wm-package/src/Nova/Flexible/ConfigHome/HorizontalScrollGeoItemRepeatable.php` — sostituire i 2 Select con il nuovo campo
- `wm-package/src/Nova/Flexible/Resolvers/ConfigHomeResolver.php` — `fromGeoRepeaterItems()` esteso per l'ereditarietà title/image_url da EcPoi/EcTrack (nuovo `mergeItemImage()`), shape JSON di output invariata
- `wm-package/composer.json` — registrazione discovery del nuovo `FieldServiceProvider`, se richiesto dal pattern esistente
- `wm-package/tests/Unit/Nova/Flexible/HorizontalScrollGeoItemRepeatableTest.php` — aggiornare per il nuovo campo (rimuovere test su Select separati non più applicabili)

## File ripristinati (fuori scope di questo ticket)

- `wm-package/src/Nova/Flexible/ConfigHome/HorizontalScrollItemRepeatable.php`
- `wm-package/src/Nova/Flexible/ConfigHome/HorizontalScrollRepeaterJsonPreset.php`
- `wm-package/src/Nova/App.php`: `horizontal_scroll_activities_layout()`, `horizontal_scroll_poi_types_layout()`, `horizontalScrollItemsRepeater()`, `horizontalScrollActivityOptions()`, `horizontalScrollPoiTypeOptions()`, `getTaxonomyLabel()`, registrazione dei due `addLayout` originali

## Revisione 5 — Follow-up post-testing (2026-07-15)

Il ticket è tornato a `progress` dopo il testing per 3 correzioni emerse dal confronto col team (Peppe, Rubens). Tutte e tre confinate lato Nova/PHP, nessun impatto sul componente Vue esistente (`FormField.vue`/`DetailField.vue`/`IndexField.vue` restano invariati) né su `ConfigHomeResolver` (la logica di ereditarietà `mergeItemTitle()`/`mergeItemImage()` già gestisce correttamente un campo `title` assente dal payload, verificato leggendo `extractGeoRepeaterFields()` — nessuna modifica al resolver necessaria).

### 1. Campo Titolo: da editabile a readonly (post-salvataggio)

Il campo `title` in `HorizontalScrollGeoItemRepeatable::fields()` (attualmente un `KeyValue` editabile via `translatableFields()`) non deve più permettere l'override manuale — il titolo è sempre quello del modello Poi/Traccia collegato. Comportamento concordato:

- **Prima del primo salvataggio** (item nuovo, non ancora persistito): nessun campo/testo visibile — coerente con quanto osservato in call.
- **Dopo il salvataggio**: appare un `Text::make(__('Title'))->readonly()->resolveUsing(...)` che mostra il titolo ereditato, con help text che spiega la provenienza dal modello.
- **Lingua mostrata**: solo la lingua di default dell'app (`it`, da `APP_LOCALE`), non tutte le 5 lingue configurate (`it/en/fr/es/de`) — a differenza del `KeyValue` multi-lingua attuale.
- **Fallback traduzione mancante**: cascade `it → en → prima lingua disponibile` (pattern Spatie `HasTranslations::getTranslation('name', 'it', fallback: true)`, già in uso altrove nel package, es. `LayerAnalyticsCard` oc:7648).
- Il valore resta **sempre inizializzato nel JSON** col titolo del modello — questa parte era già implementata (`mergeItemTitle()` in `ConfigHomeResolver` eredita già quando il campo è assente/vuoto), il cambio è **solo lato builder Nova**.

### 2. Terminologia: "Reference" → "Model" (solo label)

`GeoReferenceField::make(__('Reference'), 'geo_ref')` → `GeoReferenceField::make(__('Model'), 'geo_ref')` in `HorizontalScrollGeoItemRepeatable.php:36`. Solo la label tradotta cambia — class name (`GeoReferenceField`), attributo (`geo_ref`), namespace e componente Vue (`geo-reference-field`) restano invariati (scope volutamente ridotto, rifiuto esplicito del rename completo per rischio/beneficio). La chiave di traduzione `"Model"` esiste già in `resources/lang/{it,en}.json` (riga 99) — nessuna nuova voce di traduzione richiesta.

### 3. Immagine: nessuna modifica

Verificato nel codice: `Text::make(__('Image URL'), 'image_url')->nullable()->help(...)` in `HorizontalScrollGeoItemRepeatable.php:47-49` è già editabile con help text sull'ereditarietà. Già conforme, nessuna azione.

### Requisiti (revisione 5)

- [ ] `HorizontalScrollGeoItemRepeatable::fields()`: rimuovere il `KeyValue` editabile per `title`, sostituire con `Text::make(__('Title'), 'title')->readonly()->resolveUsing(fn ($value, $resource) => ...)` che risolve il titolo dal Poi/Traccia collegato con cascade `it→en→prima disponibile`, vuoto se l'item non è ancora salvato
- [ ] Label del campo Model: `__('Reference')` → `__('Model')`
- [ ] Test unit in `HorizontalScrollGeoItemRepeatableTest.php`: label del campo Model aggiornata, campo titolo readonly con cascade di fallback, campo titolo vuoto per un item nuovo/non salvato
- [ ] **Fix bug perdita silenziosa dell'item al cambio Model** (trovato in Fase: challenge, non nei 3 punti originali del ticket): `FormField.vue::selectModelType()` azzera `selectedId` a `null` quando l'admin cambia Poi↔Traccia su un item esistente; se salva senza riselezionare un valore, `fill()` invia `{type: null, id: null}` e `ConfigHomeResolver::fromGeoRepeaterItems()` scarta l'item senza alcun errore visibile. Fix: (a) `FormField.vue::fill()` non invia il JSON `{type,id}` se `selectedId` è vuoto (appende stringa vuota/niente), (b) `GeoReferenceField` in `HorizontalScrollGeoItemRepeatable.php` riceve `->rules('required')` così un item con Model cambiato ma nessuna nuova selezione blocca il salvataggio con errore di validazione Nova invece di sparire silenziosamente. Verificare in browser che la validazione si propaghi correttamente dentro un Repeater annidato in un Flexible (stessa cautela già nota per `dependsOn()`).
- [ ] **Fallback multilingua coerente**: allineare `HorizontalScrollGeoItemRepeatable::modelOptions()`/`modelLabel()` (oggi cascade `it→en→#id`) alla stessa cascade `it→en→prima lingua disponibile` usata per il titolo readonly, per coerenza tra le due parti del box che leggono lo stesso nome del modello.

### Rischi (revisione 5)

- **Titolo readonly non reattivo**: come per la UI attuale, un `Text::readonly()->resolveUsing()` risolve una sola volta al caricamento pagina dai dati salvati sul DB — se l'admin cambia Poi/Traccia nel widget e salva, il titolo aggiornato compare solo dopo il refresh della pagina (limite accettato esplicitamente, coerente col comportamento già presente per il resto del box).
- **Determinare "item non ancora salvato"**: il `resolveUsing()` deve distinguere un item nuovo (nessun `poi_id`/`track_id` risolvibile ancora) da uno esistente con titolo vuoto per altre ragioni (es. modello senza traduzioni) — va verificato in browser che il campo non mostri un readonly vuoto fuorviante anche per item esistenti con modello privo di nome in tutte le lingue (caso limite raro, stesso comportamento di scarto già esistente in `mergeItemTitle()` quando `$title === []`).
- **Validazione `required` dentro un Repeater annidato**: non c'è un precedente diretto nel package di regole di validazione su un campo custom dentro Repeater > Flexible — va verificato che Nova la applichi correttamente e mostri l'errore nel punto giusto della UI (stesso genere di limite già documentato per `dependsOn()` in questo stesso box).
- **Riferimenti orfani mostrati come vuoti (non risolto in questo ciclo)**: se `poi_id`/`track_id` punta a un record di un'altra app o cancellato, `FormField.vue::labelFor()` e la resolve di `DetailField`/`IndexField` mostrano il campo vuoto/`—`, identico a un item mai valorizzato — rischio che un admin sovrascriva inconsapevolmente un riferimento esistente pensando di impostarlo per la prima volta. Emerso in Fase: challenge, deliberatamente lasciato fuori scope (vedi Out of scope).
- **Preload dataset senza paginazione (non risolto in questo ciclo)**: `modelOptions()` carica l'intero dataset Poi/Traccia dell'app per ogni riga del repeater, senza limite — costo che cresce con la dimensione del dataset e col numero di righe. Nessun problema noto oggi con i volumi Forestas, ma non quantificato: da rivalutare se il box home cresce molto o su app con dataset molto più grandi.
- **Duplicati non impediti (non risolto in questo ciclo)**: nulla vieta di selezionare lo stesso Poi/Traccia in due righe diverse del repeater.

### Out of scope (revisione 5)

- Rename completo di classe/namespace/componente Vue da "Reference" a "Model" (rischio non giustificato per un cambio richiesto solo a livello di label)
- Vista multi-lingua del titolo readonly (mostrata solo la lingua di default, non le 5 configurate)
- Reattività del titolo readonly al cambio di selezione Poi/Traccia prima del salvataggio
- Correzione `tester_id` del ticket oc:8241 (gestita separatamente come aggiornamento Orchestrator, non è una modifica di codice)
- Segnalazione visiva di riferimenti orfani (poi_id/track_id non risolvibile tra le opzioni caricate) — emerso in Fase: challenge, rischio noto non affrontato in questo ciclo
- Paginazione/lazy-loading delle opzioni Poi/Traccia nel campo custom — emerso in Fase: challenge, nessun problema di performance noto oggi
- Prevenzione di selezione duplicata dello stesso Poi/Traccia in più righe del repeater — emerso in Fase: challenge, non richiesto dal ticket

## Revisione 6 — Fix box_type per compatibilità frontend (2026-07-20)

Review (`wm-review-ticket`) di Rubens Garofalo del 2026-07-15 ha trovato 1 bug bloccante non coperto dalle revisioni precedenti: il `box_type` scritto in `config.json` da `ConfigHomeResolver::buildGeoElement()`/`finalizeGeoElement()` era `"horizontal_scroll_geo"` — valore che non esiste nel front-end (webmapp-app, submodule `wm-core`). Il box configurato in Nova risultava quindi invisibile in app, senza alcun errore visibile.

### Cosa cambia (revisione 6)

- `box_type` scritto nel JSON pubblico cambia da `"horizontal_scroll_geo"` a `"base"` — l'unico valore che il front-end riconosce per il box Poi/Traccia singolo (`wm-features-box`, montato da `*ngIf="box.box_type === 'base'"` in `home-landing.component.html`).
- La chiave Nova-interna `addLayout(..., 'horizontal_scroll_geo', ...)` in `App.php` resta invariata — è solo il nome del layout lato builder Nova, distinto dal valore scritto nel JSON pubblico.
- Il lato lettura (`resolveLayoutName()`, `getAttributesForItem()`, `previousGeoItemsForGroup()`) riconosce sia `'base'` (nuovo) sia `'horizontal_scroll_geo'` (vecchio) per non rompere l'edit in Nova dei `config_home` già salvati in produzione con il valore precedente.

### Verifica (revisione 6)

Prima di applicare il fix, verificato direttamente il codice sorgente di `wm-core` (via GitHub, non solo la review testuale):
- `IBOX.box_type` (in `projects/wm-core/src/types/config.ts`) elenca i soli valori validi: non include mai `'horizontal_scroll_geo'`.
- `home-landing.component.html` monta `<wm-features-box>` solo su `box_type === 'base'`.
- `FeaturesBoxComponent` → `BoxComponent` leggono solo `title`, `image_url`, `poi_id`/`track_id` — tutti già prodotti dal resolver, nessun campo aggiuntivo richiesto nonostante `IHOMEITEMFEATURE` dichiari altri campi (`taxonomy_activities`, `taaxonomy_where`, `distance`, `cai_scale`) nel tipo TS: non sono letti da nessun componente in questa catena di rendering, quindi non necessari.

### Requisiti (revisione 6)

- [x] `buildGeoElement()`/`finalizeGeoElement()` scrivono `box_type: 'base'`
- [x] Lato lettura aggiornato per riconoscere sia `'base'` che `'horizontal_scroll_geo'` (retrocompatibilità dati già in produzione)
- [x] Test automatici invariati verdi (27/27)

### Rischi (revisione 6)

- Nessun nuovo rischio introdotto — fix isolato al valore di una chiave, nessuna modifica di struttura dati.

### Out of scope (revisione 6)

- Migrazione dei `config_home` già salvati in produzione con `box_type: 'horizontal_scroll_geo'` al nuovo valore `'base'` — gestita per compatibilità in lettura, non serve una migration attiva perché il prossimo salvataggio in Nova riscrive automaticamente il valore corretto.

## Revisione 7 — Rename "Geo" → "PoiTrack" nel naming interno (2026-07-22)

In re-review (`wm-review-ticket`), l'utente ha notato che il naming interno "Geo" (residuo della revisione 6, quando il box_type persistito era ancora `horizontal_scroll_geo`) leakava nella UI Nova: il pulsante "Add" del Repeater mostrava "Add Horizontal Scroll Geo Item Repeatable" (Nova genera questa label di default umanizzando il nome della classe `HorizontalScrollGeoItemRepeatable`). Richiesta esplicita: rimuovere "Geo" da tutto il naming interno della feature, non solo dalla label.

### Cosa cambia (revisione 7)

Rinominate tutte le identità interne che usavano "Geo" (classi, file, metodi privati, chiave di layout Nova, attributo virtuale) in "PoiTrack":

| Prima | Dopo |
|---|---|
| `HorizontalScrollGeoItemRepeatable` | `HorizontalScrollPoiTrackItemRepeatable` |
| `HorizontalScrollGeoRepeaterJsonPreset` | `HorizontalScrollPoiTrackRepeaterJsonPreset` |
| `GeoReferenceField` (+ cartella, componente Vue `geo-reference-field`, composer.json/package.json) | `PoiTrackReferenceField` (+ `poi-track-reference-field`) |
| `ConfigHomeResolver::buildGeoElement()`/`finalizeGeoElement()`/`toGeoRepeaterItems()`/`fromGeoRepeaterItems()`/`extractGeoRepeaterFields()`/`previousGeoItemsForGroup()` | equivalenti `*PoiTrack*`/`*PoiTrackElement()` |
| Chiave layout Nova `'horizontal_scroll_geo'` (2° argomento di `addLayout()`, interno, non persistito) | `'horizontal_scroll_poi_track'` |
| Attributo virtuale del campo `'geo_ref'` | `'model_ref'` |

**Non toccato — dato persistito**: il valore letterale `box_type: 'horizontal_scroll_geo'` nei `config_home` già salvati in produzione (revisione 6) resta riconosciuto in lettura per retrocompatibilità (`resolveLayoutName()`, `getAttributesForItem()`, `previousPoiTrackItemsForGroup()` accettano sia `'base'` che il legacy `'horizontal_scroll_geo'`). Rinominare il naming interno non ha alcun effetto su questo valore: è un dato, non un identificatore di codice.

`label()`/`singularLabel()` del Repeatable sono stati overridden per riusare la label già esistente `__('Horizontal Scroll Poi/Track')` (stessa mostrata nel picker layout del Flexible), cosi il pulsante "Add" in Nova non mostra più il nome classe umanizzato di default.

### Verifica (revisione 7)

- `php -l` su tutti i file PHP toccati: OK
- Pint: 10/10 file puliti
- Autoload verificato via tinker: le nuove classi si caricano correttamente (`class_exists`), la vecchia `GeoReferenceField` non esiste più
- Dist del campo Nova **ricompilato** (`npm run prod`, dopo aver ripristinato `vendor/laravel/nova-devtool` via `composer install` con le credenziali Nova già presenti in `auth.json` root — non più bloccato come nei round precedenti): grep confermato, nuovo nome `poi-track-reference-field` presente 3 volte nel bundle, vecchio nome assente
- Nova boot verificato (`config:clear` + risoluzione servizio Nova) senza errori
- **Test automatici del package NON eseguiti in questo round**: richiedono `RefreshDatabase` su un DB isolato `wm_package` non configurato in questo ambiente Docker locale — puntare al DB `maphub` di sviluppo reale senza un `.env.testing` dedicato sarebbe stato rischioso. Da eseguire in un ambiente con DB isolato prima del merge finale.
- Verifica manuale in browser: non eseguita (stesso limite ambientale delle revisioni precedenti)

## Revisione 8 — Fix salvataggio Title del box, due bug preesistenti in Detail (2026-07-23)

Durante il test manuale post-revisione 7 sono emersi tre problemi distinti, tutti **preesistenti** (nessuno introdotto dal rename di revisione 7 — verificato confrontando col commit originale `87eb29ad` del 14/07): due sul rendering in pagina Detail, uno sul salvataggio del campo "Title" del box (condiviso da tutti i box `horizontal_scroll_*`, non specifico a Poi/Track).

### Bug 1 — `showOnDetail()` mancante sul Repeater "Elementi"

`Repeater::make()` di Nova ha `onlyOnForms()` nel costruttore (nascosto da index/detail per default) — un preset custom (`->preset(new ...)`) non lo riattiva automaticamente come farebbe `->asJson()`. Il repeater "Elementi" non è quindi mai stato visibile nella pagina Detail, da quando la feature esiste (round 1, non da questa revisione). Fix: aggiunto `->showOnDetail()` a `horizontalScrollPoiTrackItemsRepeater()` in `App.php`. **Lo stesso identico bug esiste nel box a tassonomie originale** (`horizontalScrollItemsRepeater()`, mai toccato da oc:8241) — non corretto lì, fuori scope, segnalato all'utente.

### Bug 2 — `extractRawItems()` non gestiva l'array

`Layout::resolveForDisplay()` (usato dalla pagina Detail) passa ai field un **array semplice** di attributi; `Layout::resolve()` (usato dal form di edit) passa invece l'**oggetto Layout**. `HorizontalScrollPoiTrackRepeaterJsonPreset::extractRawItems()` faceva `if (! is_object($model)) return null;`, quindi in Detail falliva silenziosamente e cadeva nel fallback nativo di Nova, che va in `ErrorException` fatale (`Attempt to read property "items" on array`) su un array. Il bug 1 mascherava completamente questo bug (il codice non veniva mai eseguito finché il field restava `onlyOnForms()`). Fix: `extractRawItems()` ora gestisce esplicitamente il caso array (`is_array($model)` → `$model[$attribute] ?? null`). **Stesso bug presente anche in `HorizontalScrollRepeaterJsonPreset::extractRawItems()`** (tassonomie, mai toccato da oc:8241) — non corretto lì, fuori scope.

Verificato con un test HTTP reale (richiesta autenticata a `/nova-api/apps/{id}` in `editing=true` mode, che nel round di test risultava comunque instradata come Detail/`resolveForDisplay` da Nova): status 500 → 200 dopo il fix, 3 item recuperati correttamente nel payload.

### Bug 3 — Salvataggio del campo "Title" del box corrotto

Bug nel trait condiviso `HasFlexibleTranslatableFields` (usato dal titolo di **tutti** i box `horizontal_scroll_*`, non specifico a Poi/Track), in due parti:

1. **Causa radice**: il campo `KeyValue::make()` aveva la colonna delle chiavi (it/en/fr/es/de) **editabile** — nessun `keyLabel` visibile per guidare l'admin (`->keyLabel('')`), quindi digitando nella riga si finiva per sovrascrivere anche il codice lingua stesso. Risultato osservato in produzione: `{"titolo it": "titolo it", "titolo en": "titolo en", ...}` invece di `{"it": "titolo it", "en": "titolo en", ...}` — chiave e valore identici, nessuna lingua valida. Riprodotto e confermato al 100% simulando la richiesta di salvataggio reale (stesso pattern, valori diversi, già visto una volta in produzione con `{"test poi track": "test poi track de"}`). Fix: aggiunto `->disableEditingKeys()` — la colonna delle chiavi è ora bloccata (`readonlyKeys()` verificato `true`), l'admin può scrivere solo nella colonna "Traduzione".
2. **Effetto collaterale**: una volta che una chiave estranea finisce nel valore salvato, il `resolveUsing()` esistente (`array_merge($default, $value)`) la manteneva **per sempre** ad ogni caricamento successivo del form, anche con `disableAddingRows()`/`disableDeletingRows()` attivi (che bloccano solo l'aggiunta manuale, non le chiavi già presenti nei dati) — il form mostrava quindi righe in più rispetto alle 5 lingue configurate. Fix: `resolveUsing()` ora scarta le chiavi non valide (`array_intersect_key($value, $default)`) prima del merge — pulisce anche i dati già corrotti al prossimo salvataggio, senza bisogno di una migration.

### Verifica (revisione 8)

- `php -l`, Pint puliti su entrambi i file toccati (`App.php`, `HorizontalScrollPoiTrackRepeaterJsonPreset.php`, `HasFlexibleTranslatableFields.php`)
- Bug 1+2: verificati con test HTTP reale end-to-end (status 500→200), vedi sopra
- Bug 3, parte 1 (`disableEditingKeys`): verificato che `readonlyKeys()` ritorna `true` — comportamento client-side (blocco input browser), non verificabile in browser reale in questo ambiente
- Bug 3, parte 2 (`resolveUsing`): verificato via reflection con input corrotto (5 righe vuote pulite, nessuna riga fantasma) e input misto valido+corrotto (traduzioni valide preservate, chiave estranea scartata)
- **Test automatici del package non eseguiti** anche in questo round (stesso limite ambientale di revisione 7)
- Verifica manuale in browser: non eseguita direttamente da me (solo dall'utente, che ha riportato gli screenshot usati per la diagnosi)

### Out of scope (revisione 8)

- Fix dei bug 1 e 2 sul box a tassonomie originale (`HorizontalScrollItemRepeatable`/`HorizontalScrollRepeaterJsonPreset`) — stesso identico problema, mai toccato da oc:8241, segnalato all'utente per un ticket separato
- Bug trovato nel frontend `webmapp-app`/`wm-core` (repo separato): `FeaturesBoxComponent` (box `base`) fa `{{title}}` senza la pipe `wmtrans`, a differenza del box gemello `title` in `home-landing.component.html` che fa correttamente `{{box.title|wmtrans}}` — anche con il titolo del box salvato correttamente in tutte le lingue, l'app mostrerebbe oggi l'oggetto non tradotto. Segnalato all'utente, non risolto (repo/deploy diverso)

## Revisione 9 — Rimosso il Title del box, allineato al pattern GeoHub (2026-07-23)

L'utente ha condiviso un `config_home` reale e funzionante prodotto da GeoHub: `box_type: "base"` **non ha mai la chiave `title`** — le intestazioni di sezione sono box `title` separati che precedono il gruppo, non un titolo proprio del box `base`. Questo contraddiceva `config_home_title_layout()` (condiviso da tutti i box `horizontal_scroll_*`), che rende il Title del box **obbligatorio** (`required: true`) — l'admin non poteva salvare un box Poi/Track senza compilarlo, mentre nell'uso reale quel campo è tipicamente assente. Spiega parte delle difficoltà nei test precedenti (revisione 8).

### Cosa cambia (revisione 9)

- `horizontal_scroll_poi_track_layout()` in `App.php`: rimosso `$this->config_home_title_layout()` dal merge — il box Poi/Track ora ha **un solo campo** in Nova (il Repeater "Elementi"), nessun Title
- `ConfigHomeResolver::finalizePoiTrackElement()`: la chiave `title` viene ora **omessa interamente** dal JSON scritto quando vuota (`if ($title !== []) { $result['title'] = $title; }`), invece di scrivere sempre `"title": {}` — verificato via reflection: risultato senza `title` per box senza titolo, `title` presente e intatto per compatibilità con record legacy che ne avessero già uno salvato
- Non toccato: `config_home_title_layout()` resta invariato per gli altri box (`title` box_type stesso, Activities, POI Types) — sono fuori scope, il problema "required" condiviso potrebbe applicarsi anche a loro ma non è stato verificato/richiesto

### Verifica (revisione 9)

- Verificato via reflection: `horizontal_scroll_poi_track_layout()` restituisce 1 solo field (`Repeater|items`, nessun `KeyValue|title`)
- Verificato via reflection: `finalizePoiTrackElement()` con title vuoto → chiave `title` assente dal risultato; con title non vuoto (dato legacy) → chiave `title` preservata
- `php -l`, Pint puliti su entrambi i file
- **Confermato dall'utente con un salvataggio reale** (app "Vivi Lama", id 2): il `config_home` risultante ha il box `base` senza alcuna chiave `title`, identico al pattern GeoHub — verificata anche la compatibilità completa col frontend (`wm-core`): box senza title → header non renderizzato (nessun errore), 3 item con tipi misti poi/track renderizzati correttamente da `wm-box` con `{{data.title|wmtrans}}`, `image_url` vuoto → fallback a placeholder di default (stringa vuota è falsy), click instradato correttamente su `poi_id`/`track_id`

### Out of scope (revisione 9)

- Migrazione dei record già salvati con un `title` sul box Poi/Track (es. i dati di test "Vivi Lama" con `{"it":"titolo it",...}`) — resta in DB finché quel gruppo non viene risalvato in Nova, a quel punto la chiave `title` viene automaticamente omessa
- Rimozione dello stesso Title "obbligatorio" sugli altri box `horizontal_scroll_*` (Activities, POI Types) — stesso possibile disallineamento con GeoHub, non verificato né richiesto in questo ciclo

## Revisione 10 — Test di regressione per i fix delle revisioni 8-9 (2026-07-23)

Una re-review (`wm-review-ticket`) sui commit `0d2ecb10`/`90f24924` ha segnalato, in modo indipendente da 4 finder su 5, l'assenza di test permanenti per i tre bug fix della revisione 8 e la rimozione del Title della revisione 9 — verificati solo con reflection ad-hoc durante il debug, poi cancellata.

### Cosa cambia (revisione 10)

- `ConfigHomeResolverPoiTrackTest.php`: +2 test per `finalizePoiTrackElement()` (omissione/preservazione `title`)
- `HorizontalScrollPoiTrackRepeaterJsonPresetTest.php` (nuovo): `extractRawItems()` con array (path Detail) e oggetto (path form edit)
- `HasFlexibleTranslatableFieldsTest.php` (nuovo): `readonlyKeys()` + filtro chiavi non valide in `resolveUsing()`
- `AppConfigHomePoiTrackLayoutTest.php` (nuovo): struttura layout senza Title + `showOnDetail()` sul Repeater

### Verifica (revisione 10)

- `php -l`, Pint puliti
- **Non eseguiti contro il DB reale** — stesso limite ambientale delle revisioni precedenti (nessun DB isolato `wm_package`); la logica replica le chiamate reflection già verificate manualmente nelle revisioni 8-9, ma la suite non è mai stata eseguita end-to-end in questo ciclo
