
> Ticket: oc:8223

# Horizontal Scroll — dati item ereditati dal modello (tassonomia)

## Cosa cambia

**Contratto unico per tutti i dati dell'item (nome e immagine):** la selezione resta a due passi come oggi — prima il modello/box (layout `horizontal_scroll_activities` o `horizontal_scroll_poi_types`, cioè Taxonomy Activity o Taxonomy POI Type), poi il record specifico di quel modello (Select `res`, es. l'attività "Escursionismo"). Una volta scelto il record, **sia il nome che l'immagine arrivano di default dal record stesso**, ma restano modificabili per singola app (override per-item), esattamente come già oggi succede per il nome — l'immagine finora non seguiva questa regola perché la tassonomia non aveva un campo immagine.

- Nuovo campo **Immagine** (media library, collection dedicata) sul modello astratto `Taxonomy` — quindi disponibile su `TaxonomyActivity`, `TaxonomyPoiType`, e per coerenza anche su `TaxonomyTheme`/`TaxonomyWhere`, che condividono la stessa astratta. Nessuna migration necessaria: `Taxonomy` eredita già `HasMedia`/`InteractsWithMedia` da `GeometryModel`.
- Il campo viene esposto in Nova su `AbstractTaxonomyResource`, accanto al campo `icon` (SVG) già esistente.
- Nel repeater `HorizontalScrollItemRepeatable` (usato dai layout `horizontal_scroll_activities` e `horizontal_scroll_poi_types` di `config_home`), sia `title` che `image_url` per-item sono **override opzionali**: se vuoti, il valore finale viene ereditato dal record di tassonomia risolto tramite `res` (identifier) — nome dalla tassonomia, immagine dalla nuova media collection.
- `ConfigHomeResolver` viene esteso per risolvere l'immagine finale dell'item con la stessa logica già usata per il `title` (default dal modello, override per-item se presente) — `mergeItemTitle()` già implementa questo pattern per il nome, va replicato per l'immagine.
- Nessuna migrazione automatica dei dati esistenti: i valori `title`/`image_url` già salvati nei `config_home` di produzione restano invariati e continuano a fare da override finché non vengono svuotati manualmente in Nova.
- **Vincolo esplicito: il JSON di output di `config_home` non cambia struttura.** Ogni item dell'array `items` continua ad avere esattamente le chiavi `title`, `res`, `image_url` con lo stesso formato di oggi (stesso contratto per il frontend che consuma il config). Cambia solo *da dove* arrivano internamente i valori di `title`/`image_url` (override per-item vs fallback tassonomia) — mai la forma del JSON.

## Perché

Il ticket segnala che l'architettura attuale è concettualmente errata: title e imageUrl dell'item horizontal-scroll sono oggi campi di testo libero scollegati da qualunque modello reale — le tassonomie non hanno nemmeno un campo immagine. Questo obbliga l'admin a re-inserire manualmente gli stessi dati per ogni app, con rischio di inconsistenza tra app diverse per la stessa tassonomia (es. "Escursionismo" con una foto diversa su ogni app).

## Requisiti

- [ ] Fix `MediaObserver` (`validateModelHasAppId`/`setAppIdAndGeometry`): gestire esplicitamente i modelli senza colonna `app_id` che non sono `App`, senza chiamare `setDefaultValues()` con un oggetto non `Media` — oggi genera un `TypeError` non catturato
- [ ] Aggiungere una media collection dedicata (es. `image`) al modello astratto `Taxonomy` via `registerMediaCollections()`
- [ ] Esporre il campo immagine come campo Nova su `AbstractTaxonomyResource`, coerente con il campo `icon` esistente, con help text che segnala che l'immagine è condivisa da tutte le App che usano quella tassonomia
- [ ] In `HorizontalScrollItemRepeatable`, aggiornare l'help text di `title` e `image_url` per chiarire che sono override opzionali ("lascia vuoto per usare il valore della tassonomia")
- [ ] In `ConfigHomeResolver`, risolvere sia `title` che `image_url` finali dell'item con la stessa regola: valore per-item se valorizzato, altrimenti valore della tassonomia risolta tramite `res` (il fallback per `title` esiste già in `mergeItemTitle()`, va solo replicato per l'immagine)
- [ ] Nuovo Observer su `TaxonomyActivity`/`TaxonomyPoiType` (o hook nel `TaxonomyObserver` esistente) che, al salvataggio dell'immagine, rigenera il `config_home` di tutte le App che referenziano quell'identifier in un box `horizontal_scroll` — evita lo staleness del JSON congelato
- [ ] Il JSON di output di `config_home` (chiavi `title`, `res`, `image_url` per item) resta identico nella forma — nessun breaking change per il frontend consumer
- [ ] Nessun backfill automatico dei dati storici — i valori `title`/`image_url` già presenti nei `config_home` in produzione non vengono toccati
- [ ] Test per la nuova logica di fallback immagine in `ConfigHomeResolver` e per il fix del `MediaObserver`
- [ ] Nuove etichette Nova tradotte in `wm-package/resources/lang/it.json` e `en.json`

## Rischi

- **[Risolto in questo ciclo] Bug bloccante `MediaObserver`:** upload di un'immagine su un modello senza `app_id` (come `Taxonomy`) genera oggi un `TypeError` non catturato (`setDefaultValues()` chiamato con un oggetto non `Media` in `validateModelHasAppId()`). Verificato: `ec_pois` ha `app_id`, `taxonomy_activities`/`taxonomy_poi_types` no. Fix incluso come prerequisito in questo ticket.
- **[Mitigato] Staleness del `config_home`:** `AppConfigService::config_section_home()` legge il JSON già serializzato (`getRawOriginal('config_home')`), non richiama mai `ConfigHomeResolver`. Senza propagazione, un aggiornamento futuro dell'immagine di una tassonomia non si rifletterebbe nelle App già salvate finché qualcuno non le risalva manualmente in Nova. Mitigato con un observer dedicato che rigenera il config delle App coinvolte al salvataggio della tassonomia.
- **[Accettato, corretto in fase di challenge] Rischio cross-app su dato condiviso:** ogni progetto Webmapp ha il proprio database separato — tassonomie e App non sono condivise tra progetti diversi, si condivide solo il modello/schema via il package. Nel caso raro di più App Nova nello stesso database che condividono la stessa riga di tassonomia, l'override per-item (title/image_url) resta disponibile per personalizzare il valore per singola App. Mitigato con un help text esplicito sul campo Nova.
- **Feature creep accettato:** il campo immagine viene aggiunto anche a `TaxonomyTheme`/`TaxonomyWhere` (che non usano l'horizontal scroll) per coerenza con `icon`, già condiviso da tutte e 4 le risorse taxonomy. Costo di manutenzione minimo, accettato per semplicità.
- **Blind spot noto, non modificato in questo ciclo:** se un item referenzia un `res` di una tassonomia cancellata, `ConfigHomeResolver::fromRepeaterItems()` scarta silenziosamente l'item al salvataggio successivo (comportamento preesistente, invariato da questa feature).

## Out of scope

- Aggiunta di `EcPoi`/`EcTrack` come "model" selezionabile per l'horizontal scroll — rimandato esplicitamente a un ciclo futuro (evidenziare POI/tracce singole invece che categorie)
- Unificazione dei due layout Nova esistenti (`horizontal_scroll_activities`, `horizontal_scroll_poi_types`) in un unico layout generico parametrico
- Backfill dei dati storici (copiare i valori `title`/`image_url` per-item già in produzione nel nome/immagine della tassonomia) — task manuale separato, non incluso in questo ciclo

## Moduli toccati

- `wm-package/src/Observers/MediaObserver.php` — fix `TypeError` su modelli senza `app_id`
- `wm-package/src/Models/Abstracts/Taxonomy.php` — nuova media collection immagine
- `wm-package/src/Observers/TaxonomyObserver.php` (o nuovo observer dedicato) — propagazione: rigenera `config_home` delle App collegate al salvataggio dell'immagine
- `wm-package/src/Nova/AbstractTaxonomyResource.php` — nuovo campo Nova Immagine con help text
- `wm-package/src/Nova/Flexible/ConfigHome/HorizontalScrollItemRepeatable.php` — help text `title`/`image_url` aggiornato
- `wm-package/src/Nova/Flexible/Resolvers/ConfigHomeResolver.php` — logica di risoluzione immagine con fallback alla tassonomia
- `wm-package/resources/lang/it.json`, `wm-package/resources/lang/en.json` — nuove traduzioni
- Nuovi/aggiornati file di test in `wm-package/tests/` per `ConfigHomeResolver` e `MediaObserver`
