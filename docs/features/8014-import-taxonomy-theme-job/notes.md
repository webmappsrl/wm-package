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
