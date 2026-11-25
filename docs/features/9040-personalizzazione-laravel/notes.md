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
