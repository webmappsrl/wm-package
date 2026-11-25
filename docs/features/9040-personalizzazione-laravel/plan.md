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
