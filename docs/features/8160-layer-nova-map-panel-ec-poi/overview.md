> Ticket: oc:8160

# Layer Nova: sposta Geometry fuori dal panel Ec Tracks e mostra anche EcPoi sulla mappa

## Cosa cambia

Nella pagina di dettaglio del Layer in Nova:

1. `FeatureCollectionMap` viene spostato fuori dal `Panel::make('Ec Tracks', [...])` in un panel dedicato `Panel::make(__('Map'), [...])`, posizionato prima dei panel "Ec Tracks" ed "Ec Pois".
2. `Layer::getFeatureCollectionMap()` viene esteso per includere le geometrie degli EcPoi associati al layer (via tabella `layerables`), con tooltip sul nome e link alla scheda Nova del POI.
3. Aggiunta traduzione `"Map"` → `"Mappa"` in `resources/lang/it.json`.

## Perché

Il campo `FeatureCollectionMap` mostra la geometria complessiva del layer (bounding box + features). Collocarlo dentro "Ec Tracks" è semanticamente sbagliato: la mappa rappresenta il layer nel suo insieme, non solo le tracce. Inoltre la mappa non mostra gli EcPoi associati, rendendo incompleta la visione geografica del contenuto del layer dalla sua pagina di dettaglio.

## Requisiti

- [ ] `FeatureCollectionMap::make(__('Geometry'), 'geometry')->onlyOnDetail()` spostato in `Panel::make(__('Map'), [...])` separato
- [ ] Il panel "Map" è posizionato prima di "Ec Tracks" e "Ec Pois" nel layout Nova
- [ ] `Panel::make('Ec Tracks', [...])` contiene solo `LayerFeatures` per le tracce (senza `FeatureCollectionMap`)
- [ ] `Layer::getFeatureCollectionMap()` carica le geometrie degli EcPoi associati via `layerables` con raw SQL (stesso pattern EcTrack)
- [ ] Ogni EcPoi aggiunto alla mappa ha `tooltip` (nome), `link` (`nova/resources/ec-pois/{id}`); nessun colore esplicito (il Vue component applica i propri default: `pointFillColor: rgba(255,0,0,0.8)`, `pointRadius: 6`)
- [ ] Traduzione `"Map"` aggiunta in `resources/lang/it.json` → `"Mappa"` e in `resources/lang/en.json` → `"Map"`
- [ ] Test in `wm-package/tests/Feature/` che verificano: (a) EcPoi features presenti nel risultato di `getFeatureCollectionMap()` quando esistono EcPoi associati; (b) EcPoi senza geometria non producono features; (c) layer senza EcPoi non rompe il metodo

## Rischi

- **Nessun `ec_poi_table` in config** (a differenza di `ec_track_table`): il nome tabella va derivato da `(new EcPoi)->getTable()` o hardcodato come `ec_pois`. Soluzione: hardcode `ec_pois` + commento — è lo stesso approccio usato per `taxonomy_wheres` nello stesso metodo.
- **Performance**: nessun limite al numero di EcPoi caricati (coerente con il comportamento esistente per EcTrack). Layer con migliaia di EcPoi potrebbero rallentare la risposta API — rischio accettato per design, allineato al pattern corrente.
- **Dist JS non ricompilato**: tutte le modifiche sono PHP-only. Il Vue component `FeatureCollectionMap` già supporta geometrie `Point` e le renderizza con i default. Nessun rebuild necessario.

## Out of scope

- Stili EcPoi configurabili per layer (colore/dimensione punti personalizzabili dalla UI Nova)
- Limite o paginazione degli EcPoi mostrati sulla mappa
- Aggiunta di `ec_poi_table` alla configurazione del package
- Modifica al Vue component `FeatureCollectionMap.vue`

## Moduli toccati

| Repo | File | Modifica |
|---|---|---|
| `wm-package` | `src/Nova/Layer.php` | Sposta `FeatureCollectionMap` in panel "Map"; rimuovilo da panel "Ec Tracks" |
| `wm-package` | `src/Models/Layer.php` | `getFeatureCollectionMap()`: aggiunge loop EcPoi con query SQL + `addFeaturesForMap()` |
| `wm-package` | `resources/lang/it.json` | Aggiunge `"Map": "Mappa"` |
| `wm-package` | `resources/lang/en.json` | Aggiunge `"Map": "Map"` |
| `wm-package` | `tests/Feature/LayerGetFeatureCollectionMapEcPoisTest.php` | Nuovo — test copertura logica EcPoi in `getFeatureCollectionMap()` |
