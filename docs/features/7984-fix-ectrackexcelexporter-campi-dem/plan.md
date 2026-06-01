> Ticket: oc:7984

# Plan — Fix EcTrackExcelExporter — usa classifyField per i campi DEM

## Contesto

- **Repo:** `wm-package`
- **Branch:** `oc_7984`
- **File target:** `src/Exporters/EcTrackExcelExporter.php`
- **Commit convention:** `fix(oc:7984): ...`

---

## Step 1 — Modifica `EcTrackExcelExporter::map()`

**File:** `src/Exporters/EcTrackExcelExporter.php`

Nel metodo `map($track)`, sostituire le 9 letture dirette con `classifyField`:

| Campo | Prima | Dopo |
|---|---|---|
| `distance` | `data_get($track, 'properties.distance')` | `$track->classifyField($track, 'distance')['currentValue']` |
| `ascent` | `data_get($track, 'properties.ascent')` | `$track->classifyField($track, 'ascent')['currentValue']` |
| `descent` | `data_get($track, 'properties.descent')` | `$track->classifyField($track, 'descent')['currentValue']` |
| `ele_from` | `data_get($track, 'properties.ele_from')` | `$track->classifyField($track, 'ele_from')['currentValue']` |
| `ele_to` | `data_get($track, 'properties.ele_to')` | `$track->classifyField($track, 'ele_to')['currentValue']` |
| `ele_min` | `data_get($track, 'properties.ele_min')` | `$track->classifyField($track, 'ele_min')['currentValue']` |
| `ele_max` | `data_get($track, 'properties.ele_max')` | `$track->classifyField($track, 'ele_max')['currentValue']` |
| `duration_forward` | `data_get($track, 'properties.duration_forward')` | `$track->classifyField($track, 'duration_forward')['currentValue']` |
| `duration_backward` | `data_get($track, 'properties.duration_backward')` | `$track->classifyField($track, 'duration_backward')['currentValue']` |

Nessun import aggiuntivo necessario: `EcTrack` ha già `use HasDemClassification`.

**Commit:** `fix(oc:7984): use classifyField for DEM fields in EcTrackExcelExporter`

---

## Step 2 — Documentazione

- Creare `docs/features/7984-fix-ectrackexcelexporter-campi-dem/notes.md`

**Commit:** incluso nel commit del Step 1 o separato a scelta.
