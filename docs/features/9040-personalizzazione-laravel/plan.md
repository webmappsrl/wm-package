> Ticket: oc:9040

# Piano wm-package — `LayerMapCard`

Branch: `feature/oc-9040-personalizzazione-laravel` (creato dal commit su cui sta oggi il
submodule, `d6a25be9`). PR del package verso `develop`, **da mergiare prima** di quella di
camminiditalia (regola in `CLAUDE.md`).

**Nessun commit durante l'esecuzione**: i commit sotto sono istruzioni per la dev.

## Scelta di struttura

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#scelta-di-struttura)

La card vive **dentro la cartella del Field `FeatureCollectionMap`**, non in una cartella
`src/Nova/Cards/LayerMap/` nuova:

- **PHP:** `src/Nova/Fields/FeatureCollectionMap/src/LayerMapCard.php`, namespace
  `Wm\WmPackage\Nova\Fields\FeatureCollectionMap`, già mappato in PSR-4 in `composer.json`. Una
  cartella nuova richiederebbe una voce PSR-4 nuova, che il consumer non vede finché non rifà
  `composer update` (trappola in `.claude/rules/build.md`).
- **Vue:** `resources/js/components/LayerMapCard.vue` nello stesso bundle `field.js`, che importa
  `FeatureCollectionMap.vue` direttamente. Non serve registrare `FeatureCollectionMap.vue` come
  componente globale (requisito dell'overview superato da questa scelta: stesso risultato, meno
  codice; da annotare in `notes.md`).
- **Build:** una sola, quella del Field, che ha già `node_modules`, `postcss.config.js` e
  `webpack.mix.js`. `field.js` è caricato su ogni pagina di Nova da `FieldServiceProvider`
  (`Nova::serving`), quindi la card è disponibile anche nelle dashboard.

## Task 1 — Test PHP della card (prima del codice)

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1-test-php-della-card)

File: `tests/Unit/Nova/Fields/LayerMapCardTest.php` (accanto a `FeatureCollectionMapTest.php`)

- `component` = `layer-map-card`, `width` = `full`.
- `jsonSerialize()` contiene `geojson_url` = `/nova-vendor/feature-collection-map/layers/{id}`,
  `resource_name` = `layers`, `resource_id` = id del layer, `title` (nome del layer),
  `show_live_user_positions` (= `AnalyticsService::isConfigured()`), `legend_label` (tradotto).
- Verificare che fallisca.

## Task 2 — `LayerMapCard.php`

- `class LayerMapCard extends \Laravel\Nova\Card`, `$component = 'layer-map-card'`,
  `$width = 'full'`.
- Costruttore: `Layer $layer`. `jsonSerialize()` con i campi del Task 1. Il nome del layer con
  `getStringName()` (CLAUDE.md di camminiditalia: `$layer->name` è vuoto per via del cast).
- Nessun riferimento a ruoli o a camminiditalia.
- Lanciare il test: verde.

## Task 3 — `LayerMapCard.vue`

- Template: titolo del layer, `FeatureCollectionMap` con `:geojson-url="card.geojson_url"`,
  `:resource-name="card.resource_name"`, `:resource-id="card.resource_id"`, `:height="500"`;
  sotto, se `card.show_live_user_positions`, la legenda del marker live con lo stesso stile di
  `DetailField.vue:20-26` e testo `card.legend_label`.
- **Nessun polling**: la mappa carica il GeoJSON all'apertura e basta.
- Stile della card come le altre card Nova del package (`<card class="p-6">`, come
  `LayerAnalyticsCard`, che nella dashboard le sta sopra).

## Task 4 — Registrazione e build

- `resources/js/field.js`: `app.component('layer-map-card', LayerMapCard)` accanto ai tre
  componenti esistenti.
- Dentro `src/Nova/Fields/FeatureCollectionMap/`: `npm run prod`.
- Controllare il diff del `dist` (`dist/js/field.js`, `mix-manifest.json`): devono cambiare solo i
  file della build, nessuna prop spuria.

## Task 5 — Traduzione della legenda

- `resources/lang/{it,en,fr,es,de}.json` del package (non `lang/`): chiave inglese
  `"User position (last 30 minutes)"`; it «Posizione utente (ultimi 30 minuti)», fr «Position de
  l'utilisateur (30 dernières minutes)», es «Posición del usuario (últimos 30 minutos)», de
  «Benutzerposition (letzte 30 Minuten)». Verificare prima quali file esistono e il loro ordine.

## Task 6 — Valutazione della modalità globale (stop point)

- Stimare, **senza scrivere codice**, cosa serve per la mappa di tutti i cammini: metodo pubblico
  in `AnalyticsService` per le posizioni recenti su tutti i cammini (oggi `private`:
  `queryRecentUserMovedPoints`, `fetchUserMovedPointsRows`, `filterPointsNearLayerTracks`), un
  GeoJSON dei percorsi dei 118 layer, `LayerMapCard` in modalità globale.
- Portare la stima alla dev: se è troppo, **ci si ferma** e passa ad Alessandro; scriverlo in
  `notes.md`.

## Task 7 — Verifiche

- Test del package (dal container, `docker run … vendor/bin/pest` come da `CLAUDE.md` di
  camminiditalia): `LayerMapCardTest`, `FeatureCollectionMapTest`,
  `LayerFeatureCollectionMapUserPresenceTest`.
- Pint solo sui file toccati (`composer format` riformatta tutto: scartare il resto).
- PHPStan (`composer analyse`).
- Browser: la mappa nella detail view del Layer funziona come prima (il `dist` è ricompilato).

## Task 8 — Documentazione

- `notes.md` del package: struttura scelta (card nel Field), stima della modalità globale.
- `docs/knowledge/analytics-posthog.md`: la mappa live esiste anche come card (`LayerMapCard`),
  senza aggiornamento automatico.

## Commit (dopo il review-gate)

1. `feat(oc:9040): LayerMapCard, mappa del layer con le posizioni live in una card Nova`
2. `docs(oc:9040): documentazione LayerMapCard`

---

# Seconda parte — mappa globale e posizioni aggiornate (Alessandro, 25/11)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `LayerMapCard` in modalità globale (percorsi di tutti i cammini + persone vicine a un
cammino) e posizioni live riscaricate ogni 90 s da endpoint protetti, per entrambe le modalità.

**Architecture:** le posizioni escono dal GeoJSON del layer e arrivano da endpoint propri in
`AnalyticsController` (stesso controllo di accesso già usato lì). I percorsi di tutti i cammini
arrivano da un endpoint con GeoJSON semplificato in cache. `FeatureCollectionMap.vue` riceve una
prop facoltativa con l'URL delle posizioni e le ridisegna in un `VectorLayer` a sé.

**Tech Stack:** Laravel/Nova 5, PostGIS, PostHog HogQL, Vue 3 + OpenLayers, Laravel Mix.

**Spec:** `docs/features/9040-personalizzazione-laravel/overview.md` (sezione «Seconda parte»).

## Global Constraints

- Branch già esistente `feature/oc-9040-personalizzazione-laravel`, stessa PR #353. **Nessun commit
  durante l'esecuzione.**
- Le geometrie passano da SQL puro, mai dall'ORM.
- Semplificazione dei percorsi: `ST_Force2D` + `ST_Simplify(…, 0.0005)`, `ST_AsGeoJSON(…, 5)`.
- Finestra posizioni 30 min; distanza `config('wm-package.layer_user_presence_distance_meters', 50)`;
  tetto `MAX_RECENT_POSITIONS` (500) con `Log::warning`; cache posizioni 90 s; cache percorsi 1 giorno.
- Accesso: endpoint globali solo `hasRole('Administrator')`; endpoint di layer Administrator o
  `$layer->user_id === $request->user()?->id`; altrimenti 403.
- Il GeoJSON della detail view del Layer (`/nova-vendor/feature-collection-map/layers/{id}` senza
  parametri) non cambia, tooltip dei punti live compreso (testo letterale attuale).
- `FeatureCollectionMap.vue` senza le prop nuove si comporta come oggi.
- Test nel consumer camminiditalia (vedi il suo `plan.md`, Seconda parte): i test del package non
  si caricano da lì.
- `npm run prod` solo dentro `src/Nova/Fields/FeatureCollectionMap/`.

## Review Focus

- PostHog non configurato o in errore → endpoint delle posizioni 200 con FeatureCollection vuota,
  la mappa resta con i soli percorsi (test C2 nel consumer).
- Nessun layer / nessuna traccia con geometria → percorsi e posizioni vuoti, nessun errore SQL
  su `IN ()` (test C2).
- La route globale `/live-positions` non deve essere catturata da `/{layer}` (model binding → 404):
  test C1 la chiama prima di tutto.
- Un fetch delle posizioni fallito (rete, 403 dopo logout) non deve svuotare la mappa né lanciare
  errori in console a ripetizione: si tengono i punti precedenti (verifica nel browser, Task P5).
- Lo smontaggio della card (navigazione in Nova) ferma l'intervallo (verifica nel browser, Task P5).

---

### Task P1: posizioni recenti su tutti i cammini in `AnalyticsService`

**Files:**
- Modify: `src/Services/PostHog/AnalyticsService.php` (`queryRecentUserMovedPoints` :439, accanto a
  `getRecentUserPositions` :472 e `filterPointsNearLayerTracks` :1315)

**Interfaces:**
- Produces: `public function getRecentUserPositionsOnAllLayers(int $minutesWindow = 30): array` →
  `list<array{lat: float, lng: float, user_id: ?int}>` (stessa forma di `getRecentUserPositions()`).

- [ ] **Step 1:** test nel consumer (task C2 del piano camminiditalia) scritti e falliti.
- [ ] **Step 2:** `queryRecentUserMovedPoints(?Layer $layer, …)`: nel `Log::warning` `layer_id` diventa
  `$layer?->id` (null = globale).
- [ ] **Step 3:** `private function filterPointsNearAnyLayerTrack(array $points): array`, stessa forma
  di `filterPointsNearLayerTracks()`, con `EXISTS` su `ec_tracks` in join con `layerables`
  (`layerable_type` = `(new (config('wm-package.ec_track_model')))->getMorphClass()`, come la
  relazione `Layer::ecTracks()` :122) invece della lista di id.
- [ ] **Step 4:** `getRecentUserPositionsOnAllLayers()`: `isConfigured()` falso → `[]`; cache
  `posthog:userMoved:all:recent_positions:{$minutesWindow}` 90 s; `allTracksBoundingBox()`;
  `queryRecentUserMovedPoints(null, …)`; `filterPointsNearAnyLayerTrack()`; ricostruzione di
  `user_id` come in `getRecentUserPositions()`; stessa gestione delle eccezioni (log + `[]`).
- [ ] **Step 5:** test C2 verdi.

### Task P2: feature dei punti live riusabili e GeoJSON del layer senza posizioni

**Files:**
- Create: `src/Services/Models/LivePositionsFeatures.php`
- Modify: `src/Models/Layer.php:468-526`

**Interfaces:**
- Produces: `Wm\WmPackage\Services\Models\LivePositionsFeatures::fromPositions(array $positions): array`
  → `list<array>` di Feature GeoJSON Point (stile, tooltip, nominativo e link come oggi in
  `Layer.php:470-526`, flag `analytics_show_live_user_identity` compreso).
- Produces: `Layer::getFeatureCollectionMap()` salta le posizioni se
  `request()->boolean('without_live_positions')`.

- [ ] **Step 1:** test C3 nel consumer (detail view invariata; `?without_live_positions=1` senza punti) falliti.
- [ ] **Step 2:** spostare il blocco in `LivePositionsFeatures::fromPositions()` senza cambiarne il
  comportamento (tooltip letterale `'Posizione utente (ultimi 30 minuti)'`); `Layer` lo chiama.
- [ ] **Step 3:** parametro `without_live_positions`; docblock con oc:9040.
- [ ] **Step 4:** test C3 verdi, e i test esistenti del Field verdi.

### Task P3: GeoJSON dei percorsi di tutti i cammini

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-p3-percorsi-di-tutti-i-cammini)

**Files:**
- Create: `src/Services/Models/AllLayersRoutesGeojson.php`
- Modify: `config/wm-package.php` (chiave `all_routes_map_simplify_degrees`, env
  `ALL_ROUTES_MAP_SIMPLIFY_DEGREES`, default `0.0005`)

**Interfaces:**
- Produces: `AllLayersRoutesGeojson::get(): array` → FeatureCollection; cache
  `layer-map:all-routes:{tolleranza}` 1 giorno.

- [ ] **Step 1:** test C2 (percorsi: solo tracce associate a un layer, 2D, coordinate a 5 decimali,
  tooltip col nome) falliti.
- [ ] **Step 2:** una query SQL: tracce con geometria e almeno una riga in `layerables` (stesso
  `layerable_type` del Task P1), `ST_AsGeoJSON(ST_Force2D(ST_Simplify(geometry::geometry, ?)), 5)`;
  `properties`: `tooltip` = nome come in `Layer.php:395-397`, `strokeColor`/`strokeWidth` = i default
  del layer (`Layer.php:351-360`), nessun `link` (evita un link per ognuna delle ~1.300 tracce).
- [ ] **Step 3:** test verdi; misurare dimensione della risposta in locale e annotarla in `notes.md`.

### Task P4: endpoint in `AnalyticsController`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-p4-endpoint-in-analyticscontroller)

**Files:**
- Modify: `src/Http/Controllers/Nova/AnalyticsController.php`, `src/WmPackageServiceProvider.php:177-182`

**Interfaces:**
- Consumes: P1, P2, P3.
- Produces (prefisso `/nova-vendor/layer-analytics`, middleware `nova`), tutte con risposta
  FeatureCollection:
  - `GET /live-positions` → `livePositions()` — Administrator;
  - `GET /all-routes` → `allRoutes()` — Administrator;
  - `GET /{layer}/live-positions` → `layerLivePositions(Request, Layer)` — Administrator o owner.
  Le prime due registrate **prima** di `/{layer}`.

- [ ] **Step 1:** test C1 (accesso) falliti.
- [ ] **Step 2:** metodi e route; posizioni → `LivePositionsFeatures::fromPositions()`.
- [ ] **Step 3:** test C1 verdi.

### Task P5: posizioni aggiornabili in `FeatureCollectionMap.vue`

**Files:**
- Modify: `src/Nova/Fields/FeatureCollectionMap/resources/js/components/FeatureCollectionMap.vue`
  (props :114, `onMounted` :801, `onUnmounted`)

**Interfaces:**
- Produces: prop `livePositionsUrl: { type: String, default: null }`,
  `livePositionsRefreshSeconds: { type: Number, default: 90 }`.

- [ ] **Step 1:** con `livePositionsUrl`: `VectorSource` + `VectorLayer` propri (style
  `getFeatureStyle`, `zIndex` 15), primo caricamento al mount e poi ogni
  `livePositionsRefreshSeconds`; a ogni risposta ok `clear()` + `addFeatures()`, nessun fit; su
  errore si tengono i punti precedenti e un solo `console.warn`. Intervallo fermato in `onUnmounted`.
  Senza la prop nessun layer e nessun intervallo.
- [ ] **Step 2:** verificare che tooltip e clic funzionino anche sui punti del layer nuovo (handler di
  hover/clic: se filtrano per layer, includerlo).
- [ ] **Step 3:** `npm run prod` nella cartella del Field; il diff del `dist` contiene solo questo.

### Task P6: `LayerMapCard` globale e URL delle posizioni

**Files:**
- Modify: `src/Nova/Fields/FeatureCollectionMap/src/LayerMapCard.php`,
  `resources/js/components/LayerMapCard.vue`, `resources/lang/{it,en,fr,es,de}.json`

**Interfaces:**
- Produces: `new LayerMapCard(Layer $layer)` e `LayerMapCard::global(): static`.
  `jsonSerialize()` aggiunge `live_positions_url`.
  - layer: `geojson_url` = `/nova-vendor/feature-collection-map/layers/{id}?without_live_positions=1`,
    `live_positions_url` = `/nova-vendor/layer-analytics/{id}/live-positions`;
  - globale: `title` = `__('All routes')`, `geojson_url` = `/nova-vendor/layer-analytics/all-routes`,
    `live_positions_url` = `/nova-vendor/layer-analytics/live-positions`, `resource_name`/`resource_id` = `null`.

- [ ] **Step 1:** test C4 nel consumer falliti.
- [ ] **Step 2:** costruttore `?Layer`, `global()`, campi; il Vue passa `:live-positions-url`.
- [ ] **Step 3:** chiave `All routes`: it «Tutti i cammini», en «All routes», fr «Tous les chemins»,
  es «Todos los caminos», de «Alle Wege».
- [ ] **Step 4:** `npm run prod`; test C4 verdi.

### Task P7: documentazione

- [ ] `docs/knowledge/analytics-posthog.md`: posizioni globali, endpoint protetti, card che si aggiorna
  ogni 90 s; il limite della route `feature-collection-map` resta.
- [ ] `notes.md`: divergenze e misure.

## Commit della seconda parte (dopo il review-gate)

3. `feat(oc:9040): mappa di tutti i cammini e posizioni live aggiornate nella LayerMapCard`
4. `docs(oc:9040): documentazione della mappa globale`
