> Ticket: oc:8093

# Plan — Fix mappa layer bounding box

## Task

- [x] **Identificare la causa** — confronto dist `5e937526` vs `fb3c0555`: il dist `fb3c0555` aveva `"inline-geojson":A.field.geojson||null` nel template del `DetailFeatureCollectionMap`, assente nella sorgente.
- [x] **Installare dipendenze** — `npm install` in `src/Nova/Fields/FeatureCollectionMap/`
- [x] **Ricompilare il dist** — `npm run prod` in `src/Nova/Fields/FeatureCollectionMap/`
- [x] **Verificare il nuovo dist** — assenza di `inline-geojson:A.field.geojson` nel `DetailFeatureCollectionMap`
- [x] **Pubblicare gli asset Nova** — `php artisan nova:publish` nel container Docker
- [x] **Verifica funzionale** — ricarica pagina Layer: le tracce sono visibili

## Commit

```
fix(oc:8093): recompile FeatureCollectionMap dist to remove spurious inline-geojson prop
```
