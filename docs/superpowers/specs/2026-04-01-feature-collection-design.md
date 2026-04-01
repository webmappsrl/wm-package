# FeatureCollection — Design Spec
**Data:** 2026-04-01  
**Progetto:** forestas / wm-package

---

## Contesto

Il frontend si aspetta overlay GeoJSON in `MAP.controls.overlays` nel config app. Nel vecchio sistema Geohub questo era gestito dal modello `OverlayLayer`. Nel nuovo sistema introduciamo il modello `FeatureCollection`, che coincide esattamente con il formato GeoJSON FeatureCollection e sostituisce `OverlayLayer` senza reintrodurne il nome.

---

## Obiettivo

Permettere la creazione e gestione di FeatureCollection GeoJSON associate a un'App, con tre modalità di sorgente, serializzazione automatica nel config app, e rigenerazione asincrona al cambio dei dati.

---

## Sezione 1: Modello e Database

### Tabella: `feature_collections`

| Campo | Tipo | Note |
|-------|------|------|
| `id` | bigint PK | |
| `app_id` | FK → apps | |
| `name` | string | nome tecnico |
| `label` | jsonb | traducibile (it, en, ...) |
| `enabled` | boolean | default false |
| `mode` | enum | `generated` \| `upload` \| `external` |
| `external_url` | string nullable | solo se mode=external |
| `file_path` | string nullable | path S3/MinIO — solo se generated o upload |
| `generated_at` | timestamp nullable | |
| `default` | boolean | default false — un solo true per app |
| `clickable` | boolean | default true — passato nelle properties di ogni Feature generata |
| `fill_color` | string nullable | |
| `stroke_color` | string nullable | |
| `stroke_width` | float nullable | |
| `icon` | text nullable | SVG |
| `configuration` | jsonb nullable | JSON libero fuso nel config output |
| `timestamps` | | |

### Tabella pivot: `feature_collection_layer`

| Campo | Tipo |
|-------|------|
| `feature_collection_id` | FK → feature_collections |
| `layer_id` | FK → layers |

### Relazioni

- `FeatureCollection` → `BelongsTo` App
- `FeatureCollection` → `BelongsToMany` Layer (pivot `feature_collection_layer`, usato solo se mode=generated)
- `App` → `HasMany` FeatureCollection

### Path S3/MinIO

```
/{shard}/{appId}/feature-collection/{id}.geojson
```

---

## Sezione 2: Generazione e Storage

### Tre modalità

**`generated`** — per ogni Layer associato → per ogni TaxonomyWhere del layer → Feature con geometry (Polygon/MultiPolygon) + properties `{ layer_id: layer.id, clickable: true }`. File scritto in S3/MinIO al path standard, `file_path` e `generated_at` aggiornati.

**`upload`** — file caricato da Nova:
- Scritto in S3/MinIO al path standard
- `file_path` aggiornato

**`external`** — URL esterno fornito:
- Nessuna scrittura in S3/MinIO
- `external_url` usata direttamente nella serializzazione config

### Formato GeoJSON output (mode=generated)

```json
{
  "type": "FeatureCollection",
  "features": [
    {
      "type": "Feature",
      "geometry": { "type": "MultiPolygon", "coordinates": [...] },
      "properties": {
        "layer_id": 310,
        "clickable": true  // da FeatureCollection.clickable
      }
    }
  ]
}
```

### Job: `GenerateFeatureCollectionJob`

Triggerato da:
- Observer su `Layer`: quando cambiano i TaxonomyWhere associati (attach/detach) o il layer viene eliminato
- Salvataggio di una `FeatureCollection` con mode=generated
- Action Nova manuale "Rigenera"

Per ogni trigger: dispatch del job per tutte le `FeatureCollection` in mode=generated che includono quel layer.

---

## Sezione 3: Serializzazione nel config App

### `AppConfigService::config_section_map()`

Il codice commentato esistente (righe 427-470) viene riscritto per usare `FeatureCollection` al posto di `OverlayLayer`.

Per ogni `FeatureCollection` dell'app ordinata per `config_overlays`, se `enabled=true`:

```php
$array['label'] = $fc->getTranslations('label');
if ($fc->default) $array['default'] = true;
if ($fc->icon) $array['icon'] = $fc->icon;
$array['fillColor'] = $fc->fill_color ? hexToRgba($fc->fill_color) : hexToRgba($fc->app->primary_color);
$array['strokeColor'] = $fc->stroke_color ? hexToRgba($fc->stroke_color) : hexToRgba($fc->app->primary_color);
if ($fc->stroke_width) $array['strokeWidth'] = $fc->stroke_width;
$array['url'] = $fc->mode === 'external' ? $fc->external_url : Storage::disk('wmfe')->url($fc->file_path);
if ($fc->configuration) $array = array_merge($array, $fc->configuration);
$array['type'] = 'button';
```

Risultato in `MAP.controls.overlays`.

### Campo `config_overlays` su App

Array JSON ordinato di `FeatureCollection` ID. Determina l'ordine in `MAP.controls.overlays`. Gestito nella tab "Overlays" di Nova con la stessa UI di `config_home`.

---

## Sezione 4: Nova UI

### Risorsa Nova `FeatureCollection` (nuova, in wm-package)

**Pannello Base:**
- `name` (Text)
- `label` (traducibile)
- `enabled` (Boolean)
- `default` (Boolean — logica model: un solo true per app)
- `clickable` (Boolean — default true)
- `app` (BelongsTo App)

**Pannello Sorgente** (condizionale su `mode`):
- `mode` (Select: generated / upload / external)
- `layers` (BelongsToMany Layer — solo se generated)
- `external_url` (Text — solo se external)
- File upload (solo se upload)

**Pannello Stile:**
- `fill_color`, `stroke_color`, `stroke_width`, `icon` (SVG textarea)

**Pannello Configurazione:**
- `configuration` (Code JSON nullable) — fuso nel payload overlay del config app

**Pannello Stato** (solo detail):
- `file_path` (readonly)
- `generated_at` (readonly)
- Action "Rigenera" (solo se mode=generated)

### Tab "Overlays" su App Nova

- `overlays_label` (traducibile) — titolo filtro overlay in mappa
- `config_overlays` — lista ordinata di FeatureCollection (stesso pattern di config_home)

### LayerApiLinksCard

Aggiunge link per ogni `FeatureCollection` in mode=generated che include quel layer, se `enabled=true` e `file_path` presente.

---

## File da creare/modificare

### wm-package
- `src/Models/FeatureCollection.php` — nuovo modello
- `src/Nova/FeatureCollection.php` — nuova risorsa Nova
- `src/Jobs/FeatureCollection/GenerateFeatureCollectionJob.php` — nuovo job
- `src/Services/Models/FeatureCollectionService.php` — logica generazione
- `src/Observers/LayerObserver.php` (o aggiunta a observer esistente) — trigger rigenerazione
- `src/Services/Models/App/AppConfigService.php` — decommentare e adattare sezione overlays
- `src/Nova/Cards/ApiLinksCard/src/LayerApiLinksCard.php` — aggiungere link FeatureCollection
- `database/migrations/YYYY_create_feature_collections_table.php`
- `database/migrations/YYYY_create_feature_collection_layer_table.php`

### forestas (app)
- `app/Nova/FeatureCollection.php` — wrapper thin (estende wm-package)
- `app/Models/FeatureCollection.php` — wrapper thin se necessario

### App model / Nova
- Aggiungere `featureCollections()` hasMany su `App`
- Aggiungere `config_overlays` campo su App
- Aggiungere tab "Overlays" in Nova App
