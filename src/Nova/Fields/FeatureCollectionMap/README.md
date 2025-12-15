# FeatureCollectionMap - Nova Field

Campo personalizzato per Laravel Nova che visualizza una mappa interattiva con dati GeoJSON, supporto per tooltip, popup e arricchimento DEM.

## Indice

1. [Installazione](#installazione)
2. [Uso Base](#uso-base)
3. [Configurazione](#configurazione)
4. [Architettura](#architettura)
5. [API Endpoint](#api-endpoint)
6. [Preparazione del Model](#preparazione-del-model)
7. [Properties GeoJSON](#properties-geojson)
8. [Eventi Vue](#eventi-vue)
9. [Estensione del Componente](#estensione-del-componente)
10. [Arricchimento DEM](#arricchimento-dem)

---

## Installazione

Il field è incluso nel pacchetto `wm-package`. Assicurati che il service provider sia registrato.

```php
// config/app.php
'providers' => [
    Wm\WmPackage\WmPackageServiceProvider::class,
],
```

---

## Uso Base

### Nel Nova Resource

```php
use Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src\FeatureCollectionMap;

public function fields(NovaRequest $request)
{
    return [
        FeatureCollectionMap::make(__('Geometry'), 'geometry'),
    ];
}
```

Il campo è automaticamente impostato come `onlyOnDetail()`.

---

## Configurazione

### Metodi Disponibili

| Metodo | Descrizione | Default |
|--------|-------------|---------|
| `height(int $height)` | Altezza della mappa in pixel | `500` |
| `showZoomControls(bool $enabled)` | Mostra/nasconde i controlli zoom | `true` |
| `mouseWheelZoom(bool $enabled)` | Abilita zoom con rotellina | `true` |
| `dragPan(bool $enabled)` | Abilita pan con drag | `true` |
| `padding(int $padding)` | Padding per il fit della vista | `50` |
| `geojsonUrl(string $url)` | URL personalizzato per il GeoJSON | auto |
| `withDemEnrichment(bool $enabled)` | Abilita arricchimento DEM | `false` |
| `withPopupComponent(string $name)` | Componente popup personalizzato | `null` |

### Esempio Completo

```php
FeatureCollectionMap::make(__('Mappa'), 'geometry')
    ->height(600)
    ->showZoomControls(true)
    ->mouseWheelZoom(true)
    ->dragPan(true)
    ->padding(30)
    ->withDemEnrichment(),
```

---

## Architettura

```
FeatureCollectionMap/
├── src/
│   └── FeatureCollectionMap.php      # Campo Nova (PHP)
│   └── FieldServiceProvider.php      # Service Provider
├── resources/
│   └── js/
│       ├── field.js                  # Entry point Vue
│       └── components/
│           ├── DetailField.vue       # Wrapper per vista detail
│           ├── FormField.vue         # Wrapper per form (placeholder)
│           ├── IndexField.vue        # Wrapper per index (placeholder)
│           └── FeatureCollectionMap.vue  # Componente mappa principale
├── Routes/
│   └── api.php                       # Endpoint GeoJSON
└── views/
    └── feature-collection-map.blade.php  # Vista standalone (opzionale)
```

### Flusso dei Dati

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│  Nova Resource  │────▶│  DetailField.vue │────▶│  GeoJSON API    │
│  (PHP)          │     │  (Vue)           │     │  /nova-vendor/  │
└─────────────────┘     └──────────────────┘     └─────────────────┘
                               │                         │
                               ▼                         ▼
                        ┌──────────────────┐     ┌─────────────────┐
                        │FeatureCollection │◀────│    Model        │
                        │    Map.vue       │     │getFeatureCol... │
                        └──────────────────┘     └─────────────────┘
```

---

## API Endpoint

### GET `/nova-vendor/feature-collection-map/{model}/{id}`

Restituisce il GeoJSON per il modello specificato.

**Parametri URL:**
- `model`: Nome del modello in kebab-case (es: `hiking-route`)
- `id`: ID del record

**Query Parameters:**
- `dem_enrichment=1`: Abilita l'arricchimento DEM (opzionale)

**Esempio:**
```
GET /nova-vendor/feature-collection-map/hiking-route/123?dem_enrichment=1
```

**Response:**
```json
{
  "type": "FeatureCollection",
  "features": [
    {
      "type": "Feature",
      "geometry": { "type": "Point", "coordinates": [11.5, 45.2] },
      "properties": {
        "id": 456,
        "name": "207/34",
        "tooltip": "207/34",
        "clickAction": "popup",
        "dem": {
          "elevation": 1250,
          "matrix_row": { ... }
        }
      }
    }
  ]
}
```

---

## Preparazione del Model

Il modello deve implementare il metodo `getFeatureCollectionMap()`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HikingRoute extends Model
{
    /**
     * Restituisce il GeoJSON per la mappa
     * 
     * @return array GeoJSON FeatureCollection
     */
    public function getFeatureCollectionMap(): array
    {
        // Crea la FeatureCollection base
        $geojson = [
            'type' => 'FeatureCollection',
            'features' => []
        ];

        // Aggiungi la geometria principale (linea del percorso)
        $mainFeature = [
            'type' => 'Feature',
            'geometry' => json_decode($this->geometry, true),
            'properties' => [
                'id' => $this->id,
                'strokeColor' => 'blue',
                'strokeWidth' => 4,
            ]
        ];
        $geojson['features'][] = $mainFeature;

        // Aggiungi punti (pali, POI, ecc.)
        foreach ($this->poles as $pole) {
            $geojson['features'][] = [
                'type' => 'Feature',
                'geometry' => json_decode($pole->geometry, true),
                'properties' => [
                    'id' => $pole->id,
                    'name' => $pole->ref,
                    'tooltip' => $pole->ref,
                    'clickAction' => 'popup',  // o 'link'
                    'pointFillColor' => 'rgba(255, 0, 0, 0.8)',
                    'pointStrokeColor' => 'white',
                    'pointRadius' => 6,
                ]
            ];
        }

        return $geojson;
    }
}
```

---

## Properties GeoJSON

### Properties per Linee/Poligoni

| Property | Tipo | Descrizione | Default |
|----------|------|-------------|---------|
| `id` | int/string | ID univoco della feature | - |
| `strokeColor` | string | Colore del bordo (CSS) | `'rgba(0, 0, 255, 1)'` |
| `strokeWidth` | number | Spessore del bordo in pixel | `3` |
| `fillColor` | string | Colore di riempimento (CSS) | `'rgba(0, 0, 255, 0.3)'` |

### Properties per Punti

| Property | Tipo | Descrizione | Default |
|----------|------|-------------|---------|
| `id` | int/string | ID univoco della feature | - |
| `name` | string | Nome visualizzato nel popup | - |
| `tooltip` | string | Testo del tooltip on hover | - |
| `clickAction` | string | Azione al click: `'popup'`, `'link'`, `'none'` | `'none'` |
| `link` | string | URL per `clickAction: 'link'` | - |
| `pointFillColor` | string | Colore riempimento punto | `'rgba(255, 0, 0, 0.8)'` |
| `pointStrokeColor` | string | Colore bordo punto | `'rgba(255, 255, 255, 1)'` |
| `pointStrokeWidth` | number | Spessore bordo punto | `2` |
| `pointRadius` | number | Raggio del punto in pixel | `6` |

### Properties per Arricchimento DEM

Quando `withDemEnrichment()` è abilitato, l'API DEM aggiunge:

| Property | Tipo | Descrizione |
|----------|------|-------------|
| `dem.elevation` | number | Quota del punto in metri |
| `dem.matrix_row` | object | Matrice distanze verso altri punti |

**Struttura `matrix_row`:**
```json
{
  "dem": {
    "elevation": 1250,
    "matrix_row": {
      "848": {                    // ID del percorso
        "5001": {                 // ID del palo destinazione
          "distance": 500,        // Distanza in metri
          "time_hiking": 600,     // Tempo hiking in secondi
          "time_bike": 300,       // Tempo bike in secondi
          "ascent": 50,           // Dislivello positivo
          "descent": 20,          // Dislivello negativo
          "elevation_from": 1250, // Quota partenza
          "elevation_to": 1280    // Quota arrivo
        }
      }
    }
  }
}
```

---

## Eventi Vue

Il componente `FeatureCollectionMap.vue` emette i seguenti eventi:

| Evento | Payload | Descrizione |
|--------|---------|-------------|
| `map-ready` | `{ map, features, geojson, featuresMap }` | Mappa inizializzata |
| `feature-click` | `{ feature, properties, action }` | Click su una feature |
| `popup-open` | `{ feature, properties, id, dem, featuresMap }` | Popup aperto |
| `popup-close` | `{ id }` | Popup chiuso |

### Esempio Ascolto Eventi

```vue
<FeatureCollectionMap 
    :geojson-url="url"
    @map-ready="onMapReady"
    @feature-click="onFeatureClick"
    @popup-open="onPopupOpen"
/>
```

---

## Estensione del Componente

Per creare un popup personalizzato, puoi estendere il componente base.

### 1. Crea il Componente Esteso

```vue
<!-- resources/js/components/SignageMap.vue -->
<template>
    <div class="feature-collection-map-container">
        <div ref="mapContainer" class="map-container"></div>
        
        <!-- Tooltip (ereditato) -->
        <div ref="tooltipElement" class="map-tooltip" v-show="tooltipVisible">
            {{ tooltipText }}
        </div>

        <!-- POPUP PERSONALIZZATO -->
        <Teleport to="body">
            <div v-if="showPopup" class="custom-popup">
                <!-- Il tuo popup custom qui -->
                <h3>{{ popupTitle }}</h3>
                <table>
                    <!-- Tabella DEM, checkbox mete, ecc. -->
                </table>
                <button @click="closePopup">Chiudi</button>
                <button @click="saveDestinations">Salva</button>
            </div>
        </Teleport>
    </div>
</template>

<script>
// Importa il setup dal componente base
import { ref, onMounted, onUnmounted, watch, reactive } from 'vue';
// ... importa OpenLayers come nel componente base

export default {
    name: 'SignageMap',
    
    // Eredita props dal componente base
    props: {
        geojsonUrl: { type: String, required: true },
        height: { type: Number, default: 500 },
        // ... altre props
    },
    
    setup(props, { emit }) {
        // Copia il setup base e aggiungi la tua logica
        // ...
        
        // Aggiungi stato per le selezioni
        const selections = reactive({});
        
        // Aggiungi metodi custom
        const saveDestinations = async () => {
            // Logica di salvataggio
        };
        
        return {
            // ... return base
            selections,
            saveDestinations,
        };
    }
};
</script>
```

### 2. Registra il Componente in Nova

```javascript
// resources/js/signage-map.js
import SignageMap from './components/SignageMap.vue';

Nova.booting((app) => {
    app.component('signage-map', SignageMap);
});
```

### 3. Carica lo Script in Nova

```php
// app/Providers/NovaServiceProvider.php
use Laravel\Nova\Events\ServingNova;

public function boot()
{
    Nova::serving(function (ServingNova $event) {
        Nova::script('signage-map', public_path('js/signage-map.js'));
    });
}
```

### 4. Crea un Nuovo Nova Field (Opzionale)

```php
// app/Nova/Fields/SignageMap.php
namespace App\Nova\Fields;

use Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src\FeatureCollectionMap;

class SignageMap extends FeatureCollectionMap
{
    public $component = 'signage-map';
}
```

---

## Arricchimento DEM

### Abilitazione

```php
FeatureCollectionMap::make(__('Geometry'), 'geometry')
    ->withDemEnrichment(),
```

### Come Funziona

1. Il frontend richiede il GeoJSON con `?dem_enrichment=1`
2. L'API chiama `DemClient::getPointMatrix($geojson)`
3. Il servizio DEM esterno arricchisce ogni punto con:
   - Quota (elevation)
   - Matrice distanze verso altri punti
   - Tempi di percorrenza (hiking/bike)
   - Dislivelli (ascent/descent)

### Configurazione DEM Client

```env
# .env
DEM_HOST=https://dem.maphub.it
DEM_POINT_MATRIX_API=api/v1/feature-collection/point-matrix
```

---

## Troubleshooting

### La mappa non carica

1. Verifica che il modello abbia il metodo `getFeatureCollectionMap()`
2. Controlla la console per errori nel fetch del GeoJSON
3. Verifica che l'URL sia corretto: `/nova-vendor/feature-collection-map/{model}/{id}`

### Il popup non si apre

1. Verifica che `clickAction: 'popup'` sia impostato nelle properties
2. Controlla che il componente Vue sia compilato correttamente

### L'arricchimento DEM fallisce

1. Verifica la configurazione del DEM_HOST in `.env`
2. Controlla i log Laravel per errori del DemClient
3. L'API restituirà comunque il GeoJSON senza arricchimento in caso di errore

---

## License

MIT


