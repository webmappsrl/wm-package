<template>
    <PanelItem :index="index" :field="field">
        <template #value>
            <FeatureCollectionMap
                :geojson-url="geojsonUrl"
                :height="field.height || 500"
                :enable-slope-chart="field.enableSlopeChart === true"
                :preserve-view-on-reload="true"
                :resource-name="resourceName"
                :resource-id="currentResourceId"
                @map-ready="handleMapReady" />
        </template>
    </PanelItem>
</template>

<script>
import FeatureCollectionMap from '../../../../../../../Nova/Fields/FeatureCollectionMap/resources/js/components/FeatureCollectionMap.vue';
import Feature from 'ol/Feature';
import Point from 'ol/geom/Point';
import VectorLayer from 'ol/layer/Vector';
import VectorSource from 'ol/source/Vector';
import { Style, Stroke, Icon } from 'ol/style';
import { buildLabelFeatures, paintSign } from '../trail-sign.mjs';
import { createViewMemory } from '../view-memory.mjs';

// A livello di modulo e non di componente: Nova, dopo un'Action, smonta e
// rimonta il dettaglio, e lo stato del componente si perde (oc:8662).
const viewMemory = createViewMemory(10000);

/**
 * La mappa della scheda di un codice del registro.
 *
 * Non duplica `FeatureCollectionMap`: lo usa come figlio e interviene
 * sull'evento `map-ready`, che espone la mappa OpenLayers e le feature
 * appena caricate. Tre cose che le prop del componente condiviso non
 * sanno esprimere, e per cui esiste questo bundle a parte (oc:8568):
 *
 * 1. i vicini vanno su un layer proprio con `declutter`, che e' un'opzione
 *    del layer e non dello stile: senza, in un settore denso le etichette
 *    si sovrappongono e non se ne legge piu' nessuna;
 * 2. l'etichetta si disegna solo oltre una soglia di zoom, altrimenti a
 *    mappa aperta 60 numeri sono rumore;
 * 3. l'inquadratura iniziale ignora i vicini: il componente condiviso la
 *    calcola su tutte le LineString, e con 60 tracce aprirebbe la mappa
 *    sull'intero settore, sotto la soglia delle etichette — la feature si
 *    annullerebbe da sola.
 *
 * Dal oc:8662, altre due:
 *
 * 4. il numero del codice in esame e' sempre visibile, anche senza vicini,
 *    su un layer proprio sopra i loro e con un segnavia di colore diverso;
 *    i numeri sono segnavia CAI orizzontali, non testo piegato sulla linea;
 * 5. l'URL del GeoJSON porta la versione della mappa (`field.mapVersion`):
 *    dopo un'Action Nova rilegge la risorsa, l'URL cambia e il componente
 *    condiviso riscarica la mappa senza reinquadrarla.
 *
 * Il tooltip invece non richiede nulla: il componente condiviso lo cerca
 * con `map.forEachFeatureAtPixel`, che scansiona tutti i layer. E' cio'
 * che rende accettabile la soglia — sotto di essa il numero non sparisce,
 * si legge passandoci sopra.
 */
export default {
    name: 'DetailTrailRegistryMap',

    components: { FeatureCollectionMap },

    props: ['index', 'resource', 'resourceName', 'resourceId', 'field'],

    data() {
        return {
            neighbourLayer: null,
            labelLayer: null,
            currentLabelLayer: null,
        };
    },

    created() {
        // Fuori dalla reattivita': sono canvas e una mappa OpenLayers, e Vue
        // non deve osservarli.
        this.signCache = new Map();
        this.olMap = null;
    },

    computed: {
        viewKey() {
            return `${this.resourceName}/${this.currentResourceId}`;
        },

        currentResourceId() {
            return this.resourceId || (this.resource && this.resource.id && this.resource.id.value);
        },

        /** Il livello di zoom oltre il quale le etichette compaiono. */
        labelMinZoom() {
            const fromField = this.field.labelMinZoom;

            return typeof fromField === 'number' ? fromField : 12;
        },

        geojsonUrl() {
            const base = this.field.geojsonUrl
                || `/nova-vendor/feature-collection-map/${this.resourceName}/${this.currentResourceId}`;

            // La versione cambia con il codice mostrato: dopo un'Action Nova
            // rilegge la risorsa, questo URL cambia e il watcher del
            // componente condiviso riscarica la mappa (oc:8662).
            if (!this.field.mapVersion) {
                return base;
            }

            return `${base}${base.includes('?') ? '&' : '?'}v=${encodeURIComponent(this.field.mapVersion)}`;
        },
    },

    beforeUnmount() {
        // La vista del gestore sopravvive al rimontaggio che Nova fa dopo
        // un'Action: vedi view-memory.mjs.
        const view = this.olMap?.getView?.();

        if (view) {
            viewMemory.remember(this.viewKey, { center: view.getCenter(), zoom: view.getZoom() }, Date.now());
        }

        this.olMap = null;
        this.neighbourLayer = null;
        this.labelLayer = null;
        this.currentLabelLayer = null;
    },

    methods: {
        handleMapReady({ map, features, reloaded }) {
            if (!map || !Array.isArray(features)) {
                return;
            }

            // A ogni caricamento si riparte da zero: una ricarica senza vicini
            // non deve lasciare sulla mappa i numeri della risposta prima.
            this.removeOwnLayers(map);

            const neighbours = features.filter((f) => f.get('neighbour') === true);
            const labels = buildLabelFeatures(features);

            if (neighbours.length > 0) {
                this.moveNeighboursToOwnLayer(map, neighbours, labels.neighbours);
            }

            // Fuori dall'if dei vicini: la prima istanza di un settore non ha
            // vicini, e il suo numero deve comparire lo stesso.
            this.addCurrentLabel(map, labels.current);

            this.olMap = map;

            // Alla ricarica la vista resta quella del gestore: sia quando il
            // componente riscarica i dati (`reloaded`), sia quando Nova lo ha
            // appena ricreato dopo un'Action (vista salvata allo smontaggio).
            if (reloaded) {
                return;
            }

            const saved = viewMemory.recall(this.viewKey, Date.now());

            if (saved) {
                map.getView().setCenter(saved.center);
                map.getView().setZoom(saved.zoom);

                return;
            }

            // Solo con i vicini, come prima di oc:8662: senza, l'inquadratura
            // del componente condiviso e' gia' quella giusta, e un secondo
            // fit cambierebbe lo zoom della mappa delle anomalie.
            if (neighbours.length > 0) {
                this.refitOn(map, features.filter((f) => f.get('neighbour') !== true));
            }
        },

        removeOwnLayers(map) {
            ['neighbourLayer', 'labelLayer', 'currentLabelLayer'].forEach((key) => {
                if (this[key]) {
                    map.removeLayer(this[key]);
                    this[key] = null;
                }
            });
        },

        moveNeighboursToOwnLayer(map, neighbours, labelItems) {
            // Toglie i vicini dal layer principale: senza, resterebbero
            // disegnati due volte, una per layer.
            map.getLayers().getArray().forEach((layer) => {
                const source = typeof layer.getSource === 'function' ? layer.getSource() : null;

                if (!source || typeof source.removeFeature !== 'function') {
                    return;
                }

                neighbours.forEach((feature) => {
                    if (typeof source.hasFeature === 'function' && source.hasFeature(feature)) {
                        source.removeFeature(feature);
                    }
                });
            });

            // Il tratto e' tenue perche' e' contesto, ma il layer sta **sopra**
            // quello principale: il settore e' un poligono pieno che copre tutta
            // l'area, e `forEachFeatureAtPixel` — da cui il componente condiviso
            // ricava il tooltip — si ferma alla prima feature che incontra
            // dall'alto. Sotto, i vicini non sarebbero mai raggiungibili col
            // mouse e si leggerebbe sempre il tooltip del settore.
            this.neighbourLayer = new VectorLayer({
                source: new VectorSource({ features: neighbours }),
                zIndex: 10,
                style: (feature) => new Style({
                    stroke: new Stroke({
                        color: feature.get('strokeColor') || 'rgba(100, 116, 139, 0.9)',
                        width: feature.get('strokeWidth') || 2,
                    }),
                }),
            });

            // I numeri stanno **sopra tutto**, su un layer a parte: disegnati
            // insieme al loro tratto finirebbero sotto le altre tracce e sotto
            // i poligoni dei settori, e li' non si leggono.
            //
            // Le etichette sono **feature nuove**, punti a meta' tracciato, e non
            // le stesse del layer sotto: con una source condivisa, il renderer
            // con `declutter` e quello senza si contendono la hit detection di
            // `forEachFeatureAtPixel`, ed e' da li' che il componente condiviso
            // ricava il tooltip. Non portano `tooltip` ne' `link`, cosi' la sola
            // feature raggiungibile col mouse resta quella della linea.
            //
            // `declutter` vive qui e non sulle linee perche' e' cio' che fa
            // scansare le etichette fra loro: applicato al layer dei tratti,
            // farebbe sparire i tratti insieme alle etichette senza posto.
            const labelFeatures = labelItems.map((item) => new Feature({
                geometry: new Point(item.coordinate),
                label: item.label,
                kind: item.kind,
            }));

            this.labelLayer = new VectorLayer({
                source: new VectorSource({ features: labelFeatures }),
                declutter: true,
                zIndex: 20,
                style: (feature, resolution) => this.labelStyle(map, feature, resolution),
            });

            map.addLayer(this.neighbourLayer);
            map.addLayer(this.labelLayer);
        },

        addCurrentLabel(map, items) {
            if (!items || items.length === 0) {
                return;
            }

            // Sopra i vicini e **senza** declutter: con declutter OpenLayers
            // potrebbe nasconderlo quando si sovrappone a un vicino con la
            // stessa geometria, che e' proprio il caso da cui nasce oc:8662.
            // Nessuna soglia di zoom: e' l'informazione principale della mappa.
            this.currentLabelLayer = new VectorLayer({
                source: new VectorSource({
                    features: items.map((item) => new Feature({
                        geometry: new Point(item.coordinate),
                        label: item.label,
                        kind: item.kind,
                    })),
                }),
                zIndex: 30,
                style: (feature) => this.signStyle(feature),
            });

            map.addLayer(this.currentLabelLayer);
        },

        signStyle(feature) {
            const label = feature.get('label');
            const kind = feature.get('kind');
            const ratio = window.devicePixelRatio || 1;
            const key = `${kind}|${label}|${ratio}`;

            // Un canvas per etichetta e stato, creato una volta: la style
            // function gira a ogni frame di zoom e pan.
            if (!this.signCache.has(key)) {
                const canvas = paintSign(document, label, kind, ratio);

                this.signCache.set(key, new Style({
                    image: new Icon({
                        img: canvas,
                        size: [canvas.width, canvas.height],
                        scale: 1 / ratio,
                    }),
                }));
            }

            return this.signCache.get(key);
        },

        labelStyle(map, feature, resolution) {
            const label = feature.get('label');

            // Uno Style vuoto e non `null`: con `declutter` attivo, uno stile
            // nullo lascia il renderer senza nulla da misurare.
            const nothing = new Style({});

            if (!label) {
                return nothing;
            }

            // La style function riceve la risoluzione, non lo zoom. La soglia
            // si scrive in zoom perche' e' il numero che si legge sulla mappa,
            // e si converte qui.
            const zoom = map.getView().getZoomForResolution(resolution);

            if (zoom < this.labelMinZoom) {
                return nothing;
            }

            return this.signStyle(feature);
        },

        refitOn(map, features) {
            // Solo le linee, come fa il componente condiviso: il settore e' un
            // poligono che copre tutta l'area, e includerlo qui vanifica il
            // senso stesso di questo refit — la mappa si riaprirebbe sul
            // settore intero invece che sulla traccia in esame.
            const lines = features.filter((feature) => {
                const geometry = feature.getGeometry();

                if (!geometry) {
                    return false;
                }

                const type = geometry.getType();

                return type === 'LineString' || type === 'MultiLineString';
            });

            if (lines.length === 0) {
                return;
            }

            const source = new VectorSource({ features: lines.map((f) => f.clone()) });
            const extent = source.getExtent();

            if (!extent || extent[0] === Infinity) {
                return;
            }

            const view = map.getView();

            view.fit(extent, {
                size: map.getSize(),
                padding: [50, 50, 50, 50],
                maxZoom: Math.min(view.getMaxZoom() || 17, 17),
            });
        },
    },
};
</script>
