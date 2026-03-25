# TaxonomyWhere (wm-package)

> Last updated: 2026-03-25

## Overview

`TaxonomyWhere` rappresenta un'area geografica amministrativa (regione, provincia, comune, municipio, quartiere) o un settore CAI, usata per classificare spazialmente tracce (`EcTrack`) e punti di interesse (`EcPoi`). Fornisce la logica base di import, deduplicazione e recupero asincrono della geometria PostGIS da sorgenti esterne (OSMFeatures, OSM2CAI).

I progetti che dipendono da wm-package possono estendere sia il modello Eloquent che la risorsa Nova per aggiungere sorgenti di import o comportamenti specifici.

---

## Eloquent Model

### Classe e namespace

`Wm\WmPackage\Models\TaxonomyWhere`

### Gerarchia di ereditarietà

```
TaxonomyWhere
  └── Wm\WmPackage\Models\Abstracts\Taxonomy
        └── Wm\WmPackage\Models\Abstracts\Polygon
              └── Wm\WmPackage\Models\Abstracts\GeometryModel
                    └── Illuminate\Database\Eloquent\Model
```

### Table & Database

- **Tabella:** `taxonomy_wheres`

| Colonna | Tipo | Note |
|---------|------|------|
| `id` | `bigint` | PK auto-increment |
| `name` | `text` | Translatable (array JSON) |
| `geometry` | `geography(multipolygon)` | **PostGIS** — nullable, popolata in background |
| `properties` | `jsonb` | Metadata strutturati (vedi sotto) |
| `created_at` | `timestamp` | — |
| `updated_at` | `timestamp` | — |

> **Nota:** le colonne `osmfeatures_id` e `admin_level` che esistevano in versioni precedenti sono state consolidate dentro `properties`. Esiste un indice parziale su `(properties->>'osmfeatures_id')`.

#### Chiavi rilevanti in `properties`

| Chiave | Tipo | Sorgente | Descrizione |
|--------|------|----------|-------------|
| `osmfeatures_id` | string | OSMFeatures | ID univoco OSMFeatures (es. `A12345`) |
| `admin_level` | int | OSMFeatures | Livello amministrativo OSM (4, 6, 8, 9, 10) |
| `source` | string | tutte | `'osmfeatures'`, `'osm2cai'` (o valori aggiuntivi definiti nel progetto) |
| `source_updated_at` | ISO8601 string | OSMFeatures / OSM2CAI | Data di aggiornamento alla sorgente |
| `osm2cai_id` | int | OSM2CAI | ID settore CAI |
| `code` | string | OSM2CAI | Codice settore CAI |
| `full_code` | string | OSM2CAI | Codice completo settore CAI |
| `human_name` | string | OSM2CAI | Nome leggibile settore CAI |
| `manager` | string | OSM2CAI | Gestore del settore CAI |

### Fillable

```php
protected $fillable = ['name', 'geometry', 'properties'];
```

Sovrascrive il fillable del parent `Taxonomy` (che include anche `import_method`, `identifier`, `description`, `excerpt`, `icon`).

### Casts

Ereditati da `Taxonomy`:

| Attributo | Cast |
|-----------|------|
| `name` | `array` (translatable) |
| `description` | `array` (translatable) |
| `excerpt` | `array` (translatable) |
| `properties` | `array` |

### Relationships

| Metodo | Tipo | Modello target | Tabella pivot | Note |
|--------|------|----------------|---------------|------|
| `ecTracks()` | `MorphToMany` | `EcTrack` (configurabile via `wm-package.ec_track_model`) | `taxonomy_whereables` | FK `taxonomy_where_id`; pivot `TaxonomyWhereable` |
| `layers()` | `MorphToMany` | `Layer` | `taxonomy_whereables` | FK `taxonomy_where_id`; pivot `TaxonomyWhereable` |
| `ecPois()` | `MorphToMany` | `EcPoi` | ereditato da `Taxonomy` | via chiave morfologica `whereable` |

> **Tabella `taxonomy_whereables`:** colonne `taxonomy_where_id` (FK), `taxonomy_whereable_id`, `taxonomy_whereable_type` (morph). Il pivot usa il model intermedio `TaxonomyWhereable`.

### Traits

| Trait | Provenienza | Scopo |
|-------|-------------|-------|
| `HasTranslations` (Spatie) | `Taxonomy` | Supporto multilingua per `name`, `description`, `excerpt` |
| `HasFactory` | `Taxonomy` | Factory per test |
| `FeatureCollectionMapTrait` | `TaxonomyWhere` | Renderizza la geometria come FeatureCollection per il campo Nova |
| `HasSafeTranslatable` | `GeometryModel` | Wrapper sicuro per `HasTranslations` |
| `InteractsWithMedia` (Spatie) | `GeometryModel` | Gestione media allegati (collection `default`) |

### Metodi accessori notevoli

| Metodo | Descrizione |
|--------|-------------|
| `getOsmfeaturesId(): ?string` | Legge `properties['osmfeatures_id']` |
| `getAdminLevel(): ?int` | Legge `properties['admin_level']` con cast a `int` |
| `getSource(): ?string` | Legge `properties['source']` |
| `getRelationKey(): string` | Ritorna `'whereable'` (chiave morph usata dal parent) |
| `getFeatureCollectionMap(): array` | Geometria come FeatureCollection con stile (bordo blu `rgba(37,99,235,1)`, fill `rgba(37,99,235,0.2)`) |
| `getJson(): array` | Rappresentazione array ripulita (rimuove `pivot`, `import_method`, `source`, `user_id`, ecc.) — ereditato da `Taxonomy` |
| `getGeojson(): ?array` | Esporta la geometria come GeoJSON via `GeoJsonService` — ereditato da `GeometryModel` |

### Events & Observers

Registrato tramite `Taxonomy::boot()`:

- **`TaxonomyObserver`**
  - `creating`: valida unicità del campo `identifier` (se valorizzato)
  - `updating`: normalizza `identifier` con `Str::slug()`

---

## Nova Resource

### Classe e namespace

`Wm\WmPackage\Nova\TaxonomyWhere`

### Gerarchia di ereditarietà

```
Wm\WmPackage\Nova\TaxonomyWhere
  └── Wm\WmPackage\Nova\AbstractTaxonomyResource
        └── Laravel\Nova\Resource
```

### Parent Class

`AbstractTaxonomyResource` definisce campi base (ID, Name, Description, IconSelect, Properties/Code), ricerca su `id` e `name`, e metodi vuoti per filtri, azioni, lenses. `Wm\WmPackage\Nova\TaxonomyWhere` sovrascrive tutti questi con campi, filtri e azioni specifici per le aree geografiche.

### Fields

| Campo | Tipo Nova | Visibilità | Descrizione |
|-------|-----------|------------|-------------|
| `ID` | `ID` | index, detail | PK — sortable |
| `name` | `Text` | index, detail, form | Nome (translatable) |
| `source` | `Text` (computed) | solo index | Legge `getSource()` — readonly |
| `Geometry` | `FeatureCollectionMap` | solo detail | Mappa interattiva con geometria PostGIS |
| `Geometry` | `Boolean` | solo index | `true` se geometry non è null |
| `Proprietà` | `PropertiesPanel` | detail, form | Pannello collassabile JSON properties |

> **Nota:** il label "Geometry" appare due volte in index — è intenzionale. Un campo `Boolean` mostra presenza/assenza in lista, mentre il `FeatureCollectionMap` è visibile solo nel dettaglio.

### Filters

| Filtro | Classe | Tipo | Opzioni |
|--------|--------|------|---------|
| Sorgente | `TaxonomyWhereSourceFilter` | select | OSMFeatures / OSM2CAI |
| Admin Level | `TaxonomyWhereAdminLevelFilter` | select | Regione (L4), Provincia (L6), Comune (L8), Municipio (L9), Quartiere (L10) |
| Geometria | `TaxonomyWhereHasGeometryFilter` | select | Presente / Assente |

> **Nota:** `TaxonomyWhereSourceFilter` include solo `osmfeatures` e `osm2cai`. Se il progetto aggiunge sorgenti custom (es. `geohub_conf_32`), il filtro non le copre a meno che non venga sovrascritto nella risorsa app-level.

### Actions

| Azione | Classe | Standalone | Descrizione |
|--------|--------|------------|-------------|
| Import TaxonomyWhere | `ImportTaxonomyWhere` | Si | Importa aree da OSMFeatures (vari admin level) o settori da OSM2CAI; dispatch job geometria in background; sincronizza track al termine |
| Crea Layer | `CreateLayerFromTaxonomyWhere` | No | Crea un `Layer` per ogni `TaxonomyWhere` selezionata, copia `feature_image` via Spatie Media |
| Ricarica Geometry | `RetryTaxonomyWhereGeometryFetch` | No | Ri-dispatcha `FetchTaxonomyWhereGeometryJob` sui record selezionati |
| Sincronizza Tracks | `SyncTracksTaxonomyWhereAction` | Si | Chiama `GeometryComputationService::syncTracksTaxonomyWhere()` su tutte le track |

### Lenses

Nessuna.

### Metrics

Nessuna.

### Authorization & Policies

Nessun `TaxonomyWherePolicy` dedicato nel wm-package. L'accesso a Nova è controllato dal gate globale del progetto (tipicamente `!$user->hasRole('Guest')`). Non ci sono restrizioni per ruolo aggiuntive sulla risorsa stessa.

### Nova Menu

La risorsa è pensata per essere inserita in una sezione **Taxonomies** del menu Nova. Il posizionamento esatto è definito nel `NovaServiceProvider` del progetto.

---

## Behaviors & Business Logic

### Geometria asincrona

La geometria PostGIS non viene mai impostata in modo sincrono durante l'import. Viene recuperata tramite job in coda:

- **`FetchTaxonomyWhereGeometryJob`** — per record OSMFeatures: chiama `OsmfeaturesClient::getAdminAreaDetail()`, aggiorna `geometry` via `ST_GeomFromGeoJSON()` raw SQL.
- **`FetchOsm2caiSectorGeometryJob`** — per record OSM2CAI: chiama `Osm2caiClient::getSectorDetail()`, arricchisce `properties` (`code`, `full_code`, `human_name`, `manager`), aggiorna `geometry`.

Entrambi i job hanno 3 tentativi con backoff di 60 secondi.

### Deduplicazione all'import

I record esistenti sono identificati via `properties->>'osmfeatures_id'` o `properties->>'osm2cai_id'`. Se `source_updated_at` nell'API non è più recente del valore già salvato in `properties`, il record viene saltato.

### Sincronizzazione tracks

Ogni import termina con `GeometryComputationService::syncTracksTaxonomyWhere()`, che assegna la taxonomy_where corretta a ogni `EcTrack` in base alla geometria.

### Assegnazione user_id

Se la tabella `taxonomy_wheres` ha la colonna `user_id` (verificata via `Schema::hasColumn`), l'utente viene ereditato dall'`App` selezionata durante l'import.

### Azione "Crea Layer"

`CreateLayerFromTaxonomyWhere` crea il `Layer`, lo collega alla `TaxonomyWhere` e tenta di scaricare la `feature_image` (se presente in `properties`) via Spatie Media Library nella collection `default` del Layer. In caso di errore media, la creazione del Layer prosegue e l'errore viene loggato con `Log::warning`.

---

## Related Jobs

| Job | Trigger | Descrizione |
|-----|---------|-------------|
| `FetchTaxonomyWhereGeometryJob` | Import OSMFeatures / RetryGeometryFetch | Scarica geometry da OSMFeatures via `osmfeatures_id` |
| `FetchOsm2caiSectorGeometryJob` | Import OSM2CAI | Scarica geometry da OSM2CAI via `osm2cai_id` |

---

## Related Services

| Service | Utilizzo |
|---------|---------|
| `GeometryComputationService` | `syncTracksTaxonomyWhere()` post-import |
| `OsmfeaturesClient` | API OSMFeatures |
| `Osm2caiClient` | API OSM2CAI |

---

## Notes

- La colonna `geometry` è di tipo `geography(multipolygon)` PostGIS — può essere null se il job non è ancora stato eseguito o è fallito.
- `TaxonomyWhereSourceFilter` non include sorgenti custom aggiunte a livello di progetto: i progetti che aggiungono sorgenti devono sovrascrivere `filters()` nella risorsa Nova app-level.
- Il modello supporta l'override della classe `EcTrack` tramite `config('wm-package.ec_track_model')`.
