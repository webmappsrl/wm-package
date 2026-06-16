> Ticket: oc:8014

# Notes — ImportTaxonomyThemeJob

## Fix da review (Rubens Garofalo, 16-06-2026)

- **`sync()` → `syncWithoutDetaching()` in `ImportTaxonomyJob`:** bloccante. `sync()` sovrascriveva tutte le associazioni del record con l'ultimo tema importato; ogni job azzerava i temi precedenti. Confermato su app 63: EcTrack 82756 e EcPoi 38201 con 3 temi ciascuno — ne rimaneva 1 dopo l'import. Fix: `syncWithoutDetaching()` in `processDependencies()`.
- **Rimosso dead code `$syncData`:** il loop che costruiva `$syncData` era unreachable (il dato veniva ricostruito inline nel loop successivo). Rimosso per chiarezza.
- **Nota `icon` in CLAUDE.md aggiornata:** la decisione di attivare il campo `icon` era stata presa e registrata in notes.md, ma CLAUDE.md riportava ancora la nota "commentato/TBD". Allineato.

## Deviazioni dal piano

- **`ImportAppJob.php` non era nel piano originale:** il piano e l'overview iniziali non includevano la modifica a `ImportAppJob`. Il ticket ha segnalato esplicitamente la lacuna nella description — il blocco `if (in_array('taxonomy_theme', ...))` in `processDependencies()` e l'aggiornamento del fallback `$allDependencies` in `getAllowedDependencies()` sono stati aggiunti come fix critico. Overview e plan aggiornati di conseguenza.
- **Rebase su `develop` necessario:** il branch `oc_8014` era stato creato prima del merge di oc:8013 (`fix: use entityId instead of model->id`). Il rebase è stato eseguito per includere il fix nella storia del branch — senza di esso `ImportTaxonomyThemeJob` avrebbe ereditato il bug di `$model->id` da `ImportTaxonomyJob`.

## Bug trovati

Nessuno durante la fase di analisi.

## Decisioni

- **`Log::error()` nel catch:** aggiunto rispetto al pattern originale di `ImportTaxonomyActivityJob` (che inghiotte silenziosamente). Motivazione: la Challenge adversariale ha evidenziato che senza logging un fallimento completo del job appare come "completed" in Horizon, rendendo il debug impossibile.
- **Campo `icon` abilitato nel config:** GeoHub ha la colonna `icon` in `taxonomy_themes` con formato SVG, identico a `taxonomy_activity`. Il transformer `svgIconToNameIcon` è corretto.
- **`identifier` mappato come `'identifier' => 'identifier'`** (stile taxonomy_activity) e non come `'identifier' => 'properties->geohub_id'` (stile taxonomy_when): il config top-level ha già `'identifier' => 'properties->geohub_id'` per il lookup, il campo `fields.identifier` mappa la colonna GeoHub `identifier` al campo locale. Verificato al test E2E post-fix.

## Test E2E post-fix sync (16-06-2026, app GeoHub 63, DB ripristinato)

Ambiente: maphub locale branch `oc_8014`, GeoHub dev via tunnel SSH `dev.geohub`.

### Comandi eseguiti

```bash
docker exec php-maphub php artisan wm:import-from-geohub app 63
# dopo primo passaggio (pivot a 0 per race condition):
docker exec php-maphub php artisan wm:import-from-geohub app 63 --dependencies=taxonomy_theme
```

### Esito verifiche

| Check | Esito | Note |
| ----- | ----- | ---- |
| Connessione GeoHub app 63 | PASS | Tunnel SSH richiesto |
| ImportAppJob / app locale | PASS | `id=3`, `geohub_id=63` |
| ImportTaxonomyThemeJob | PASS | 502/502 themes, 0 errori job |
| Fix `syncWithoutDetaching` multi-theme | PASS | Vedi casi sotto |
| Pivot totali allineati a GeoHub | PASS | Dopo re-import taxonomy |
| API filtro tema (webapp) | PASS | 2 features su theme 365 |
| Nova TaxonomyTheme | PASS | Risorsa registrata |

### Fix sync — casi noti (dopo re-import taxonomy)

| Entità | GeoHub id | Locale id | Theme GH | Theme locale | GH theme ids |
| ------ | --------- | --------- | -------- | ------------ | ------------ |
| EcTrack *Sentiero Marcò* | 82756 | 41 | 3 | **3** | 366, 367, 369 |
| EcPoi *Laghetto Welsperg* | 38201 | 99 | 3 | **3** | 364, 389, 390 |

### Pivot totali (app 63, post re-import)

| Tipo | Contenuti GH/local | Pivot GH/local | Multi-entity GH/local |
| ---- | ------------------ | -------------- | --------------------- |
| EcTrack | 22/22 | **25/25** | 5/5 |
| EcPoi | 119/119 | **182/182** | 62/62 |
| Layer | 9/9 | **9/9** | — |
| **Totale pivot** | | **216** | |

Confronto test pre-fix: stessi casi avevano 1/3 theme (track 82756) e 1/3 (POI 38201); pivot POI 119/182.

### Race condition

Al **primo** import completo: 502 themes importati ma **0 pivot** (`taxonomy_themeables` vuota) perché i job `ImportTaxonomyThemeJob` girano in parallelo con `ImportEcTrackJob`/`ImportEcPoiJob`. Il re-import `--dependencies=taxonomy_theme` risolve tutto (pivot 216/216 allineati a GeoHub).

### Verdetto

**OK per merge** — il fix `syncWithoutDetaching()` è confermato: associazioni multiple replicate correttamente. Resta come limite noto la race condition al primo passaggio (workaround: re-import taxonomy o ticket follow-up per serializzare taxonomy dopo EC).

- ~~Decidere con il capo se includere `icon`~~ — confermato: GeoHub ha la colonna `icon` in `taxonomy_themes` con formato SVG. Il transformer `svgIconToNameIcon` è attivo nel config.
- Aprire ticket separati per `ImportTaxonomyWhenJob` e `ImportTaxonomyTargetJob` — stessa lacuna, esclusi dallo scope di questo ticket.
- Dopo il merge, comunicare ai dev dei progetti che usano wm-package di aggiornare il config pubblicato (`default_dependencies` per `app`).
- **Race condition taxonomy vs EC:** al primo import i pivot restano vuoti finché non si re-importa `taxonomy_theme`. Valutare ticket per accodare taxonomy dopo `ec_track`/`ec_poi` o job di retry associazioni a fine batch.

> Ticket: oc:8014

# Notes — ImportTaxonomyThemeJob

## Deviazioni dal piano

Nessuna — implementazione non ancora iniziata.

## Bug trovati

Nessuno durante la fase di analisi.

## Decisioni

- **`Log::error()` nel catch:** aggiunto rispetto al pattern originale di `ImportTaxonomyActivityJob` (che inghiotte silenziosamente). Motivazione: la Challenge adversariale ha evidenziato che senza logging un fallimento completo del job appare come "completed" in Horizon, rendendo il debug impossibile.
- **Campo `icon` abilitato nel config:** GeoHub ha la colonna `icon` in `taxonomy_themes` con formato SVG, identico a `taxonomy_activity`. Il transformer `svgIconToNameIcon` è corretto.
- **`identifier` mappato come `'identifier' => 'identifier'`** (stile taxonomy_activity) e non come `'identifier' => 'properties->geohub_id'` (stile taxonomy_when): il config top-level ha già `'identifier' => 'properties->geohub_id'` per il lookup, il campo `fields.identifier` mappa la colonna GeoHub `identifier` al campo locale. Da verificare al primo test che il dato arrivi correttamente.

## Follow-up

- Decidere con il capo se includere `icon` nel mapping fields (verificare esistenza colonna in GeoHub).
- Aprire ticket separati per `ImportTaxonomyWhenJob` e `ImportTaxonomyTargetJob` — stessa lacuna, esclusi dallo scope di questo ticket.
- Dopo il merge, comunicare ai dev dei progetti che usano wm-package di aggiornare il config pubblicato (`default_dependencies` per `app`).
