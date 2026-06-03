> Ticket: oc:7953

# Notes — lista vuota in "POI Search In"

## Deviazioni dal piano
- Il linter ha rimosso automaticamente anche `$track_selected` dal campo `Track Search In` (stesso bug del secondo argomento superfluo a `options()`). La modifica è stata recepita.
- `$track_selected` e `$poi_selected` rimangono definiti in `searchable_tab()` ma non vengono più passati a `options()` — sono variabili inutilizzate, da rimuovere in un cleanup separato.

## Bug trovati
- Il metodo `Multiselect::options()` accetta un solo argomento. Il codice originale passava `$track_selected` come secondo argomento (silenziosamente ignorato da PHP). Intelephense lo segnalava correttamente con `P1119`.
- In ambiente locale il salvataggio dell'App genera un errore S3 (`Unable to check existence for: camminiditalia/json/icons.json`) nell'`AppObserver::saved()` — problema preesistente e non legato a questo fix.

## Decisioni
- Le opzioni del multiselect `POI Search In` rispecchiano esattamente i campi di `EcPoi::getSearchableString()`: `name`, `description`, `excerpt`, `osmid`, `taxonomyPoiTypes`.
- La pre-selezione dei valori salvati è gestita automaticamente da Nova via attributo del modello — non serve il secondo argomento di `options()`.

## Follow-up
- Rimuovere le variabili inutilizzate `$track_selected` e `$poi_selected` da `searchable_tab()` in un cleanup dedicato.
- Valutare un contratto formale (es. costante o metodo) tra le opzioni Nova e i campi di `getSearchableString()` per evitare desincronizzazione futura.
