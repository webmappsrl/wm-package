> Ticket: oc:7645

# EC POI Map Display Mode — Implementation Plan (refactor)

> Questo piano documenta il refactor rispetto all'implementazione originale.
> L'implementazione originale è nel commit `fc7ab808`. Il refactor è nel commit `592179e3`.

**Goal:** Semplificare la logica rimuovendo il livello TaxonomyPoiType e rinominare `use_image_as_icon` → `show_image_on_map`.

**Architecture:** Il campo `show_image_on_map` vive in `EcPoi.properties` (JSONB). Il backend lo risolve direttamente dall'EcPoi (nessun fallback sulla categoria). Il JSON dei related POI espone `feature_image.show_image_on_map` solo se il POI ha immagini.

**Tech Stack:** Laravel, Nova 5, Spatie Media Library, Pest

---

## File modificati

| File | Operazione | Cosa cambia |
|---|---|---|
| `src/Models/EcPoi.php` | Modifica | `getUseImageAsIcon` → `getShowImageOnMap`, rimozione fallback categoria |
| `src/Models/Abstracts/Taxonomy.php` | Modifica | Rimosso `getUseImageAsIcon()` |
| `src/Nova/EcPoi.php` | Modifica | Select → Boolean + readonly + help + showOnIndex |
| `src/Nova/TaxonomyPoiType.php` | Modifica | Rimosso field `use_image_as_icon` |
| `src/Http/Resources/RelatedEcPoiResource.php` | Modifica | `use_image_as_icon` → `show_image_on_map` |
| `resources/lang/en.json` / `it.json` | Modifica | Rimosse label obsolete, aggiunte nuove |
| `tests/Feature/EcPoiMapDisplayTest.php` | Modifica | Riscritti 5 test senza TaxonomyPoiType |
| `database/factories/EcPoiFactory.php` | Modifica | Fix `faker->latitude()` preesistente |

---

## Decisioni prese durante il refactor

- **No TaxonomyPoiType:** nessun caso d'uso reale per il default a livello categoria — rimosso completamente
- **Rename:** `show_image_on_map` più leggibile di `use_image_as_icon`
- **Nova field readonly:** se il POI non ha media, la checkbox è disabilitata — evita che l'admin attivi un campo senza effetto
- **Default `false`:** coerente con il layer `pois.geojson` che mostra sempre icone; l'admin attiva esplicitamente solo dove l'immagine aggiunge valore
