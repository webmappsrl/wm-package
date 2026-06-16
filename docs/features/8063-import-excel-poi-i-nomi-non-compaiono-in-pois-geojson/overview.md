> Ticket: oc:8063

# Import Excel POI: i nomi non compaiono in pois.geojson

## Cosa cambia

`EcPoiRowProcessor::apply()` sincronizza `properties['name']` da `getTranslations('name')` al termine del loop, replicando la logica di `AbstractObserver::saving()` che viene bypassata da `saveQuietly()`. Dopo il fix, i POI importati da Excel compaiono con il nome corretto nel `pois.geojson`.

## Perché

L'import Excel usa `saveQuietly()` per non triggerare la catena di observer per ogni riga (DEM, taxonomy_where, job rigenerazione GeoJSON). Questo bypassa anche `AbstractObserver::saving()`, che normalmente copia `getTranslations('name')` → `properties['name']`. Il GeoJSON legge da `properties`, non dalla colonna `name`: i POI risultavano salvati correttamente in DB/Nova ma senza nome nel file GeoJSON su S3.

## Requisiti

- [ ] `EcPoiRowProcessor::apply()` sincronizza `properties['name']` da `getTranslations('name')` dopo il loop, solo se il modello ha `getTranslations` e le traduzioni non sono vuote (`if ($name !== [])`)
- [ ] Test esistente `apply_builds_point_geometry_from_lat_lng_and_writes_properties` aggiornato: anonymous model con `getTranslations`, asserzione `$properties['name'] === ['it' => 'Rifugio', 'en' => 'Refuge']`
- [ ] Nuovo test: solo `name_it` fornito → `properties['name'] === ['it' => '...']` (nessuna chiave `en`)
- [ ] Nuovo test: modello senza `getTranslations` → `properties['name']` non viene scritto (guard `method_exists` rispettato)

## Rischi

- **PHPStan**: chiamata `$model->getTranslations('name')` dopo `method_exists` su tipo `Model` — stesso pattern già in uso in `apply()` per `setTranslation`, non presente in baseline. Livello PHPStan nel package è 4. Rischio basso; verificare in Docker.
- **Import parziale**: in create mode con solo `name_it`, `properties['name']` sarà `['it' => '...']` senza `en`. Il GeoJSON (`GeoJsonService::getModelAsGeojson`) legge `properties` as-is, nessuna trasformazione — comportamento corretto.

## Out of scope

- Trigger automatico di rigenerazione GeoJSON dopo import (l'uso di `saveQuietly()` è intenzionale per performance)
- Sync di `description` ed `excerpt` (già scritti in `properties` direttamente nel loop, non hanno questo problema)
- Migration/command per POI esistenti importati senza nome (workaround documentato nel ticket: re-import con `id` + "Rigenera pois.geojson" su Nova)
- Altre lingue oltre `it` ed `en` (il fix è generico: usa `getTranslations('name')` che restituisce tutte le lingue disponibili)

## Moduli toccati

| File | Repo | Modifica |
|---|---|---|
| `src/Imports/Processors/EcPoiRowProcessor.php` | wm-package | Aggiunta sync `properties['name']` dopo il loop in `apply()` |
| `tests/Unit/Imports/Processors/EcPoiRowProcessorTest.php` | wm-package | Aggiornamento test esistente + 2 nuovi casi |
