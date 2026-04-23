# EcTrack JSON — DEM Priority Output Design

**Date:** 2026-04-01
**Scope:** wm-package only
**File target:** `wm-package/src/Http/Resources/EcTrackResource.php`

---

## Obiettivo

Il JSON salvato su S3/MinIO (`tracks/{id}.json`) deve esporre solo i valori "vincenti" per i 9 campi DEM, calcolati secondo la tabella di priorità già definita. Le sorgenti raw (`manual_data`, `dem_data`, `osm_data`) non devono comparire nel JSON finale.

---

## Campi interessati (9 campi principali)

- `distance`
- `ascent`
- `descent`
- `ele_max`
- `ele_min`
- `ele_from`
- `ele_to`
- `duration_forward`
- `duration_backward`

---

## Tabella di priorità (da spec DEM Classification Tab)

Ordine decisionale (confronto non-strict `==`):

1. `manual_data[field]` non null e non stringa vuota → valore MANUAL
2. `osmid !== null` e `osm_data[field]` non null → valore OSM
3. `dem_data[field]` non null → valore DEM
4. Altrimenti → null (campo assente nel JSON)

---

## Modifica in `EcTrackResource::toArray()`

### Rimuovere il blocco attuale (righe 32-38)

```php
if (isset($geojson['properties']['dem_data']) && is_array($geojson['properties']['dem_data'])) {
    foreach ($geojson['properties']['dem_data'] as $key => $value) {
        if (! isset($properties[$key]) || $properties[$key] === null) {
            $properties[$key] = $value;
        }
    }
    unset($properties['dem_data']);
}
```

### Sostituire con

```php
// Applica priorità MANUAL > OSM > DEM per i 9 campi principali
foreach (self::DEM_FIELDS as $field) {
    $classified = $this->classifyField($this->resource, $field);
    if ($classified['currentValue'] !== null) {
        $properties[$field] = $classified['currentValue'];
    } else {
        unset($properties[$field]);
    }
}

// Rimuovi sorgenti raw: non servono lato frontend
unset($properties['manual_data'], $properties['dem_data'], $properties['osm_data']);
```

### Costante e trait

```php
use Wm\WmPackage\Nova\Traits\HasDemClassification;

class EcTrackResource extends JsonResource
{
    use HasDemClassification;

    private const DEM_FIELDS = [
        'distance', 'ascent', 'descent',
        'ele_max', 'ele_min', 'ele_from', 'ele_to',
        'duration_forward', 'duration_backward',
    ];
    // ...
}
```

---

## Gestione assenza dati

- Se una sorgente è assente o null, `classifyField()` scende alla successiva.
- Se tutte le sorgenti mancano → `currentValue = null` → `unset($properties[$field])`.
- `removeInvalidProperties()` già applicato prima filtra ulteriori null/empty array.
- Nessun crash per `properties` null o malformato (gestito dentro `classifyField()`).

---

## Impatto

| Scenario | Comportamento attuale | Comportamento nuovo |
|---|---|---|
| Solo `dem_data` | Campo flat da DEM | Invariato |
| `manual_data` valorizzato | `manual_data` nested nel JSON | Campo flat con valore MANUAL, raw rimosso |
| `osm_data` valorizzato, no manual | `osm_data` nested nel JSON | Campo flat con valore OSM, raw rimosso |
| Tutti assenti | Campo assente | Invariato |

---

## Test

`wm-package/tests/Unit/Http/Resources/EcTrackResourceDemPriorityTest.php`

Casi:
1. Solo `dem_data` → campi flat da DEM, no `dem_data` nel JSON
2. `manual_data` valorizzato → valore MANUAL vince, `manual_data` assente
3. `manual_data` stringa vuota, `osm_data` presente → valore OSM vince
4. Tutte le sorgenti assenti → campo assente nel JSON
5. `osm_data` presente ma `osmid` null → non usato, scende a DEM
6. `properties` null → nessun crash

---

## Note

- Nessun commit autonomo: il codice va revisionato dall'utente prima del commit.
- Tutto il codice va in wm-package.
