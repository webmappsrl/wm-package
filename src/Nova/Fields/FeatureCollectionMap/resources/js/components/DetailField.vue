<template>
    <PanelItem :index="index" :field="field">
        <template #value>
            <FeatureCollectionMap ref="mapComponent" :geojson-url="geojsonUrl" :height="field.height || 500"
                :show-zoom-controls="field.showZoomControls !== false"
                :mouse-wheel-zoom="field.mouseWheelZoom !== false" :drag-pan="field.dragPan !== false"
                :popup-component="field.popupComponent" :enable-screenshot="field.enableScreenshot === true"
                :enable-slope-chart="field.enableSlopeChart === true"
                :resource-name="resourceName"
                :resource-id="resourceId || (resource && resource.id && resource.id.value)"
                @feature-click="handleFeatureClick" @map-ready="handleMapReady" @popup-open="handlePopupOpen"
                @popup-close="handlePopupClose" />

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

    data() {
        return {
            mapRefreshKey: Date.now(),
            lastUpdatedAt: null,
            pollInterval: null,
            lastGeojsonHash: null,
            clickHandler: null,
            inertiaStartHandler: null
        };
    },

    mounted() {
        console.log('DetailField mounted, popupComponent:', this.field.popupComponent);

        // Intercept navigation to force reload when navigating to index
        const currentResourceId = this.resourceId || (this.resource && this.resource.id && this.resource.id.value);
        if (currentResourceId) {
            this.setupNavigationInterceptor();
        }

        // Get initial updated_at timestamp if available
        this.updateLastUpdatedAt();

        // Listen for Nova relationship update events to refresh the map
        // Nova emits 'relationship-updated' when BelongsToMany relationships change
        Nova.$on('relationship-updated', this.handleRelationshipUpdate);

        // Also listen for resource refresh events
        Nova.$on('resource-refresh', this.handleResourceRefresh);

        // Listen for custom event when UGC relationships change
        const currentResourceName = this.resourceName;
        const eventName = `trail-survey-ugc-updated-${currentResourceName}-${currentResourceId}`;
        Nova.$on(eventName, this.handleUgcUpdate);

        // Store event name for cleanup
        this.ugcUpdateEventName = eventName;

        // Poll for changes by checking GeoJSON hash (more reliable than timestamp)
        // Check every 2 seconds
        this.pollInterval = setInterval(() => {
            this.checkForGeojsonUpdates();
        }, 2000);
    },

    beforeUnmount() {
        // Remove navigation interceptor
        this.removeNavigationInterceptor();

        // Remove event listeners
        Nova.$off('relationship-updated', this.handleRelationshipUpdate);
        Nova.$off('resource-refresh', this.handleResourceRefresh);

        // Remove custom UGC update event listener
        if (this.ugcUpdateEventName) {
            Nova.$off(this.ugcUpdateEventName, this.handleUgcUpdate);
        }

        // Clear polling interval
        if (this.pollInterval) {
            clearInterval(this.pollInterval);
        }
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

            // Add timestamp parameter to force refresh when relationships change
            const params = new URLSearchParams();
            if (this.field.demEnrichment) {
                params.append('dem_enrichment', '1');
            }
            // Add refresh key to force reload when relationships change
            params.append('_t', this.mapRefreshKey);

            const url = params.toString() ? `${baseUrl}?${params.toString()}` : baseUrl;
            console.log('FeatureCollectionMap URL:', url);

            return url;
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
        },
        handleRelationshipUpdate(data) {
            // Check if this update is for the current resource
            const currentResourceId = this.resourceId || (this.resource && this.resource.id && this.resource.id.value);
            const currentResourceName = this.resourceName;

            if (data && data.resourceName === currentResourceName && data.resourceId === currentResourceId) {
                console.log('Relationship updated, refreshing map:', data);
                this.forceMapReload();
            }
        },
        handleResourceRefresh() {
            // When resource is refreshed, update the map
            console.log('Resource refreshed, updating map');
            this.forceMapReload();
            this.updateLastUpdatedAt();
        },
        handleUgcUpdate() {
            // Handle custom UGC update event
            console.log('UGC relationship updated, refreshing map');
            this.forceMapReload();
        },
        forceMapReload() {
            // Update refresh key to force URL change
            this.mapRefreshKey = Date.now();

            // Also directly call reload on map component if available
            if (this.$refs.mapComponent && this.$refs.mapComponent.loadGeoJSON) {
                console.log('Forcing map reload via component method');
                this.$refs.mapComponent.loadGeoJSON();
            }
        },
        updateLastUpdatedAt() {
            // Try to get updated_at from resource
            if (this.resource && this.resource.updated_at) {
                const updatedAt = this.resource.updated_at.value || this.resource.updated_at;
                this.lastUpdatedAt = updatedAt;
            }
        },
        async checkForGeojsonUpdates() {
            // Check if GeoJSON has changed by fetching and comparing hash
            try {
                const modelName = this.resourceName;
                const id = this.resourceId || (this.resource && this.resource.id && this.resource.id.value);
                if (!id) return;

                const url = `/nova-vendor/feature-collection-map/${modelName}/${id}`;

                const response = await fetch(url);
                if (response.ok) {
                    const data = await response.json();
                    // Create a simple hash from feature count and IDs
                    const featureIds = (data.features || [])
                        .map(f => f.properties?.model_id || f.properties?.id || f.id)
                        .filter(id => id != null)
                        .sort()
                        .join(',');
                    const hash = `${data.features?.length || 0}-${featureIds}`;

                    if (this.lastGeojsonHash && this.lastGeojsonHash !== hash) {
                        console.log('GeoJSON changed, refreshing map', {
                            oldHash: this.lastGeojsonHash,
                            newHash: hash,
                            featureCount: data.features?.length || 0
                        });
                        this.lastGeojsonHash = hash;
                        this.forceMapReload();
                    } else if (!this.lastGeojsonHash) {
                        // Initialize hash on first check
                        this.lastGeojsonHash = hash;
                        console.log('Initialized GeoJSON hash:', hash);
                    }
                }
            } catch (error) {
                // Silently fail - polling is just a fallback
                console.debug('GeoJSON hash check failed:', error);
            }
        },
        setupNavigationInterceptor() {
            // Intercept clicks on links that navigate to index page
            const handleClick = (e) => {
                const link = e.target.closest('a[href]');
                if (!link) return;
                
                const href = link.getAttribute('href') || link.href;
                if (!href) return;
                
                try {
                    const currentPath = new URL(window.location.href).pathname;
                    const newPath = new URL(href, window.location.origin).pathname;
                    
                    // Check if we're on a detail page and clicking a link to the index page
                    const currentResourceId = this.resourceId || (this.resource && this.resource.id && this.resource.id.value);
                    if (currentResourceId && currentPath.includes(`/${this.resourceName}/`)) {
                        const indexPattern = new RegExp(`^/resources/${this.resourceName}/?$`);
                        if (indexPattern.test(newPath)) {
                            e.preventDefault();
                            e.stopPropagation();
                            e.stopImmediatePropagation();
                            window.location.href = href;
                            return false;
                        }
                    }
                } catch (err) {
                    // Invalid URL, ignore
                }
            };
            
            // Use capture phase to intercept before other handlers
            document.addEventListener('click', handleClick, true);
            this.clickHandler = handleClick;
            
            // Also intercept Inertia navigation events
            const handleInertiaStart = (event) => {
                const url = event.detail?.url || event.url || window.location.href;
                
                try {
                    const currentPath = new URL(window.location.href).pathname;
                    const newPath = new URL(url, window.location.origin).pathname;
                    
                    const currentResourceId = this.resourceId || (this.resource && this.resource.id && this.resource.id.value);
                    if (currentResourceId && currentPath.includes(`/${this.resourceName}/`)) {
                        const indexPattern = new RegExp(`^/resources/${this.resourceName}/?$`);
                        if (indexPattern.test(newPath)) {
                            if (event.preventDefault) event.preventDefault();
                            if (event.stopPropagation) event.stopPropagation();
                            window.location.href = url;
                            return false;
                        }
                    }
                } catch (err) {
                    // Invalid URL, ignore
                }
            };
            
            // Listen to Inertia events
            document.addEventListener('inertia:start', handleInertiaStart);
            this.inertiaStartHandler = handleInertiaStart;
        },
        removeNavigationInterceptor() {
            if (this.clickHandler) {
                document.removeEventListener('click', this.clickHandler, true);
                this.clickHandler = null;
            }
            if (this.inertiaStartHandler) {
                document.removeEventListener('inertia:start', this.inertiaStartHandler);
                this.inertiaStartHandler = null;
            }
        }
    }
};
</script>
