<template>
    <PanelItem :index="index" :field="field">
        <template #value>
            <FeatureCollectionMap :geojson-url="geojsonUrl" :height="field.height || 500"
                :show-zoom-controls="field.showZoomControls !== false"
                :mouse-wheel-zoom="field.mouseWheelZoom !== false" :drag-pan="field.dragPan !== false"
                :popup-component="field.popupComponent" @feature-click="handleFeatureClick" @map-ready="handleMapReady"
                @popup-open="handlePopupOpen" @popup-close="handlePopupClose" />

            <!-- Custom popup component if specified -->
            <component v-if="field.popupComponent" :is="field.popupComponent" />
        </template>
    </PanelItem>
</template>

<script>
import FeatureCollectionMap from './FeatureCollectionMap.vue';

export default {
    name: 'DetailFeatureCollectionMap',

    components: {
        FeatureCollectionMap
    },

    props: ['index', 'resource', 'resourceName', 'resourceId', 'field'],

    mounted() {
        console.log('DetailField mounted, popupComponent:', this.field.popupComponent);
    },

    computed: {
        geojsonUrl() {
            // If custom URL is provided, use it
            if (this.field.geojsonUrl) {
                return this.field.geojsonUrl;
            }

            // Use resourceName as-is (Str::studly will handle the conversion)
            const modelName = this.resourceName;

            // Get the resource ID from the resource object or resourceId prop
            const id = this.resourceId || (this.resource && this.resource.id && this.resource.id.value);

            const baseUrl = `/nova-vendor/feature-collection-map/${modelName}/${id}`;

            console.log('FeatureCollectionMap URL:', baseUrl);

            // Add dem_enrichment parameter if enabled
            if (this.field.demEnrichment) {
                return `${baseUrl}?dem_enrichment=1`;
            }

            return baseUrl;
        }
    },

    methods: {
        handleFeatureClick(event) {
            console.log('Feature clicked:', event);
        },
        handleMapReady(event) {
            console.log('Map ready:', event);
            // Emit global event for custom popup components
            Nova.$emit('feature-collection-map:ready', {
                ...event,
                popupComponent: this.field.popupComponent
            });
        },
        handlePopupOpen(event) {
            console.log('Popup opened:', event);
            // Emit global event for custom popup components
            Nova.$emit('feature-collection-map:popup-open', {
                ...event,
                popupComponent: this.field.popupComponent
            });
        },
        handlePopupClose(event) {
            console.log('Popup closed:', event);
            // Emit global event for custom popup components
            Nova.$emit('feature-collection-map:popup-close', event);
        }
    }
};
</script>
