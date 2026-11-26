> Ticket: oc:9040

# Card Nova con la mappa del layer (`LayerMapCard`)

Parte `wm-package` di una feature mista: il consumer è camminiditalia, che mette la card nella
dashboard «Statistiche» (vedi `docs/features/9040-personalizzazione-laravel/overview.md` nel repo
camminiditalia).

## Cosa cambia

1. **Componente della mappa riusabile.** `FeatureCollectionMap.vue` (Field
   `FeatureCollectionMap`) viene registrato anche come componente Vue globale, oltre ai wrapper
   `detail-`, `form-` e `index-feature-collection-map` che `field.js:5-9` registra oggi. Il `dist`
   del Field viene ricompilato (`npm run prod`).
2. **Nuova card `LayerMapCard`.** Una card Nova che mostra la mappa di un layer, con il percorso e
   i marker delle persone in tempo reale, caricando lo stesso GeoJSON del Field nella detail view
   (`/nova-vendor/feature-collection-map/layers/{id}`). Si usa dove un Field non si può usare,
   per esempio in una Dashboard.
3. **Modalità globale della card** (seconda parte, Alessandro, 25/11). `LayerMapCard` può
   mostrare tutti i cammini insieme: i percorsi di tutte le EC track associate a un layer,
   semplificati, e le persone degli ultimi 30 minuti a meno di 50 m da una di quelle tracce.
4. **Posizioni live separate dai percorsi** (seconda parte). Le posizioni arrivano da endpoint
   propri, uno per layer e uno globale, protetti per ruolo. `FeatureCollectionMap.vue` può
   riscaricarle a intervalli in un layer della mappa a sé, senza riscaricare i percorsi e senza
   cambiare zoom e centro della mappa. Senza le prop nuove il componente si comporta come oggi.

## Perché

Le dashboard Nova accettano solo card, non Field: la mappa con le persone in tempo reale, che
oggi esiste solo come Field nella detail view del Layer (`src/Nova/Layer.php:203-208`), non si può
mettere in una dashboard così com'è. La card riusa dati, polling e cache esistenti: cambia solo il
contenitore.

## Requisiti

- [ ] `FeatureCollectionMap.vue` registrato come componente globale (nome da fissare nel piano)
      in `src/Nova/Fields/FeatureCollectionMap/resources/js/field.js`; `dist` ricompilato e
      versionato.
- [ ] `LayerMapCard` (PHP + Vue) prende un `Layer`, passa al componente `geojsonUrl` =
      `/nova-vendor/feature-collection-map/layers/{id}` e mostra la legenda dei marker live se
      `AnalyticsService::isConfigured()`, come il Field.
- [ ] ~~**Nessun aggiornamento automatico nella card**~~ — *superato dalla seconda parte (vedi
      sotto): le posizioni si aggiornano ogni 90 s da un endpoint separato.* (decisione della dev, 25/11): la mappa mostra
      le posizioni al momento dell'apertura, per aggiornarle si ricarica la pagina. Il polling
      ogni 2 s sta in `DetailField.vue:83-87`/`:203-235`, non in `FeatureCollectionMap.vue`, e
      ridisegna la mappa solo se cambiano numero o id delle feature (i marker live non hanno id);
      le posizioni sono comunque in cache 90 s (`AnalyticsService.php:484`).
- [ ] La card passa a `FeatureCollectionMap.vue` anche `resourceName` (`layers`) e `resourceId`,
      che il componente usa per i link e lo screenshot.
- [ ] La legenda dei marker live è tradotta (oggi in `DetailField.vue` è scritta in italiano nel
      Vue): il testo arriva dal PHP con `__()`, chiave in `resources/lang/*.json` del package.
- [ ] Il Field nella detail view del Layer non cambia comportamento.
- [ ] La card è generica: nessun riferimento a ruoli o a camminiditalia.
- [ ] Test PHP della card (`jsonSerialize`: componente, URL, layer) e, se il package li ha per i
      componenti, test Vitest.

### Seconda parte: mappa globale e posizioni aggiornate

- [ ] **Posizioni di tutti i cammini in `AnalyticsService`:** metodo pubblico per le posizioni
      recenti (finestra 30 min) su tutti i layer, costruito con i pezzi già presenti
      (`allTracksBoundingBox()`, `fetchUserMovedPointsRows(..., aggregatePerPerson: true)`): una
      posizione per persona, solo se a meno di `layer_user_presence_distance_meters` (50 m) da una
      EC track associata ad almeno un layer, in un'unica query PostGIS (non una per layer). Limite
      `MAX_RECENT_POSITIONS` (500) con `Log::warning` se raggiunto, cache di 90 s come per il
      layer, `[]` se PostHog non è configurato o fallisce.
- [ ] **Feature delle posizioni riusabili:** la costruzione dei punti live oggi dentro
      `Layer::getFeatureCollectionMap()` (`src/Models/Layer.php:468-526`: stile, tooltip,
      nominativo solo con `analytics_show_live_user_identity`, link) si sposta in un punto
      riusabile, usato dalla detail view del Layer e dai due endpoint nuovi. Il GeoJSON della
      detail view non cambia.
- [ ] **Endpoint delle posizioni**, nel gruppo `nova-vendor/layer-analytics` di
      `AnalyticsController`, con lo stesso controllo di accesso già usato lì
      (`AnalyticsController.php:19-22`, `:47`): posizioni globali solo per l'Administrator;
      posizioni di un layer per l'Administrator o per il proprietario del layer; 403 altrimenti,
      anche senza login. La route globale va registrata prima di `/{layer}`.
- [ ] **Endpoint dei percorsi di tutti i cammini**, solo Administrator: EC track associate ad
      almeno un layer, `ST_Force2D` + `ST_Simplify(…, 0.0005)`, 5 decimali, tooltip col nome della
      traccia, in cache lunga (un giorno) separata dalle posizioni. Le geometrie passano da SQL
      puro, come da regola del repo.
- [ ] **Il GeoJSON del layer senza posizioni**, per la card del Validator: la card chiede il
      percorso del layer senza i punti live (che prende dall'endpoint delle posizioni), così non
      compaiono due volte. Il modo (parametro della richiesta o altro) si fissa nel piano; la
      detail view continua a ricevere le posizioni come oggi.
- [ ] **`FeatureCollectionMap.vue`:** prop facoltative per l'URL delle posizioni e l'intervallo
      (default 90 s). Le posizioni vanno in un `VectorLayer` separato che si riscarica senza
      cambiare zoom e centro (la `View` di OpenLayers); l'intervallo si ferma quando il componente viene smontato. Senza le
      prop nuove il comportamento è quello di oggi.
- [ ] **`LayerMapCard`:** costruttore statico per la modalità globale (titolo tradotto, URL dei
      percorsi di tutti i cammini, URL delle posizioni globali); in modalità layer passa l'URL
      del GeoJSON senza posizioni e quello delle posizioni del layer. La card resta senza
      riferimenti a ruoli: l'accesso lo controllano gli endpoint.
- [ ] Chiavi nuove in `resources/lang/{it,en,fr,es,de}.json`; `dist` del Field ricompilato dentro
      la sua cartella.

## Rischi

- **`dist` ricompilato:** la ricompilazione del Field può portare differenze non volute nel
  bundle (versioni delle dipendenze in `node_modules`). Va verificato nel browser che la mappa
  della detail view del Layer funzioni ancora.
- **Autorizzazione:** la route del GeoJSON non controlla il singolo record (limite accettato in
  `docs/knowledge/analytics-posthog.md`). La card non lo peggiora, ma lo rende raggiungibile da
  un altro punto: chi la usa deve passare solo layer che l'utente può vedere.
- **Ordine di merge:** per la regola del repo, la PR di `wm-package` si mergia prima; solo dopo
  camminiditalia aggiorna il puntatore del submodule e usa la card. Se la PR del package non è
  mergiata in tempo, la parte camminiditalia che usa `LayerMapCard` aspetta.
- **Build:** `npm run prod` si lancia dentro la cartella del Field o della card, mai dalla root;
  la nuova card porta il suo `postcss.config.js` (`module.exports = {}`), altrimenti la build
  risale alla root del consumer e fallisce (regole in `CLAUDE.md` e `.claude/rules/build.md`).
- ~~**Posizioni non aggiornate da sole**~~ — *superato dalla seconda parte:* la card riscarica
  solo le posizioni (pochi kB) ogni 90 s, non il GeoJSON intero ogni 2 s: circa 960 richieste
  leggere al giorno per tab del browser aperto, servite dalla cache.
- **Componente condiviso:** `FeatureCollectionMap.vue` è usato da tutti i progetti. Le prop nuove
  sono facoltative e senza di esse non cambia nulla, ma il `dist` ricompilato va verificato nel
  browser sulla detail view del Layer.
- **Peso dei percorsi globali:** misurato su camminiditalia (1.287 EC track): 1,4 MB semplificati
  a 0.0005°, 445 kB compressi, query 0,28 s. Su un consumer con molte più tracce il peso cresce in
  proporzione: la tolleranza resta configurabile.
- **Filtro di prossimità globale:** `ST_DWithin` fino a 500 punti contro tutte le tracce dei layer;
  `ec_tracks` ha l'indice GiST su `geometry`, ma il tempo con PostHog vero non è misurato.

## Out of scope

- Autorizzazione per record sulla route del GeoJSON esistente
  (`/nova-vendor/feature-collection-map/{model}/{id}`, solo middleware `nova`): ticket separato.
- Polling del Field nella detail view del Layer: resta ogni 2 s, come oggi.
- Percorsi dai tile pbf dell'App al posto del GeoJSON semplificato.

## Moduli toccati

Aggiornati a fine lavoro (la struttura è cambiata nel piano, vedi `notes.md`):

- `src/Nova/Fields/FeatureCollectionMap/src/LayerMapCard.php` — nuova card (namespace
  `Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src`)
- `src/Nova/Fields/FeatureCollectionMap/resources/js/components/LayerMapCard.vue` — nuovo componente
- `src/Nova/Fields/FeatureCollectionMap/resources/js/field.js` — registra `layer-map-card`
- `src/Nova/Fields/FeatureCollectionMap/dist/` — ricompilato
- `resources/lang/{it,en,fr,es,de}.json` — chiave della legenda
- `docs/knowledge/analytics-posthog.md` — la card e il limite della route
- test: nel consumer camminiditalia (`tests/Feature/LayerMapCardTest.php`), non in `tests/`

Seconda parte:

- `src/Services/PostHog/AnalyticsService.php` — posizioni recenti di tutti i cammini, percorsi
  semplificati
- `src/Models/Layer.php` — feature delle posizioni estratte, GeoJSON senza posizioni
- `src/Http/Controllers/Nova/AnalyticsController.php`, `src/WmPackageServiceProvider.php` —
  endpoint e route nuove
- `src/Nova/Fields/FeatureCollectionMap/resources/js/components/FeatureCollectionMap.vue` — layer
  delle posizioni aggiornabile
- `src/Nova/Fields/FeatureCollectionMap/src/LayerMapCard.php`, `LayerMapCard.vue` — modalità
  globale e URL delle posizioni
- `src/Nova/Fields/FeatureCollectionMap/dist/` — ricompilato
- `resources/lang/{it,en,fr,es,de}.json`
- `docs/knowledge/analytics-posthog.md` — posizioni globali e endpoint protetti
