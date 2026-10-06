# Campi Flexible, Translatable ed embed nel WYSIWYG

Il builder `config_home`/`config_detail` di Nova, il field traducibile che ci vive dentro, e come
un `<iframe>` sopravvive al passaggio da Trix.

## Stato attuale

### `FlexibleTranslatable`

Sottoclasse di `Kongulov\NovaTabTranslatable\NovaTabTranslatable`, con due costruttori nominati:

- `simple()` — un attributo JSON annidato per lingua (`title: {it:.., en:..}`)
- `richText()` — N attributi flat per lingua (`content_it`, `content_en`), con sanificazione
  HTMLPurifier condivisa

Sostituisce `HasFlexibleTranslatableFields::translatableFields()` nei quattro punti di consumo
(`App::config_home_title_layout()`, `App::overlays_title_layout()`,
`HorizontalScrollItemRepeatable`, `InfoBoxItemRepeatable`). Del trait originale resta solo
`decodeTranslatableValue()`, usato in lettura da tre resolver e già difensivo verso entrambi i
formati di storage (oc:8349).

**Il vendor non funziona dentro un `Repeater`.** `NovaTabTranslatable::fillInto()`/`resolve()`
assumono un vero Eloquent Model e ignorano il path "scoped" che il preset JSON del `Repeater`
calcola per i campi di riga. `FlexibleTranslatable` li sovrascrive in modo mode-aware; nessuna
patch al vendor (oc:8349).

**Il componente Vue sottomette ogni lingua come chiave flat indipendente**
(`translations_title_it`), non una mappa aggregata come il vecchio `KeyValue`. Chi tocca il
formato della request deve guardare il componente **reale**, non la parità del formato di
storage (oc:8349).

**Dentro un layout Flexible i sotto-campi per lingua sono condivisi fra i gruppi dello stesso
layout e il template del layout.** `Layout::cloneField()` di whitecube fa un clone superficiale e
il meta `fields` di kongulov punta a `$this->data`: gruppi e template usano gli stessi oggetti per
`it`, `en`… `Layout::getResolvedValue()` serializza i campi del gruppo, ma di un livello solo, e i
sotto-campi restano oggetti fino al `json_encode` finale. Lì succedono due cose:

- con più gruppi dello stesso layout, ogni gruppo riceve i valori dell'ultimo gruppo risolto;
- la risposta di edit di Nova contiene lo stesso oggetto campo due volte, in `fields` e dentro il
  pannello in `panels` (`ResolvesFields::resolvePanelsFromFields()`), e la seconda copia viene
  serializzata dopo il `resolve(true)` di `Layout::jsonSerialize()` del template: i sotto-campi
  arrivano vuoti **anche con un solo gruppo per layout**.

Il form mostra i title vuoti o sbagliati e al salvataggio li scrive così.
`FlexibleTranslatable::jsonSerialize()` serializza subito i sotto-campi (oc:8675). Nei `Repeater`
il problema non c'è: Nova costruisce i campi di ogni riga con una nuova chiamata a `fields()`.

Un test su questo percorso serializza il campo come la risposta di Nova, cioè due volte nello
stesso `json_encode` (`fields` e `panels`), e legge la copia dei pannelli; con almeno due gruppi
dello stesso layout e valori diversi controlla anche la contaminazione fra gruppi. Un solo
`json_encode` del campo non vede il caso con un gruppo per layout (oc:8675).

### Embed nel rich text

La whitelist HTMLPurifier estende `iframe[src|...]` e `img[src|alt|width|height|title]`, con
`HTML.SafeIframe` + `URI.SafeIframeRegexp` limitato a `http(s)://` e `URI.AllowedSchemes` a
http/https/mailto. L'elemento `iframe` con attributi reali non è definito nel modulo Iframe
nativo di HTMLPurifier se non sotto `HTML.Trusted` (mai attivato): va registrato con
`addElement()`.

La logica vive nel trait `HasEmbeddableRichText`
(`src/Nova/Fields/Concerns/HasEmbeddableRichText.php`), il cui contratto è verificato a
compile-time da due metodi astratti — `defaultRichTextAllowedHtml()` e `safeIframeSrcRegexp()` —
più `embedToolingAttributes()`, unica fonte PHP della stringa `data-wm-embed-support`. Un test
verifica che quella stringa sia identica in `resources/js/nova.js` (oc:8349).

**Il percorso di un embed**, in tre pezzi:

1. Il bottone "Insert embed" (`resources/js/nova.js`, via `Trix.config.toolbar.getDefaultHTML`,
   agganciato al primo `trix-before-initialize` — non a `Nova.booting()`, dove `window.Trix` non
   è ancora garantito) inserisce un marker testuale `[[embed:url:<base64>]]` con
   `editor.insertString()`. **Non** un `Trix.Attachment`: Trix serializza il contenuto di un
   attachment HTML-escaped dentro `data-trix-attachment`, riproducendo lo stesso bug.
2. Un listener `paste` su `document` in **capture phase** intercetta l'incolla diretto. In bubble
   phase sarebbe troppo tardi: Trix registra il proprio listener sull'elemento `<trix-editor>`,
   che lo riceve prima di un listener delegato su `document`. Se `text/html` contiene un
   `<iframe>`, ogni iframe viene sostituito **in place** con un text node contenente il marker e
   il risultato va a Trix in una sola `insertHTML()` — sostituirli in un array separato perdeva
   l'ordine di testo ed embed. Se c'è solo `text/plain`, il marker si produce solo quando
   l'intera stringa trimmata è lo snippet `<iframe>...</iframe>`.
3. `expandEmbedMarkers()` espande il marker in un `<iframe>` reale lato server, sempre, e
   HTMLPurifier lo sanifica. Il bottone non conferisce fiducia: serve solo a far arrivare il
   markup al server come testo semplice.

**Round-trip in lettura.** Un `<iframe>` già salvato viene silenziosamente scartato da Trix alla
riapertura: il suo parser non conosce il tag. Il salvataggio successivo — anche per una modifica
non collegata nello stesso form — sovrascriverebbe il DB senza l'embed. Per questo
`resolveUsing()` collassa ogni iframe salvato di nuovo in un marker con
`collapseEmbedMedia()` prima di passarlo a Trix (oc:8349).

Il tooling JS è **scoped** al solo campo marcato da `wireRichTextField()`
(`extraAttributes => ['data-wm-embed-support' => 'true']`): il bottone viene comunque iniettato
ovunque da `getDefaultHTML()` per limite dell'hook, ma rimosso subito dopo da un listener
`trix-initialize` per ogni editor senza l'attributo. Senza lo scoping, il comportamento
raggiungerebbe ogni `<trix-editor>` di ogni consumer, dato che il JS è caricato con
`Nova::script()` per l'intera installazione (oc:8349).

### `config_detail` — box informativi su Layer/EcTrack/EcPoi

`properties->config_detail`, builder `Flexible` esposto dal pannello `Detail Blocks`
(`HasConfigDetailPanel`), allineato strutturalmente a `config_home`: chiave discriminante
`box_type`, campi tradotti come oggetti annidati. Oggi un solo layout, `info` (repeater di righe
`title` + `content` WYSIWYG).

`ConfigDetailResolver` scrive su path annidato in colonna JSON condivisa con il merge nativo
`fillJsonAttribute()`, così i sibling `properties->*` restano intatti.

Un `Repeater` dentro un `Flexible` non renderizza in detail Nova: `HasConfigDetailPanel` espone
il campo editabile con `onlyOnForms()` più una preview HTML read-only
(`ConfigDetailPreviewRenderer`, accordion `<details>` nativo, tab lingua CSS-only), con `content`
ri-sanificato in lettura dallo stesso factory del save path.

Il frontend wm-core non legge ancora questi dati: finiscono in `MAP.layers` e nel GeoJSON, ma
nessun componente li consuma (oc:8181).

### `HorizontalScroll` con riferimento Poi/Track

`PoiTrackReferenceField` (Model Poi/Track + search filtrata client-side) sostituisce due `Select`
sempre visibili: `dependsOn()` di Nova non raggiunge campi annidati in un Repeater dentro un
Flexible.

- Il titolo dell'item è **sempre readonly**, ereditato dal modello con cascade
  `it → en → prima lingua disponibile`. `resolveUsing()` riceve `$this->data` del `Repeatable`,
  non la risorsa padre.
- `$model->name` su un modello con Spatie `HasTranslations` risolve **sempre** una stringa per la
  locale corrente, mai l'array: per tutte le traduzioni serve `getTranslations('name')`.
- Le chiavi lingua del `KeyValue` condiviso sono bloccate con `->disableEditingKeys()`, e
  `resolveUsing()` filtra con `array_intersect_key()` prima del merge — senza, una chiave
  estranea già salvata restava per sempre (oc:8241).

**`box_type` nel `config.json` pubblico è `base`**, non `horizontal_scroll_geo`: quel valore non
esiste in `IBOX.box_type` di wm-core e `home-landing.component.html` monta `wm-features-box` solo
su `base` — il box era invisibile in app, senza errori. Il valore legacy resta riconosciuto **in
lettura** (`resolveLayoutName()`, `getAttributesForItem()`,
`previousPoiTrackItemsForGroup()`), altrimenti ogni item già salvato sparirebbe dal form
(oc:8241).

Il box Poi/Track non ha più un campo Title: un `config_home` reale prodotto da GeoHub non ha mai
`title` su un box `base` — le intestazioni sono box `title` separati. La chiave è omessa dal JSON
quando vuota, non scritta come `"title": {}` (oc:8241).

## Come ci siamo arrivati

- Le costanti `DEFAULT_RICH_TEXT_ALLOWED_HTML` e `SAFE_IFRAME_SRC_REGEXP` erano finite come
  `const` dentro il trait `HasEmbeddableRichText`: PHP le supporta nei trait solo da 8.2, e
  `composer.json` ammette 8.1. Sono tornate sulla classe `FlexibleTranslatable`, e il contratto
  del trait è passato da costanti implicite a metodi astratti (oc:8349).
- `window.btoa()` lanciava un'eccezione non gestita su caratteri fuori Latin-1 (em-dash,
  virgolette tipografiche, comuni nei titoli embed): risolto con un wrapper UTF-8-safe in
  `nova.js` (oc:8349).
- Il naming interno è passato da "Geo" a "PoiTrack" (classi, metodi del resolver, chiave di
  layout Nova, attributo virtuale): il vecchio nome leakava nella UI come "Add Horizontal Scroll
  Geo Item Repeatable", non essendoci un override di `label()`. Il `box_type` persistito non è
  stato toccato — è un dato in produzione, non un identificatore interno (oc:8241).
- Due bug preesistenti in Detail si mascheravano a vicenda: `Repeater::make()->preset(...)` senza
  `->showOnDetail()` (Nova lo nasconde per default con `onlyOnForms()`, e un preset custom non lo
  riattiva come farebbe `->asJson()`), e `extractRawItems()` che scartava un array semplice —
  `Layout::resolveForDisplay()` passa un array, solo `Layout::resolve()` passa l'oggetto Layout.
  Corretti solo per il box Poi/Track; **lo stesso bug è ancora nel box a tassonomie**
  (`horizontalScrollItemsRepeater()` / `HorizontalScrollRepeaterJsonPreset`) (oc:8241).
- Bug ancora aperto su un altro repo: `wm-core`/`FeaturesBoxComponent` stampa `{{title}}` senza
  la pipe `wmtrans`, a differenza del box `title`, quindi mostra l'oggetto non tradotto
  (oc:8241).
- Il fix di oc:8488 convertiva il title stringa legacy in `getAttributesForItem()`, prima della
  costruzione del form, e il suo test chiamava i metodi privati con un `Layout` senza campi: non
  passava dal form e non poteva vedere i sotto-campi condivisi, introdotti con `798ead30` (#269)
  il 07/09/2026 (oc:8675).
- Scartata una protezione al salvataggio che rimettesse il title salvato quando arriva vuoto: nel
  `config_home` i box non hanno un identificativo stabile (la chiave del gruppo è un `uniqid`
  rigenerato a ogni apertura dell'edit), e un abbinamento per posizione e tipo sbaglierebbe quando
  l'admin aggiunge, toglie o riordina box (oc:8675).
- Scartato un `__clone` profondo dei sotto-campi: stanno in tre posti di kongulov, uno privato
  (`translatedFieldsByLocale`), e la classe cambia fra la 2.1.7 dei consumer e la 2.2.5 (oc:8675).
