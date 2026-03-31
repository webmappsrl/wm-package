# DEM Classification Tab — Design Spec

**Date:** 2026-03-31
**Scope:** wm-package only
**Branch:** wm-package `update-forestas`

---

## Obiettivo

Migliorare il tab DEM nella detail view di EcTrack mostrando, per ogni campo principale, una tabella con i valori da tutte le sorgenti (DEM, OSM, MANUAL) e il CURRENT VALUE con indicatore della sorgente vincente.

In edit mode i campi restano invariati (semplici Text che scrivono su `properties->manual_data`).

---

## Layout tabella (detail only)

Per ogni campo principale:

| DEM | OSM | MANUAL | CURRENT VALUE (indicator) |
|-----|-----|--------|--------------------------|
| 16.1 | | | 16.1 |

- La colonna **OSM** appare solo se `$model->osmid !== null`
- L'indicatore è uno tra: `DEM`, `OSM`, `MANUAL`, `EMPTY`

---

## Campi con tabella (9 campi principali)

- `distance`
- `ascent`
- `descent`
- `ele_max`
- `ele_min`
- `ele_from`
- `ele_to`
- `duration_forward`
- `duration_backward`

I campi bike/hiking (`duration_forward_bike`, `duration_backward_bike`, `duration_forward_hiking`, `duration_backward_hiking`) restano `Text::make()` semplici senza tabella.

---

## Sorgenti dati

Tutti i valori vivono dentro `properties` (colonna JSON) di EcTrack:
- `$model->properties['dem_data'][$field]`
- `$model->properties['osm_data'][$field]`
- `$model->properties['manual_data'][$field]`
- `$model->osmid` — colonna diretta sul modello

JSON malformato o null va trattato come mappa vuota (nessun crash).

---

## Regole di priorità (CURRENT VALUE)

Ordine decisionale (confronto non-strict `==`):

1. Se `manual_data[field]` non è null **e non è stringa vuota** → `indicator = MANUAL`, `currentValue = manualValue`
2. Altrimenti se `osmid !== null` e `osm_data[field]` non è null → `indicator = OSM`, `currentValue = osmValue`
3. Altrimenti se `dem_data[field]` non è null → `indicator = DEM`, `currentValue = demValue`
4. Altrimenti → `indicator = EMPTY`, `currentValue = null`

Nota: stringa vuota in `manual_data` viene ignorata e si scende alla sorgente successiva.

---

## Architettura

### Trait `HasDemClassification`

`wm-package/src/Nova/Traits/HasDemClassification.php`

Due metodi pubblici:

**`classifyField(EcTrack $model, string $field): array`**
- Logica pura, nessuna dipendenza UI
- Ritorna: `['indicator' => string, 'demValue' => mixed, 'osmValue' => mixed, 'manualValue' => mixed, 'currentValue' => mixed]`

**`generateFieldTable(EcTrack $model, string $field): string`**
- Chiama `classifyField()` e costruisce HTML della tabella
- Mostra colonna OSM solo se `$model->osmid !== null`

### `AbstractGeometryResource`

`wm-package/src/Nova/AbstractGeometryResource.php`

- Usa il trait `HasDemClassification`
- `getDemTabFields()` aggiornato: i 9 campi principali usano `Text::make()->onlyOnDetail()->resolveUsing(fn($v, $m) => $this->generateFieldTable($m, $field))->asHtml()`
- I campi bike/hiking restano `Text::make()` semplici

### Test unitari

`wm-package/tests/Unit/Nova/Traits/HasDemClassificationTest.php`

8 casi:
1. `currentValue` null (tutti e tre vuoti) → EMPTY
2. `manual_data` valorizzato → MANUAL
3. `manual_data` stringa vuota, `osmid` valorizzato, `osm_data` valorizzato → OSM
4. `osmid` null con `osm_data` valorizzato → non OSM, scende a DEM
5. Solo `dem_data` valorizzato → DEM
6. `manual_data` stringa vuota, `osmid` null, `dem_data` valorizzato → DEM
7. `properties` null/malformato → nessun crash, EMPTY
8. Confronto loose `==` (es. `"10"` == `10`) → funziona correttamente

---

## Workflow di sviluppo

- Nessun commit autonomo: il codice va revisionato dall'utente prima del commit.
- Tutto il codice va in wm-package.
- Documentazione in `wm-package/docs/superpowers/`.
