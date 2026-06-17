> Ticket: oc:8093

# Notes — Fix mappa layer bounding box

## Deviazioni dal piano

Nessuna.

## Bug trovati

Il dist `field.js` compilato in `fb3c0555` (feat(oc:7756): add BboxField) conteneva `"inline-geojson":A.field.geojson||null` nel template del `DetailFeatureCollectionMap`. Questa riga non esiste nella sorgente `DetailField.vue` — era frutto di una modifica locale temporanea finita nel dist per sbaglio durante la compilazione del ticket 7756.

## Decisioni

- Fix via ricompilazione pura: nessuna modifica al codice sorgente Vue o PHP, solo `npm run prod`.
- Il nuovo dist è ~56 byte più piccolo del precedente (906687 vs 906743), coerente con la rimozione della prop errata.

## Follow-up

- Valutare se aggiungere EcPoi alla mappa del layer (`getFeatureCollectionMap()` nel modello Layer) — ticket separato.
- Il commit `fb3c0555` in wm-package è il commit da cui tenersi lontani per future ricompilazioni senza prima verificare la sorgente.
