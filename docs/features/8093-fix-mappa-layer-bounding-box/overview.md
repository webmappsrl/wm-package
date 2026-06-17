> Ticket: oc:8093

# Fix: mappa layer mostra bounding box invece delle geometrie reali

## Cosa cambia

La mappa nella pagina di dettaglio di un Layer torna a mostrare le EcTracks e le TaxonomyWheres associate, invece del rettangolo blu (bounding box del layer).

## Perché

### Commit che ha introdotto il bug

**`fb3c0555`** — `feat(oc:7756): add BboxField standalone Nova component for bounding box`
→ https://github.com/webmappsrl/wm-package/commit/fb3c0555e823d9a988f2aee849a293cb1983a4f5

Durante la compilazione del dist per il ticket **oc:7756** (aggiunta del BboxField), il dist `field.js` di `FeatureCollectionMap` è stato ricompilato da una working copy locale che conteneva modifiche temporanee mai committate. Il risultato è che il dist conteneva una prop non presente nella sorgente `DetailField.vue`:

```javascript
"inline-geojson": A.field.geojson || null
```

Questo passava la geometry grezza del Layer (MultiPolygon/bbox) come `inlineGeojson` al componente Vue. Quando `inlineGeojson` è truthy, il componente lo usa direttamente senza chiamare l'API — il polygon veniva così renderizzato con il fill blu di default (`rgba(0,0,255,0.3)`), producendo il rettangolo blu.

Il dist era stato compilato da una versione locale temporanea di `DetailField.vue` che non è mai stata committata.

## Requisiti

- [x] La mappa del Layer mostra le EcTracks associate (non il bbox polygon)
- [x] Il dist `field.js` è allineato alla sorgente `DetailField.vue`

## Rischi

Nessuno: la fix è una ricompilazione della sorgente esistente senza modifiche al codice PHP o Vue.

## Out of scope

- Aggiunta di EcPoi alla mappa del layer
- Modifiche a `getFeatureCollectionMap()` nel modello Layer

## Moduli toccati

- `wm-package/src/Nova/Fields/FeatureCollectionMap/dist/js/field.js` — ricompilato
- `wm-package/src/Nova/Fields/FeatureCollectionMap/dist/css/field.css` — ricompilato
- `wm-package/src/Nova/Fields/FeatureCollectionMap/dist/mix-manifest.json` — aggiornato
