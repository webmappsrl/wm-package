> Ticket: oc:7645

# EC POI: scelta icona o immagine sulla mappa

## Cosa cambia

Il problema si esprime **esclusivamente** nei related POI (cliccando su una traccia): il frontend oggi decide autonomamente cosa mostrare in base alla sola presenza dell'immagine. Il layer `pois.geojson` funziona già correttamente e non va toccato.

Il backend espone `feature_image.show_image_on_map: true/false` nel JSON dei related_pois dell'EcTrack. Il frontend legge quel flag senza più logica decisionale propria.

## Perché

Cammini d'Italia vuole una mappa visivamente coerente con icone uniformi per tipo di POI. La gestione dell'immagine (gallery, scheda dettaglio) deve essere separata dalla scelta di cosa mostrare sulla mappa. Non esiste un caso d'uso reale per impostare il default a livello di categoria — la gestione POI per POI è sufficiente.

Il default è `false` (mostra icona): comportamento conservativo e coerente con il layer `pois.geojson`. L'admin attiva `true` solo sui POI la cui foto aggiunge valore visivo nel contesto della traccia.

## Requisiti

- [x] `show_image_on_map` (nullable boolean, default `null` → interpreta come `false`) salvato in `EcPoi.properties->show_image_on_map`
- [x] Nessuna migration — `EcPoi` ha già `properties` (jsonb)
- [x] Nova field: Boolean toggle (checkbox) nullable su EcPoi
  - `->readonly()` se il POI non ha immagini
  - `->help()` con testo esplicativo
  - `->showOnIndex()` visibile nell'index accanto alla colonna immagine
- [x] Il risultato viene scritto come `feature_image.show_image_on_map: true/false` **solo se il POI ha immagini** (`feature_image` presente), in `RelatedEcPoiResource`
- [x] L'API standalone di EcPoi non cambia — nessun breaking change
- [x] Nessun campo su TaxonomyPoiType — logica gestita solo a livello EcPoi

## Rischi

- **Dati esistenti con `use_image_as_icon`**: il campo era su un branch non ancora in produzione — nessun backfill necessario
- **Admin attiva checkbox senza immagine**: campo `->readonly()` in Nova previene l'interazione; anche se scritto, non viene emesso nel JSON

## Out of scope

- Modifiche al frontend wm-core
- Campo `map_display_mode` su TaxonomyPoiType
- Esposizione di `show_image_on_map` nell'API standalone EcPoi

## Moduli toccati

**wm-package:**
- `src/Models/EcPoi.php` — accessor `getShowImageOnMap()` + `resolveShowImageOnMap()`
- `src/Models/Abstracts/Taxonomy.php` — rimosso `getUseImageAsIcon()`
- `src/Nova/EcPoi.php` — Boolean field con readonly + help + showOnIndex
- `src/Nova/TaxonomyPoiType.php` — rimosso field `use_image_as_icon`
- `src/Http/Resources/RelatedEcPoiResource.php` — emette `show_image_on_map` in `feature_image`
- `database/factories/EcPoiFactory.php` — fix bug `faker->latitude()`
- `tests/Feature/EcPoiMapDisplayTest.php` — 5 test per la nuova logica
- `resources/lang/en.json` / `it.json` — label aggiornate
