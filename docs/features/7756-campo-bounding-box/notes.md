> Ticket: oc:7756

# Notes — Campo bounding box

## Deviazioni dal piano
- **Approccio unificato `bboxMode()`**: il piano prevedeva due campi separati (`Text::make()` per il form + `FeatureCollectionMap::make()->onlyOnDetail()` per la preview). L'implementazione ha invece aggiunto `bboxMode()` al componente `FeatureCollectionMap`, che gestisce internamente form (text input) e detail (valore + Copy + mappa). Approccio più pulito, funzionalmente equivalente.

## Bug trovati
- **SQL injection latente in `sanitizeBbox`**: i valori del bbox venivano interpolati raw nella query `ST_MakeEnvelope` senza cast a float. Corretto in Step 1 con `array_map('floatval', ...)`. Il bug preesisteva alla feature.
- **Validazione bbox insufficiente**: la closure originale controllava solo che il valore fosse un array JSON valido, senza verificare numero di elementi, range WGS84 o ordine min/max. Estesa in Step 2.
- **Import rimossi dal linter**: gli import aggiunti nel ticket precedente (oc:7749) erano stati rimossi dal linter. Riaggiunti in questo ciclo insieme ai nuovi.

## Decisioni
- **`BboxField` come componente Nova separato**: invece di aggiungere `bboxMode()` a `FeatureCollectionMap` (che deve rimanere astratto e generico), la logica bbox è stata estratta in un nuovo campo Nova autonomo `BboxField`. `App.php` usa `BboxField::make('map_bbox')`. La `DetailField.vue` di `BboxField` importa `FeatureCollectionMap.vue` via path relativo per la mappa — build-time dependency accettata, evita duplicare il codice della mappa.
- Path PHP files in `src/Nova/Fields/BboxField/` (no subdirectory `src/`) per allinearsi al mapping PSR-4 `Wm\WmPackage\ → src/`.
- `FieldServiceProvider.php` usa `__DIR__.'/dist/'` (non `/../dist/`) poiché il file è nella root del campo.

## Follow-up
- **Campo text read-only**: valutare in futuro se rendere `map_bbox` read-only in edit, dato che è calcolato automaticamente. Per ora resta editabile per permettere override manuali.
- **Link esterno `boundingbox.klokantech.com`**: dominio non gestito da Webmapp — monitorare in caso di dismissione.
