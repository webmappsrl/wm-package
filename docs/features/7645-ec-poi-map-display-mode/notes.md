> Ticket: oc:7645

# Notes — EC POI map_display_mode

## Deviazioni dal piano

- `EcTrackResource::getRelatedPois()` NON modificato per chiamare `->toArray($request)` esplicitamente — causa crash su `MediaResource(null)`. Mantenuto il pattern originale `->toArray()` sulla collection, la serializzazione avviene lazily da Laravel.
- `RelatedEcPoiResource` semplificato: `use_image_as_icon` viene aggiunto a `feature_image` solo se `getMedia()->isNotEmpty()`. Se l'EC non ha immagini, `feature_image` rimane null e il frontend usa l'icona di default senza bisogno del flag.

## Decisioni

- Nessuna migration — entrambi i modelli usano il campo `properties` (jsonb) già esistente.
- `use_image_as_icon` booleano ovunque (DB e API) — coerente e diretto nei condizionali del frontend.
- Il campo `use_image_as_icon` vive dentro `feature_image` nel JSON dei related_pois, non come campo di primo livello — contestualizzato all'immagine.
- Risoluzione server-side in `EcPoi::resolveUseImageAsIcon()`: override EC → default categoria (prima per id ASC) → fallback `false`.
- `RelatedEcPoiResource` separato da `EcPoiResource` — API standalone EcPoi invariata, zero breaking change.

## Follow-up

- I test del package (`tests/Feature/EcPoiMapDisplayTest.php`) non sono eseguibili dal container `laravel-camminiditalia` per incompatibilità delle factory con il contesto del repo principale. Da investigare separatamente.
- Frontend wm-core: leggere `feature_image.use_image_as_icon` invece di controllare la presenza dell'immagine — gestito in ticket separato.
