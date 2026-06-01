> Ticket: oc:7645

# Notes — EC POI map_display_mode

## Deviazioni dal piano

- **Refactor logica TaxonomyPoiType:** l'implementazione originale includeva un fallback sulla categoria POI (`TaxonomyPoiType`). Dopo revisione è stato eliminato — non esiste un caso d'uso reale per impostare il default a livello di categoria. Il campo è gestito solo a livello di EcPoi.
- **Rename campo:** `use_image_as_icon` → `show_image_on_map` — nome più leggibile e semanticamente corretto.
- **Nova field:** cambiato da Select (3 opzioni) a Boolean (checkbox), con `->readonly()` quando il POI non ha immagini.
- `EcTrackResource::getRelatedPois()` NON modificato per chiamare `->toArray($request)` esplicitamente — causa crash su `MediaResource(null)`. Mantenuto il pattern `->toArray()` sulla collection.

## Decisioni

- Nessuna migration — `EcPoi` usa il campo `properties` (jsonb) già esistente.
- `show_image_on_map` vive dentro `feature_image` nel JSON dei related_pois, non come campo di primo livello — contestualizzato all'immagine.
- Il campo è esposto solo se il POI ha immagini (`getMedia()->isNotEmpty()`): se `feature_image` è null il campo non appare e il frontend usa l'icona di default.
- `show_image_on_map` visibile nell'index Nova di EcPoi accanto alla colonna immagine (`->showOnIndex()`).
- Risoluzione server-side in `EcPoi::resolveShowImageOnMap()`: valore su EcPoi → fallback `false`. Nessun livello categoria.
- `RelatedEcPoiResource` separato da `EcPoiResource` — API standalone EcPoi invariata, zero breaking change.

## Follow-up

- Frontend wm-core: leggere `feature_image.show_image_on_map` invece di controllare la presenza dell'immagine — gestito in ticket separato.
- I test del package non sono eseguibili dal container `laravel-camminiditalia` (DB dedicato `wm_package` assente). Da eseguire nel repo isolato del package.
