<template>
    <div ref="keydownRoot" class="feature-collection-map-container" tabindex="-1" @keydown.esc="closePopup">
        <!-- Loading overlay -->
        <div v-if="isLoading" class="loading-overlay">
            <div class="loading-spinner"></div>
            <p class="loading-text">Caricamento mappa...</p>
        </div>

        <div ref="mapContainer" class="map-container" :class="{ 'map-loading': isLoading }"></div>

        <!-- Screenshot button - nascosto quando enableScreenshot è attivo (screenshot automatico) -->

        <!-- Tooltip overlay -->
        <div ref="tooltipElement" class="map-tooltip" v-show="tooltipVisible">
            {{ tooltipText }}
        </div>

        <!-- Default Popup - simple with name and link (only if no custom popup component) -->
        <Teleport to="body">
            <div v-if="showPopup && !popupComponent" class="fixed inset-0 z-[60] flex items-center justify-center p-4"
                role="dialog" aria-modal="true">
                <div
                    class="relative z-20 bg-white dark:bg-gray-800 rounded-lg shadow-xl overflow-hidden w-full max-w-md">
                    <!-- Header -->
                    <div class="bg-primary-500 dark:bg-primary-600 px-6 py-4">
                        <h3 class="text-lg font-semibold text-white">{{ popupTitle }}</h3>
                    </div>

                    <!-- Content -->
                    <div class="px-6 py-4">
                        <p class="text-gray-600 dark:text-gray-300 text-sm">
                            Clicca sul pulsante per visualizzare i dettagli.
                        </p>
                    </div>

                    <!-- Footer with buttons -->
                    <div
                        class="px-6 py-4 bg-gray-100 dark:bg-gray-700 flex justify-end items-center gap-3 border-t border-gray-200 dark:border-gray-600">
                        <button type="button" @click="closePopup"
                            style="background: white; color: #333; border: 1px solid #ccc; padding: 8px 16px; border-radius: 4px; font-weight: bold; cursor: pointer;">
                            Chiudi
                        </button>
                        <a v-if="currentFeatureId" :href="getResourceLink()" target="_blank"
                            style="background: #0ea5e9; color: white; padding: 8px 16px; border-radius: 4px; font-weight: bold; text-decoration: none; cursor: pointer;">
                            Vai alla risorsa
                        </a>
                    </div>
                </div>
            </div>
            <!-- Backdrop -->
            <div v-if="showPopup && !popupComponent" class="fixed inset-0 z-[55] bg-gray-500/75 dark:bg-gray-900/75"
                @click="closePopup">
            </div>
        </Teleport>
    </div>
</template>

<script>
import { ref, nextTick, onMounted, onUnmounted, watch } from 'vue';

// OpenLayers imports
import Map from 'ol/Map';
import View from 'ol/View';
import TileLayer from 'ol/layer/Tile';
import VectorLayer from 'ol/layer/Vector';
import VectorSource from 'ol/source/Vector';
import OSM from 'ol/source/OSM';
import GeoJSON from 'ol/format/GeoJSON';
import Overlay from 'ol/Overlay';
import { Style, Fill, Stroke, Circle as CircleStyle } from 'ol/style';
import { fromLonLat } from 'ol/proj';
import { defaults as defaultControls } from 'ol/control';
import { defaults as defaultInteractions } from 'ol/interaction';

// html2canvas import
import html2canvas from 'html2canvas';

export default {
    name: 'FeatureCollectionMap',

    props: {
        geojsonUrl: {
            type: String,
            required: true
        },
        height: {
            type: Number,
            default: 500
        },
        showZoomControls: {
            type: Boolean,
            default: true
        },
        mouseWheelZoom: {
            type: Boolean,
            default: true
        },
        dragPan: {
            type: Boolean,
            default: true
        },
        padding: {
            type: Number,
            default: 50
        },
        resourceName: {
            type: String,
            default: 'poles'
        },
        popupComponent: {
            type: String,
            default: null
        },
        enableScreenshot: {
            type: Boolean,
            default: false
        },
        resourceName: {
            type: String,
            default: null
        },
        resourceId: {
            type: [String, Number],
            default: null
        },
        /** Callback opzionale per stili aggiuntivi sui punti: (feature, resolution) => Style | Style[] | null. Riusabile per custom (es. icona X su pali esclusi da export). */
        getAdditionalPointStyles: {
            type: Function,
            default: null
        }
    },

    emits: ['feature-click', 'map-ready', 'popup-open', 'popup-close'],

    setup(props, { emit }) {
        const keydownRoot = ref(null);
        const mapContainer = ref(null);
        const map = ref(null);
        const vectorSource = ref(null);
        const geojsonData = ref(null);
        const featuresMap = ref({});
        const isLoading = ref(true);

        // Tooltip state
        const tooltipElement = ref(null);
        const tooltipOverlay = ref(null);
        const tooltipVisible = ref(false);
        const tooltipText = ref('');

        // Popup state
        const showPopup = ref(false);
        const popupTitle = ref('');
        const popupData = ref({});
        const currentFeatureId = ref(null);
        const currentFeatureProperties = ref({});

        // Screenshot state
        const isCapturing = ref(false);
        const screenshotCaptured = ref(false); // Flag per evitare screenshot multipli

        // Get link to Nova resource
        const getResourceLink = () => {
            if (!currentFeatureId.value) return '#';
            return `/resources/${props.resourceName}/${currentFeatureId.value}`;
        };

        // Style function for features (resolution = metri per pixel, usata anche per callback getAdditionalPointStyles)
        const getFeatureStyle = (feature, resolution) => {
            const featureProps = feature.getProperties();
            const geometryType = feature.getGeometry().getType();

            if (geometryType === 'Point' || geometryType === 'MultiPoint') {
                const baseRadius = featureProps.pointRadius || 6;
                const fillColor = featureProps.pointFillColor || 'rgba(255, 0, 0, 0.8)';
                const strokeColor = featureProps.pointStrokeColor || 'rgba(255, 255, 255, 1)';
                const strokeWidth = featureProps.pointStrokeWidth || 2;

                // Controlla se ci sono più colori checkpoint (bordo multicolore)
                const checkpointRouteColors = featureProps.checkpointRouteColors;

                if (checkpointRouteColors && Array.isArray(checkpointRouteColors) && checkpointRouteColors.length > 1) {
                    // Crea un array di Style per cerchi concentrici multicolore
                    const styles = [];

                    // 1. Cerchio principale con fill
                    // Nel caso normale, lo stroke si estende sia dentro che fuori dal raggio
                    // Il raggio visibile del fill è quindi baseRadius - strokeWidth/2
                    // Per mantenere la stessa dimensione visibile, riduciamo il raggio qui
                    const mainRadius = baseRadius - (strokeWidth / 2);

                    styles.push(new Style({
                        image: new CircleStyle({
                            radius: Math.max(mainRadius, 2), // Minimo 2px per evitare cerchi troppo piccoli
                            fill: new Fill({
                                color: fillColor
                            }),
                            // Nessun stroke sul cerchio principale
                        })
                    }));

                    // 2. Cerchi concentrici per ogni colore checkpoint
                    // Ogni cerchio ha un raggio leggermente più grande del precedente
                    // e uno spessore di 2-3 pixel
                    const colors = checkpointRouteColors;
                    const segmentWidth = 2.5; // Spessore di ogni segmento colorato

                    colors.forEach((color, index) => {
                        // Il primo cerchio concentrico deve iniziare esattamente dal bordo del cerchio principale
                        // Ogni stroke si estende sia dentro che fuori dal raggio di segmentWidth/2
                        // Quindi il raggio del cerchio per lo stroke è: mainRadius + (index * segmentWidth) + (segmentWidth / 2)
                        const radius = mainRadius + (index * segmentWidth) + (segmentWidth / 2);

                        styles.push(new Style({
                            image: new CircleStyle({
                                radius: radius,
                                fill: new Fill({
                                    color: 'transparent' // Trasparente, solo il bordo è visibile
                                }),
                                stroke: new Stroke({
                                    color: color,
                                    width: segmentWidth
                                })
                            })
                        }));
                    });

                    // Stili aggiuntivi opzionali (es. icona X per pali esclusi da export in SignageMap)
                    if (props.getAdditionalPointStyles) {
                        const extra = props.getAdditionalPointStyles(feature, resolution);
                        if (extra) {
                            const arr = Array.isArray(extra) ? extra : [extra];
                            styles.push(...arr);
                        }
                    }
                    return styles;
                } else {
                    // Stile normale con un solo colore
                    let styles = [new Style({
                        image: new CircleStyle({
                            radius: baseRadius,
                            fill: new Fill({
                                color: fillColor
                            }),
                            stroke: new Stroke({
                                color: strokeColor,
                                width: strokeWidth
                            })
                        })
                    })];
                    if (props.getAdditionalPointStyles) {
                        const extra = props.getAdditionalPointStyles(feature, resolution);
                        if (extra) {
                            const arr = Array.isArray(extra) ? extra : [extra];
                            styles = styles.concat(arr);
                        }
                    }
                    return styles.length === 1 ? styles[0] : styles;
                }
            }

            return new Style({
                stroke: new Stroke({
                    color: featureProps.strokeColor || 'rgba(0, 0, 255, 1)',
                    width: featureProps.strokeWidth || 3
                }),
                fill: new Fill({
                    color: featureProps.fillColor || 'rgba(0, 0, 255, 0.3)'
                })
            });
        };

        // Load GeoJSON data
        const loadGeoJSON = async () => {
            console.log('Loading GeoJSON from:', props.geojsonUrl);
            isLoading.value = true;
            try {
                const response = await fetch(props.geojsonUrl);

                if (!response.ok) {
                    console.error('GeoJSON fetch failed:', response.status, response.statusText);
                    isLoading.value = false;
                    return;
                }

                const data = await response.json();
                console.log('GeoJSON loaded:', data);
                geojsonData.value = data;

                if (data.features) {
                    data.features.forEach(feature => {
                        if (feature.properties && feature.properties.id) {
                            featuresMap.value[String(feature.properties.id)] = feature;
                        }
                    });
                }

                const format = new GeoJSON();
                const features = format.readFeatures(data, {
                    featureProjection: 'EPSG:3857'
                });

                vectorSource.value.clear();
                vectorSource.value.addFeatures(features);

                if (features.length > 0) {
                    // Trova tutte le LineString nella FeatureCollection (escludi poligoni e punti)
                    const lineStringFeatures = features.filter(feature => {
                        const geometry = feature.getGeometry();
                        if (!geometry) {
                            return false;
                        }
                        const geometryType = geometry.getType();
                        // Include solo LineString e MultiLineString
                        return geometryType === 'LineString' || geometryType === 'MultiLineString';
                    });

                    // Se ci sono LineString, usa solo quelle per il calcolo dell'extent
                    // Altrimenti usa tutte le feature come fallback
                    const featuresForExtent = lineStringFeatures.length > 0 ? lineStringFeatures : features;

                    // Crea una source temporanea per calcolare l'extent solo delle LineString
                    const tempSource = new VectorSource();
                    tempSource.addFeatures(featuresForExtent);
                    const extent = tempSource.getExtent();

                    const view = map.value.getView();

                    // Calcola manualmente centro e zoom per evitare qualsiasi animazione
                    const size = map.value.getSize();
                    if (size && extent && extent[0] !== Infinity) {
                        // Calcola il centro dell'extent
                        const center = [
                            (extent[0] + extent[2]) / 2,
                            (extent[1] + extent[3]) / 2
                        ];

                        // Calcola la risoluzione necessaria per adattare l'extent con padding
                        const width = extent[2] - extent[0];
                        const height = extent[3] - extent[1];
                        const aspectRatio = width / height;
                        const mapAspectRatio = size[0] / size[1];

                        let resolution;
                        if (aspectRatio > mapAspectRatio) {
                            // L'extent è più largo, usa la larghezza
                            resolution = width / (size[0] - props.padding * 2);
                        } else {
                            // L'extent è più alto, usa l'altezza
                            resolution = height / (size[1] - props.padding * 2);
                        }

                        // Calcola lo zoom dalla risoluzione usando la formula di OpenLayers
                        const maxResolution = view.getMaxResolution();
                        const zoom = Math.log2(maxResolution / resolution);
                        const maxZoom = Math.min(view.getMaxZoom() || 17, 17);

                        // Imposta direttamente centro e zoom senza animazione
                        view.setCenter(center);
                        view.setZoom(Math.min(Math.max(zoom, 0), maxZoom));
                    }
                }

                // Cattura screenshot automaticamente solo quando la mappa ha finito di renderizzare
                if (props.enableScreenshot && !screenshotCaptured.value) {
                    // Con duration: 0, possiamo catturare immediatamente dopo il rendercomplete
                    map.value.once('rendercomplete', () => {
                        captureScreenshot();
                    });
                }

                emit('map-ready', { map: map.value, features, geojson: data, featuresMap: featuresMap.value });
                isLoading.value = false;
            } catch (error) {
                console.error('Error loading GeoJSON:', error);
                isLoading.value = false;
            }
        };

        // Handle feature click - can be overridden in child components
        const handleFeatureClick = (feature, featureProps) => {
            const clickAction = featureProps.clickAction || (featureProps.link ? 'link' : 'none');

            switch (clickAction) {
                case 'popup':
                    openPopup(feature, featureProps);
                    break;

                case 'link':
                    if (featureProps.link) {
                        window.open(featureProps.link, '_blank');
                    }
                    emit('feature-click', { feature, properties: featureProps, action: 'link' });
                    break;

                default:
                    emit('feature-click', { feature, properties: featureProps, action: 'none' });
            }
        };

        // Open popup - can be overridden in child components
        const openPopup = (feature, featureProps) => {
            currentFeatureId.value = featureProps.id;
            currentFeatureProperties.value = featureProps;
            popupTitle.value = featureProps.name || featureProps.tooltip || `#${featureProps.id}`;
            popupData.value = featureProps.dem || {};

            showPopup.value = true;

            emit('popup-open', {
                feature,
                properties: featureProps,
                id: featureProps.id,
                dem: featureProps.dem,
                featuresMap: featuresMap.value
            });

            emit('feature-click', { feature, properties: featureProps, action: 'popup' });

            nextTick(() => keydownRoot.value?.focus());
        };

        // Handle map click
        const handleClick = (event) => {
            const pixel = event.pixel;
            const features = map.value.getFeaturesAtPixel(pixel);

            if (features && features.length > 0) {
                const feature = features[0];
                const featureProps = feature.getProperties();
                handleFeatureClick(feature, featureProps);
            }
        };

        // Handle pointer move for cursor change and tooltip
        const handlePointerMove = (event) => {
            const pixel = event.pixel;
            const feature = map.value.forEachFeatureAtPixel(pixel, (f) => f);

            if (feature) {
                const featureProps = feature.getProperties();
                
                // Mostra il cursore pointer solo se c'è un link o un'azione di click
                if (featureProps.link || featureProps.clickAction === 'popup') {
                    map.value.getTargetElement().style.cursor = 'pointer';
                } else {
                    map.value.getTargetElement().style.cursor = 'default';
                }
                
                // Mostra sempre il tooltip se presente, indipendentemente dal link
                const tooltip = featureProps.tooltip || featureProps.name;

                if (tooltip && tooltipOverlay.value) {
                    tooltipText.value = tooltip;
                    tooltipVisible.value = true;
                    tooltipOverlay.value.setPosition(event.coordinate);
                } else {
                    tooltipVisible.value = false;
                }
            } else {
                map.value.getTargetElement().style.cursor = '';
                tooltipVisible.value = false;
            }
        };

        // Close popup
        const closePopup = () => {
            showPopup.value = false;
            emit('popup-close', { id: currentFeatureId.value });
            currentFeatureId.value = null;
            currentFeatureProperties.value = {};
            popupData.value = {};
        };

        // Capture screenshot of the map
        const captureScreenshot = async () => {
            if (!map.value || !mapContainer.value || isCapturing.value || screenshotCaptured.value) {
                return;
            }

            // Verifica che resourceName e resourceId siano disponibili
            if (!props.resourceName || !props.resourceId) {
                console.warn('Impossibile salvare lo screenshot: informazioni risorsa non disponibili.');
                return;
            }

            isCapturing.value = true;
            screenshotCaptured.value = true; // Evita screenshot multipli

            try {
                // Nascondi temporaneamente il tooltip se visibile
                const tooltipWasVisible = tooltipVisible.value;
                tooltipVisible.value = false;

                // Cattura lo screenshot del container della mappa
                const canvas = await html2canvas(mapContainer.value, {
                    useCORS: true,
                    logging: false,
                    backgroundColor: '#ffffff',
                    scale: 1,
                    width: mapContainer.value.offsetWidth,
                    height: mapContainer.value.offsetHeight
                });

                // Ripristina il tooltip se era visibile
                tooltipVisible.value = tooltipWasVisible;

                // Converti il canvas in base64
                const imageData = canvas.toDataURL('image/png');

                // Invia l'immagine al backend
                const response = await fetch(`/nova-vendor/feature-collection-map/screenshot/${props.resourceName}/${props.resourceId}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    },
                    body: JSON.stringify({
                        image: imageData
                    })
                });

                if (!response.ok) {
                    const errorData = await response.json().catch(() => ({ message: 'Errore sconosciuto' }));
                    throw new Error(errorData.message || `Errore HTTP: ${response.status}`);
                }

                const result = await response.json();

                // Mostra messaggio di successo solo se non è automatico (per non disturbare l'utente)
                // Nova.success('Screenshot salvato con successo!');
                console.log('Screenshot salvato automaticamente:', result);

            } catch (error) {
                console.error('Errore durante la cattura dello screenshot:', error);
                // Non mostrare errori all'utente se è automatico, solo in console
                // Nova.error('Errore durante il salvataggio dello screenshot: ' + (error.message || 'Errore sconosciuto'));
                screenshotCaptured.value = false; // Permetti di riprovare in caso di errore
            } finally {
                isCapturing.value = false;
            }
        };

        // Initialize map
        onMounted(() => {
            vectorSource.value = new VectorSource();

            const vectorLayer = new VectorLayer({
                source: vectorSource.value,
                style: getFeatureStyle
            });

            map.value = new Map({
                target: mapContainer.value,
                layers: [
                    new TileLayer({
                        source: new OSM()
                    }),
                    vectorLayer
                ],
                view: new View({
                    center: fromLonLat([12.5, 42.5]),
                    zoom: 6
                }),
                controls: defaultControls({
                    zoom: props.showZoomControls
                }),
                interactions: defaultInteractions({
                    mouseWheelZoom: props.mouseWheelZoom,
                    dragPan: props.dragPan
                })
            });

            // Create tooltip overlay
            tooltipOverlay.value = new Overlay({
                element: tooltipElement.value,
                positioning: 'bottom-center',
                offset: [0, -10],
                stopEvent: false
            });
            map.value.addOverlay(tooltipOverlay.value);

            map.value.on('click', handleClick);
            map.value.on('pointermove', handlePointerMove);

            loadGeoJSON();
        });

        // Cleanup
        onUnmounted(() => {
            if (map.value) {
                map.value.setTarget(null);
                map.value = null;
            }
        });

        // Watch for geojsonUrl changes - use immediate and deep watching
        watch(() => props.geojsonUrl, (newUrl, oldUrl) => {
            if (newUrl && newUrl !== oldUrl) {
                console.log('GeoJSON URL changed, reloading map:', { oldUrl, newUrl });
                // Reset screenshot flag when URL changes
                screenshotCaptured.value = false;
                loadGeoJSON();
            }
        }, { immediate: false });

        return {
            // Props (for template)
            popupComponent: props.popupComponent,
            enableScreenshot: props.enableScreenshot,
            // Refs
            keydownRoot,
            mapContainer,
            tooltipElement,
            tooltipVisible,
            tooltipText,
            // Loading state
            isLoading,
            // Popup state
            showPopup,
            popupTitle,
            popupData,
            currentFeatureId,
            currentFeatureProperties,
            // Screenshot state
            isCapturing,
            screenshotCaptured,
            // Methods
            closePopup,
            getResourceLink,
            captureScreenshot,
            // Exposed for child components
            map,
            vectorSource,
            featuresMap,
            openPopup,
            loadGeoJSON
        };
    }
};
</script>

<style scoped>
.feature-collection-map-container {
    position: relative;
    width: 100%;
}

.map-container {
    width: 100%;
    height: v-bind('height + "px"');
    border-radius: 4px;
    overflow: hidden;
}

.map-tooltip {
    position: absolute;
    background-color: rgba(0, 0, 0, 0.8);
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    white-space: nowrap;
    pointer-events: none;
    z-index: 100;
}

.screenshot-button {
    position: absolute;
    top: 10px;
    right: 10px;
    z-index: 1000;
    background-color: #0ea5e9;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 4px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
    transition: background-color 0.2s, opacity 0.2s;
}

.screenshot-button:hover:not(:disabled) {
    background-color: #0284c7;
}

.screenshot-button:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.loading-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: rgba(255, 255, 255, 0.9);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    z-index: 1000;
    border-radius: 4px;
}

.loading-spinner {
    width: 40px;
    height: 40px;
    border: 4px solid #f3f4f6;
    border-top: 4px solid #0ea5e9;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

.loading-text {
    margin-top: 12px;
    color: #6b7280;
    font-size: 14px;
    font-weight: 500;
}

@keyframes spin {
    0% {
        transform: rotate(0deg);
    }

    100% {
        transform: rotate(360deg);
    }
}

.map-loading {
    opacity: 0.5;
    pointer-events: none;
}
</style>
