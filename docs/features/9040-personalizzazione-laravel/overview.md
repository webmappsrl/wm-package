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
3. **Modalità globale (tutti i cammini): da valutare.** Richiederebbe un metodo pubblico in
   `AnalyticsService` per le posizioni recenti su tutti i cammini (oggi solo per layer, con metodi
   `private`: `queryRecentUserMovedPoints`, `fetchUserMovedPointsRows`,
   `filterPointsNearLayerTracks`) e un GeoJSON dei percorsi di tutti i layer. Se è troppo
   complessa, ci si ferma e passa ad Alessandro.

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
- [ ] **Nessun aggiornamento automatico nella card** (decisione della dev, 25/11): la mappa mostra
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
- **Posizioni non aggiornate da sole:** la card non fa polling; chi lascia la dashboard aperta vede
  le posizioni del momento in cui l'ha aperta. Accettato: evita circa 43.000 richieste da ~100 KB
  al giorno per scheda aperta sulla pagina di arrivo dopo il login.

## Out of scope

- Autorizzazione per record sulla route del GeoJSON.
- Modalità globale, se la valutazione dice che è troppo.

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
