> Ticket: oc:8140

# Fix: properties.layers su EcPoi valorizzato erroneamente per layer senza taxonomy_where

## Cosa cambia

`LayerService::updateLayersPropertyOnLayeredFeature()` smette di aggiornare `properties['layers']` degli EcPoi **e EcTrack** quando il layer non ha né modelli manuali in `layerables` né filtri di tassonomia validi (`taxonomy_where` non vuoto o `taxonomyActivities` presenti). La guard viene aggiunta **solo** nel path di scrittura, lasciando invariato il comportamento delle letture Nova.

## Perché

`scopeByWhereProperty()` fa early return senza aggiungere condizioni alla query quando `properties['taxonomy_where']` è assente. `EcFeatureTrait::scopeOnLayer()` lo chiama come unico filtro geografico: se non aggiunge nulla, la query restituisce tutti i modelli dell'app con geometria non nulla. Per un layer in auto-mode senza modelli manuali, `getRelatedModelsQuery()` segue il path `getAllVisibleModelsQuery()` → `onLayer()` → `byWhereProperty()` senza filtro → `updateLayersPropertyOnLayeredFeature()` scrive l'ID del layer in `properties['layers']` di **tutti** i modelli dell'app. Risultato: POI e tracce associati a layer/cammini errati nelle app. Il bug è identico per EcPoi ed EcTrack — il fix li copre entrambi senza biforcazione, simmetrico con il guard già presente in `assignTracksByTaxonomy()`.

## Requisiti

- [ ] `LayerService::updateLayersPropertyOnLayeredFeature()` salta il processing per layer che non hanno né modelli manuali in `layerables` né filtri validi (`taxonomy_where` non vuoto OR `taxonomyActivities` presenti) — si applica a tutti i model class in `getModelsWithLayersInProperties()` (EcPoi e EcTrack)
- [ ] Il guard si applica solo alla scrittura; `getAllVisibleModels()` (letture Nova) non viene modificato
- [ ] La logica del guard è consistente con il pattern già presente in `assignTracksByTaxonomy()` (check su `empty(taxonomyIds) && empty(whereIds)`)
- [ ] Copertura test: layer senza filtri → 0 modelli aggiornati; layer con modelli manuali → aggiorna solo quelli in layerables; layer con taxonomy filter → comportamento invariato

## Rischi

- **N+1 query sul guard**: `$layer->taxonomyActivities` fa una query al DB per ogni layer processato nel job. Mitigazione: verificare se la collection è già eager-loaded sul Layer prima di accederla nel guard; se non lo è, usare `$layer->taxonomyActivities()->exists()` invece di caricare l'intera collection.
- **Impatto su altri progetti wm-package**: il fix cambia il comportamento di `updateLayersPropertyOnLayeredFeature` per tutti i progetti che usano wm-package. Progetti con layer privi di taxonomy e POI manuali smetteranno di aggiornare `properties['layers']` su EcPoi — comportamento corretto, ma da verificare che nessun progetto si aspettasse il comportamento precedente (tutti i POI visibili).

## Out of scope

- Modifica a `scopeByWhereProperty()` (la root cause è gestita a livello superiore nella call chain)
- Rigenerazione PBF

## Moduli toccati

- `wm-package/src/Services/Models/LayerService.php` — guard in `updateLayersPropertyOnLayeredFeature()`
