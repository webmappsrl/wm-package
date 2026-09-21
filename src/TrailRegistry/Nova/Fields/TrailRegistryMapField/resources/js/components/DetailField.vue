<template>
    <PanelItem :index="index" :field="field">
        <template #value>
            <FeatureCollectionMap
                :geojson-url="geojsonUrl"
                :height="field.height || 500"
                :enable-slope-chart="false"
                :resource-name="resourceName"
                :resource-id="currentResourceId"
                @map-ready="handleMapReady" />
        </template>
    </PanelItem>
</template>

<script>
import FeatureCollectionMap from '../../../../../../../Nova/Fields/FeatureCollectionMap/resources/js/components/FeatureCollectionMap.vue';
import VectorLayer from 'ol/layer/Vector';
import VectorSource from 'ol/source/Vector';
import { Style, Stroke, Text, Fill } from 'ol/style';

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
        };
    },

    computed: {
        currentResourceId() {
            return this.resourceId || (this.resource && this.resource.id && this.resource.id.value);
        },

        /** Il livello di zoom oltre il quale le etichette compaiono. */
        labelMinZoom() {
            const fromField = this.field.labelMinZoom;

            return typeof fromField === 'number' ? fromField : 12;
        },

        geojsonUrl() {
            if (this.field.geojsonUrl) {
                return this.field.geojsonUrl;
            }

            return `/nova-vendor/feature-collection-map/${this.resourceName}/${this.currentResourceId}`;
        },
    },

    beforeUnmount() {
        this.neighbourLayer = null;
        this.labelLayer = null;
    },

    methods: {
        handleMapReady({ map, features }) {
            if (!map || !Array.isArray(features)) {
                return;
            }

            const neighbours = features.filter((f) => f.get('neighbour') === true);

            if (neighbours.length === 0) {
                return;
            }

            const others = features.filter((f) => f.get('neighbour') !== true);

            this.moveNeighboursToOwnLayer(map, neighbours);
            this.refitOn(map, others);
        },

        moveNeighboursToOwnLayer(map, neighbours) {
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

            if (this.neighbourLayer) {
                map.removeLayer(this.neighbourLayer);
            }

            if (this.labelLayer) {
                map.removeLayer(this.labelLayer);
            }

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
            // Le feature sono **cloni** e non le stesse del layer sotto: con una
            // source condivisa, il renderer con `declutter` e quello senza si
            // contendono la hit detection di `forEachFeatureAtPixel`, ed e' da
            // li' che il componente condiviso ricava il tooltip. I cloni non
            // portano `tooltip` ne' `link`, cosi' la sola feature raggiungibile
            // col mouse resta quella della linea.
            //
            // `declutter` vive qui e non sulle linee perche' e' cio' che fa
            // scansare le etichette fra loro: applicato al layer dei tratti,
            // farebbe sparire i tratti insieme alle etichette senza posto.
            const labelFeatures = neighbours.map((feature) => {
                const clone = feature.clone();

                clone.setProperties({ label: feature.get('label') }, true);

                return clone;
            });

            this.labelLayer = new VectorLayer({
                source: new VectorSource({ features: labelFeatures }),
                declutter: true,
                zIndex: 20,
                style: (feature, resolution) => this.labelStyle(map, feature, resolution),
            });

            map.addLayer(this.neighbourLayer);
            map.addLayer(this.labelLayer);
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

            return new Style({
                text: new Text({
                    text: label,
                    // Lungo il tracciato: al centro della geometria, un
                    // sentiero a U porterebbe l'etichetta fuori dal sentiero.
                    placement: 'line',
                    overflow: false,
                    font: 'bold 13px sans-serif',
                    fill: new Fill({ color: 'rgba(15, 23, 42, 1)' }),
                    // L'alone bianco stacca il numero dal tratto su cui corre.
                    stroke: new Stroke({ color: 'rgba(255, 255, 255, 1)', width: 4 }),
                }),
            });
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
