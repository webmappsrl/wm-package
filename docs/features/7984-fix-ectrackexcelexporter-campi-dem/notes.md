> Ticket: oc:7984

# Notes — Fix EcTrackExcelExporter — usa classifyField per i campi DEM

## Deviazioni dal piano

Nessuna deviazione rilevante.

## Bug trovati

Nessuno durante l'implementazione.

## Decisioni

- Nessun import aggiuntivo nell'Exporter: `EcTrack` ha già `use HasDemClassification`, quindi `$track->classifyField(...)` è disponibile senza modifiche al namespace dell'Exporter.
- Nessun cast a `float` aggiunto: `classifyField` restituisce il valore as-is da `dem_data`/`osm_data`/`manual_data`, coerente con il comportamento precedente di `data_get`.

## Follow-up

- Nessuno.
