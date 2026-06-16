> Ticket: oc:8063

# Notes — Import Excel POI: i nomi non compaiono in pois.geojson

## Deviazioni dal piano

Nessuna deviazione. Il fix è stato implementato esattamente come descritto nel piano e nelle note dev del ticket.

## Bug trovati

Nessuno durante l'implementazione.

## Decisioni

- **EcTrackRowProcessor non è affetto**: verificato durante la Challenge — il processor di EcTrack non gestisce `name_it`/`name_en` e non usa `setTranslation`, quindi non ha lo stesso problema.
- **Merge implicito in update mode**: il rischio di perdita traduzioni in lingue non presenti nel file Excel (sollevato dalla review adversariale) è un falso positivo. In update mode il modello è caricato dal DB con tutte le lingue già persistite; `setTranslation` aggiorna solo la locale fornita; `getTranslations('name')` restituisce l'array completo — nessuna lingua viene persa.
- **Duplicazione logica observer/processor**: la logica di sync è ora presente sia in `AbstractObserver::saving()` sia in `EcPoiRowProcessor::apply()`. Accettata come tech debt noto; estrarre un metodo condiviso sarebbe un refactor separato fuori scope.

## Follow-up

- POI già importati prima del fix restano con `properties.name` assente nel GeoJSON. Workaround: re-import con `id` valorizzato + "Rigenera pois.geojson" su Nova (documentato nel ticket).
- Valutare in futuro se estrarre la logica di sync in un metodo condiviso tra observer e processor per evitare divergenze future.
