<template>
    <Teleport to="body">
        <div v-if="showPopup" class="fixed inset-0 z-[60] px-3 md:px-0 py-3 md:py-6 overflow-x-hidden overflow-y-auto" role="dialog" aria-modal="true">
            <div class="relative mx-auto z-20 max-w-5xl">
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg overflow-hidden flex flex-col max-h-[90vh]">
                    <!-- Header -->
                    <div class="bg-primary-500 dark:bg-primary-600 px-6 py-4 flex justify-between items-center flex-shrink-0">
                        <div class="flex justify-between items-center w-full">
                            <span class="text-lg font-semibold text-white">{{ popupTitle }}</span>
                            <span v-if="poleElevation" class="text-sm font-normal text-white opacity-75 ml-4">
                                {{ poleElevation }} m s.l.m.
                            </span>
                        </div>
                    </div>

                    <!-- Content -->
                    <div class="flex-1 overflow-y-auto p-6">
                        <template v-if="hasMatrixData">
                            <div v-for="(trackData, trackId) in popupData.matrix_row" :key="trackId" class="mb-6 last:mb-0">
                                <div class="flex items-center justify-between mb-3">
                                    <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                                        Distanze verso altri pali (Percorso #{{ trackId }})
                                    </h4>
                                    <span class="text-xs text-gray-400">
                                        {{ getSelectedCount(trackId) }}/3 mete selezionate
                                    </span>
                                </div>
                                <div class="overflow-x-auto">
                                    <table class="w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <thead class="bg-gray-50 dark:bg-gray-900">
                                            <tr>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider w-12">Meta</th>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Palo</th>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Distanza</th>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Tempo hiking</th>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Tempo bike</th>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Salita</th>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Discesa</th>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Quota da</th>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Quota a</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                            <tr v-for="(pointData, pointId) in sortedTrackData(trackData)" :key="pointId"
                                                class="hover:bg-gray-50 dark:hover:bg-gray-700"
                                                :class="{ 'bg-blue-50 dark:bg-blue-900/20': isSelected(trackId, pointId) }">
                                                <td class="px-4 py-3 whitespace-nowrap">
                                                    <div class="flex items-center gap-2">
                                                        <input 
                                                            type="checkbox"
                                                            :checked="isSelected(trackId, pointId)"
                                                            :disabled="!isSelected(trackId, pointId) && getSelectedCount(trackId) >= 3"
                                                            @change="toggleSelection(trackId, pointId, pointData.distance)"
                                                            class="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 rounded cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                                                        />
                                                        <span v-if="getMetaRole(trackId, pointId)" 
                                                            class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium"
                                                            :class="getMetaRoleClass(trackId, pointId)">
                                                            {{ getMetaRole(trackId, pointId) }}
                                                        </span>
                                                    </div>
                                                </td>
                                                <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">{{ getTargetName(pointId) }}</td>
                                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">{{ formatDistance(pointData.distance) }}</td>
                                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">{{ formatTime(pointData.time_hiking) }}</td>
                                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">{{ formatTime(pointData.time_bike) }}</td>
                                                <td class="px-4 py-3 whitespace-nowrap text-sm font-semibold text-green-600">+{{ pointData.ascent || 0 }}m</td>
                                                <td class="px-4 py-3 whitespace-nowrap text-sm font-semibold text-red-600">-{{ pointData.descent || 0 }}m</td>
                                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">{{ pointData.elevation_from || '-' }}m</td>
                                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">{{ pointData.elevation_to || '-' }}m</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </template>

                        <div v-if="!poleElevation && !hasMatrixData" class="text-center py-8 text-gray-500 dark:text-gray-400 italic">
                            Nessun dato DEM disponibile per questo palo.
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="bg-gray-50 dark:bg-gray-800 px-6 py-4 flex justify-between items-center border-t border-gray-200 dark:border-gray-700 flex-shrink-0">
                        <div v-if="hasChanges" class="text-sm text-yellow-600 dark:text-yellow-400">
                            Modifiche non salvate
                        </div>
                        <div v-else></div>
                        <div class="flex items-center gap-3">
                            <button type="button" @click="closePopup"
                                class="shadow relative bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 cursor-pointer rounded text-sm font-bold focus:outline-none focus:ring ring-gray-200 dark:ring-gray-600 inline-flex items-center justify-center h-9 px-3">
                                Annulla
                            </button>
                            <button 
                                type="button" 
                                @click="saveDestinations"
                                :disabled="saving || !hasChanges"
                                class="shadow relative bg-primary-500 hover:bg-primary-400 text-white dark:text-gray-900 cursor-pointer rounded text-sm font-bold focus:outline-none focus:ring ring-primary-200 dark:ring-gray-600 inline-flex items-center justify-center h-9 px-3 disabled:opacity-50 disabled:cursor-not-allowed">
                                <span v-if="saving">Salvataggio...</span>
                                <span v-else>Salva Mete</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Backdrop -->
        <div v-if="showPopup" class="fixed inset-0 z-[55] bg-gray-500/75 dark:bg-gray-900/75" @click="closePopup"></div>
    </Teleport>
</template>

<script>
import { ref, computed, reactive, onMounted, onUnmounted } from 'vue';

export default {
    name: 'SignagePopup',

    setup() {
        const showPopup = ref(false);
        const popupTitle = ref('');
        const popupData = ref({});
        const currentPoleId = ref(null);
        const featuresMap = ref({});
        const saving = ref(false);

        // Selection state - { trackId: { pointId: distance, ... }, ... }
        const selections = reactive({});
        const originalSelections = ref({});

        const hasMatrixData = computed(() => {
            return popupData.value.matrix_row && Object.keys(popupData.value.matrix_row).length > 0;
        });

        const hasChanges = computed(() => {
            return JSON.stringify(selections) !== JSON.stringify(originalSelections.value);
        });

        const poleElevation = computed(() => {
            if (popupData.value.elevation !== undefined && popupData.value.elevation !== null) {
                return popupData.value.elevation;
            }
            if (popupData.value.matrix_row) {
                const tracks = Object.values(popupData.value.matrix_row);
                if (tracks.length > 0) {
                    const points = Object.values(tracks[0]);
                    if (points.length > 0 && points[0].elevation_from !== undefined) {
                        return points[0].elevation_from;
                    }
                }
            }
            return null;
        });

        const sortedTrackData = (trackData) => {
            const entries = Object.entries(trackData);
            entries.sort((a, b) => (a[1].distance || 0) - (b[1].distance || 0));
            return Object.fromEntries(entries);
        };

        const isSelected = (trackId, pointId) => {
            return selections[trackId] && selections[trackId][pointId] !== undefined;
        };

        const getSelectedCount = (trackId) => {
            if (!selections[trackId]) return 0;
            return Object.keys(selections[trackId]).length;
        };

        const toggleSelection = (trackId, pointId, distance) => {
            if (!selections[trackId]) {
                selections[trackId] = {};
            }

            if (selections[trackId][pointId] !== undefined) {
                delete selections[trackId][pointId];
                if (Object.keys(selections[trackId]).length === 0) {
                    delete selections[trackId];
                }
            } else {
                if (getSelectedCount(trackId) < 3) {
                    selections[trackId][pointId] = distance;
                }
            }
        };

        const getMetaRole = (trackId, pointId) => {
            if (!isSelected(trackId, pointId)) return null;

            const trackSelections = selections[trackId];
            if (!trackSelections) return null;

            const sorted = Object.entries(trackSelections)
                .sort((a, b) => a[1] - b[1])
                .map(([id]) => id);

            const index = sorted.indexOf(String(pointId));
            const total = sorted.length;

            if (total === 1) {
                return 'Itinerario';
            } else if (total === 2) {
                return index === 0 ? 'Ravvicinata' : 'Itinerario';
            } else if (total === 3) {
                if (index === 0) return 'Ravvicinata';
                if (index === 1) return 'Intermedia';
                return 'Itinerario';
            }
            return null;
        };

        const getMetaRoleClass = (trackId, pointId) => {
            const role = getMetaRole(trackId, pointId);
            switch (role) {
                case 'Ravvicinata':
                    return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
                case 'Intermedia':
                    return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200';
                case 'Itinerario':
                    return 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200';
                default:
                    return '';
            }
        };

        const getTargetName = (targetId) => {
            const feature = featuresMap.value[String(targetId)];
            if (feature && feature.properties) {
                return feature.properties.name || feature.properties.tooltip || `#${targetId}`;
            }
            return `#${targetId}`;
        };

        const formatDistance = (meters) => {
            if (!meters) return '-';
            if (meters < 1000) {
                return `${meters}m`;
            }
            return `${(meters / 1000).toFixed(2)} km`;
        };

        const formatTime = (seconds) => {
            if (!seconds) return '-';
            const hours = Math.floor(seconds / 3600);
            const minutes = Math.floor((seconds % 3600) / 60);
            if (hours > 0) {
                return `${hours}h ${minutes}min`;
            }
            return `${minutes} min`;
        };

        const initializeSelections = (properties) => {
            Object.keys(selections).forEach(key => delete selections[key]);

            if (properties.destinations) {
                for (const [trackId, poleIds] of Object.entries(properties.destinations)) {
                    if (Array.isArray(poleIds)) {
                        selections[trackId] = {};
                        const trackData = popupData.value.matrix_row?.[trackId];
                        poleIds.forEach(poleId => {
                            const distance = trackData?.[poleId]?.distance || 0;
                            selections[trackId][poleId] = distance;
                        });
                    }
                }
            }

            originalSelections.value = JSON.parse(JSON.stringify(selections));
        };

        const saveDestinations = async () => {
            if (!currentPoleId.value) return;

            saving.value = true;

            try {
                const destinations = {};
                for (const [trackId, points] of Object.entries(selections)) {
                    destinations[trackId] = Object.entries(points)
                        .sort((a, b) => a[1] - b[1])
                        .map(([id]) => parseInt(id));
                }

                const response = await fetch(`/nova-vendor/signage-popup/poles/${currentPoleId.value}/destinations`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    body: JSON.stringify({ destinations })
                });

                if (!response.ok) {
                    throw new Error('Failed to save destinations');
                }

                originalSelections.value = JSON.parse(JSON.stringify(selections));

                if (window.Nova) {
                    Nova.success('Mete salvate con successo!');
                }
            } catch (error) {
                console.error('Error saving destinations:', error);
                if (window.Nova) {
                    Nova.error('Errore nel salvataggio delle mete');
                }
            } finally {
                saving.value = false;
            }
        };

        const closePopup = () => {
            showPopup.value = false;
            currentPoleId.value = null;
            popupData.value = {};
            featuresMap.value = {};
            Object.keys(selections).forEach(key => delete selections[key]);
            originalSelections.value = {};
        };

        const handlePopupOpen = (event) => {
            console.log('SignagePopup: Received popup-open event', event);
            if (event.popupComponent !== 'signage-popup') {
                console.log('SignagePopup: Not for us, ignoring');
                return;
            }

            currentPoleId.value = event.id;
            popupTitle.value = event.properties.name || event.properties.tooltip || `Palo ${event.id}`;
            popupData.value = event.dem || {};
            featuresMap.value = event.featuresMap || {};

            initializeSelections(event.properties);

            showPopup.value = true;
            console.log('SignagePopup: Popup opened');
        };

        const handlePopupClose = () => {
            closePopup();
        };

        // Handle ESC key
        const handleKeydown = (event) => {
            if (event.key === 'Escape' && showPopup.value) {
                closePopup();
            }
        };

        onMounted(() => {
            console.log('SignagePopup: Component mounted, registering event listeners');
            Nova.$on('feature-collection-map:popup-open', handlePopupOpen);
            Nova.$on('feature-collection-map:popup-close', handlePopupClose);
            document.addEventListener('keydown', handleKeydown);
        });

        onUnmounted(() => {
            console.log('SignagePopup: Component unmounting, removing event listeners');
            Nova.$off('feature-collection-map:popup-open', handlePopupOpen);
            Nova.$off('feature-collection-map:popup-close', handlePopupClose);
            document.removeEventListener('keydown', handleKeydown);
        });

        return {
            showPopup,
            popupTitle,
            popupData,
            hasMatrixData,
            poleElevation,
            hasChanges,
            saving,
            selections,
            sortedTrackData,
            isSelected,
            getSelectedCount,
            toggleSelection,
            getMetaRole,
            getMetaRoleClass,
            getTargetName,
            formatDistance,
            formatTime,
            saveDestinations,
            closePopup
        };
    }
};
</script>


