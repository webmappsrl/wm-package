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
