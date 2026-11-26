> Ticket: oc:9040

# Notes — `LayerMapCard` (parte wm-package)

## Divergenze dal piano, task per task

### Scelta di struttura

- `FeatureCollectionMap.vue` **non** è registrato come componente globale, come diceva l'overview:
  `LayerMapCard.vue` sta nello stesso bundle `field.js` e lo importa direttamente. Stesso
  risultato, meno codice.
- La classe sta in `src/Nova/Fields/FeatureCollectionMap/src/LayerMapCard.php` con namespace
  `Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src`, come `FeatureCollectionMap.php` accanto:
  la risolve la voce PSR-4 `Wm\WmPackage\` → `src`, senza voci nuove in `composer.json`.

### Task 1 test PHP della card

- Il test è in camminiditalia (`tests/Feature/LayerMapCardTest.php`), non in
  `tests/Unit/Nova/Fields/`: in `wm-package/vendor` le dipendenze del package non sono installate
  (solo `autoload.php`), e da camminiditalia `Wm\WmPackage\Tests\TestCase` non si carica.
  Installarle voleva dire un `composer install` senza lock e con le credenziali di Nova. Stessa
  scelta di oc:8140; decisa con la dev il 25/11.

### Task 3 LayerMapCard.vue

- `:enable-slope-chart="false"` aggiunto dopo la review (25/11): il default di
  `FeatureCollectionMap.vue` è `true` (`:194-197`), mentre `DetailField.vue:8` lo accende solo con
  `field.enableSlopeChart`, spento per il Layer. Senza, cliccando una tappa sulla mappa della
  dashboard compariva un profilo altimetrico che nella detail view non c'è.
- Padding `p-6` invece del `p-4` del piano, come `LayerAnalyticsCard`.

## Bug trovati

- Nessuno nel codice toccato. Per la route del GeoJSON che risponde senza login vedi `notes.md`
  di camminiditalia (`docs/features/9040-personalizzazione-laravel/notes.md`, «Bug trovati»).

## Decisioni

- **Branch dal `develop`** (25/11, indicazione della dev): il branch è stato ricreato da
  `origin/develop` (`0fd6a5a5`) invece che dal commit su cui era il submodule (`d6a25be9`, già
  contenuto in `develop`). Fra i due nessuna migration, solo factory
  (`wm-package:publish-missing-migrations --dry-run`: tutto allineato).
- **Nessun aggiornamento automatico nella card** (decisione della dev): vedi overview.
- **Build:** `npm run prod` nella cartella del Field. Verificato che ricompilando i sorgenti di
  `HEAD` si ottiene esattamente il `dist` versionato: la differenza nel bundle (+1,4 KB) è solo la
  card.
- **Pint:** `composer format` lanciato da camminiditalia riformatta anche file del package fuori
  dal lavoro (due Resource e circa 60 test): annullati con `git checkout`.

### Valutazione della mappa globale (Task 6) — non fatta, da passare ad Alessandro

Misure sul DB locale (25/11):

- I percorsi da disegnare sono le EC track dei layer: **1.252 tracce, 35 MB di GeoJSON**; anche
  semplificate (`ST_SimplifyPreserveTopology(…, 0.0005)`) restano **circa 1,7 MB**. La geometria
  dei layer (`layers.geometry`, 17 kB in tutto) non basta: non è il percorso.
- Le posizioni: `getRecentUserPositions()` lavora per layer (bbox delle tracce del layer, poi
  `filterPointsNearLayerTracks()` sulle tracce di quel layer); i metodi che servirebbero sono
  `private` (`queryRecentUserMovedPoints()`, `fetchUserMovedPointsRows()`,
  `filterPointsNearLayerTracks()`, `allTracksBoundingBox()`).

Cosa servirebbe: un metodo pubblico per le posizioni su tutta l'area (bbox di tutte le tracce,
limite `MAX_RECENT_POSITIONS` da rivedere per tutta Italia, filtro di vicinanza su 1.252 tracce),
un endpoint con le tracce semplificate e in cache, la modalità globale della card con il controllo
del ruolo. Stima: **3-4 ore** più il rischio di prestazioni (pagina di arrivo di ogni
Administrator). Secondo il piano ci si ferma: **la mappa globale passa ad Alessandro**; la
dashboard dell'Administrator resta con la sola card aggregata.

---

# Seconda parte — mappa globale e posizioni aggiornate (Alessandro, 25/11)

## Divergenze dal piano, task per task

### Task P4 endpoint in AnalyticsController

- **403 in JSON, non `abort(403)`.** Sulle route con middleware `nova` un'eccezione HTTP passa da
  `NovaExceptionHandler::renderInertiaException()`, che disegna una pagina Inertia con il menu di
  Nova. Il menu di camminiditalia (`app/Providers/NovaServiceProvider.php:114`) chiama
  `$request->user()->hasAnyRole()` senza controllare che ci sia un utente: senza login si ottiene
  un **500** invece del 403. Nei test succede solo se un test precedente ha già registrato il menu
  nello stato statico di Nova (da solo il test passa, nella classe intera no). I tre endpoint nuovi
  rispondono con `response()->json(['message' => 'Forbidden'], 403)` (metodo `forbidden()`), che
  non passa dal gestore di Nova.
- **Login richiesto esplicitamente sull'endpoint del layer.** La condizione copiata da `layer()`,
  `$layer->user_id === $request->user()?->id`, senza login e su un layer senza proprietario vale
  `null === null`, cioè vero: in un test la route nuova rispondeva **200** senza login. Ora
  `$user !== null` è controllato prima. Coperto da `LiveMapEndpointsTest::test_guest_without_login_gets_403`
  (la factory crea il layer con `user_id` null).

### Task P3 percorsi di tutti i cammini

- Le feature hanno anche `properties.id` (id della EC track), non previsto nel piano: serve a
  `FeatureCollectionMap.vue` per la mappa delle feature e non costa nulla.

## Bug trovati

- **`AnalyticsController::layer()`** (esistente, non toccato) ha la stessa condizione
  `$layer->user_id === $request->user()?->id`: senza login, su un layer senza proprietario,
  passerebbe il controllo. Oggi in pratica risponde 500 per il menu di Nova (sopra), ma è un buco
  da chiudere in un ticket separato, insieme alla route `feature-collection-map`.

## Decisioni

- **Mappa per l'Administrator fatta** (Alessandro, 25/11), nonostante allo scrum delle 12:12 si
  fosse deciso «solo Validator»: Peppe nella stessa call aveva detto che era utile anche per
  Cammini d'Italia se non costava, e i numeri misurati lo confermano.
- **Percorsi: GeoJSON semplificato a 0.0005° (≈ 50 m), non tile pbf.** Misurato sul DB locale:
  1.287 EC track, 29 MB a piena risoluzione, 1,4 MB semplificate senza quota, 445 kB con gzip,
  query 0,28 s. Unione delle tappe per cammino scartata: 3,1-3,7 MB, perché le tappe condivise
  fra più cammini si ripetono. Tolleranza configurabile (`ALL_ROUTES_MAP_SIMPLIFY_DEGREES`).
- **Posizioni: solo a meno di 50 m da una traccia di un layer**, come sulla mappa del singolo
  layer, con il tetto di 500 persone.
- **Aggiornamento ogni 90 s per entrambe le card** (Administrator e Validator): supera la decisione
  della prima parte (nessun aggiornamento). Il Field della detail view resta con il suo polling
  ogni 2 s.
- **Endpoint nuovi protetti per ruolo**; la route `feature-collection-map` esistente resta com'è
  (ticket separato).
- **Stima della seconda parte saltata** su richiesta di Alessandro: proposta indipendente
  misurato 0,45h + stimato 6,8h = 7,25h, non scritta sul ticket (resta 8h).

## Follow-up

- Ticket separato in `wm-package`: login e controllo sul record per
  `/nova-vendor/feature-collection-map/{model}/{id}`. (`AnalyticsController::layer()` è stato
  chiuso nei cleanup dopo la review, vedi sotto.)
- Ticket separato in camminiditalia: `NovaServiceProvider.php:114` (e la riga sopra) con
  `$request->user()?->…`, così una 403 senza login non diventa 500.

## Cleanup dopo la review (26/11)

Review della seconda parte con verdetto «approvato con riserve», nessun bloccante. Alessandro ha
chiesto di fare tutti i cleanup:

- **Nuovo endpoint `/nova-vendor/layer-analytics/{layer}/routes`** (`AnalyticsController::layerRoutes()`):
  è il GeoJSON del layer senza posizioni, protetto come le posizioni. Sostituisce
  `?without_live_positions=1` sulla route generica `feature-collection-map`. Così
  `Layer::getFeatureCollectionMap(bool $withLivePositions = true)` riceve un argomento esplicito e
  non legge più `request()` dentro il model.
- **Un solo controllo d'accesso** `canReadLayer()` per `layer()`, `layerLivePositions()` e
  `layerRoutes()`. Ora anche le statistiche del layer rispondono 403 in JSON. Si chiude anche il
  caso `null === null` di `layer()`: un utente senza login su un layer senza proprietario.
- **`AnalyticsService`**:
  - un solo `recentUserPositions(?Layer)` per il layer e per tutti i cammini;
  - un solo `filterPointsNearTracks($points, ?Layer)` al posto di due query quasi uguali;
  - le chiavi di cache sono invariate;
  - la costante pubblica `LIVE_POSITIONS_CACHE_SECONDS = 90` la usa anche `LayerMapCard` per
    l'intervallo del polling;
  - i messaggi di log non nominano più il metodo sbagliato.
- **`LayerMapCard`**:
  - `live_positions_url` vale null senza PostHog, quindi niente polling di un endpoint sempre vuoto;
  - nuova chiave `live_positions_refresh_seconds`.
- **Vue**: il minimo di 10 s sull'intervallo ora è la costante `MIN_LIVE_POSITIONS_REFRESH_SECONDS`
  (non era previsto dal piano). `dist` ricompilato.
- **Percorsi di tutti i cammini**: la cache passa da un giorno a **un'ora**, perché niente la
  invalida e una tappa appena associata deve comparire in tempi ragionevoli.
- **Nome e colore condivisi**:
  - `MapFeatureName::fromJson()` per il nome nei tooltip, usato da `Layer` e da `AllLayersRoutesGeojson`;
  - `Layer::DEFAULT_TRACK_STROKE_COLOR` per il colore.
- **Tooltip anonimo del marker tradotto** con `__('User position (last 30 minutes)')`. I test che lo
  confrontano impostano la lingua `it` (`LayerFeatureCollectionMapUserPresenceTest`).
- **«All routes» nel package è neutro**: «Tutti i percorsi», «Tous les itinéraires», «Todas las
  rutas», «Alle Routen». «Tutti i cammini» è un override nel lang di camminiditalia.
- **Commenti** di config e `AllLayersRoutesGeojson` senza il nome dello shard.
- **Moduli toccati della seconda parte non elencati nell'overview**, da riportare alla chiusura:
  - `src/Services/Models/AllLayersRoutesGeojson.php`
  - `src/Services/Models/LivePositionsFeatures.php`
  - `src/Services/Models/MapFeatureName.php`
  - `config/wm-package.php`
  - `tests/Feature/LayerFeatureCollectionMapUserPresenceTest.php`

  I percorsi semplificati stanno in `AllLayersRoutesGeojson`, non in `AnalyticsService`.

Lasciati come ipotetici:
- il tetto di 500 persone si applica prima del filtro a 50 m;
- un pallino può essere vecchio fino a circa 180 s;
- il polling continua con la tab in background;
- un clic su un pallino sopra una tappa prende il pallino.

