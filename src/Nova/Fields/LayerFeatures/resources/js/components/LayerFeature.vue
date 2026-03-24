<template>
    <div class="layer-feature-wrapper">
        <!-- Modalità Index: Mostra i conteggi -->
        <div v-if="isIndexMode" class="flex items-center space-x-2">
            <div v-if="isLoading" class="text-gray-500">
                Caricamento...
            </div>
            <div v-else-if="error" class="text-red-500">
                Errore: {{ error }}
            </div>
            <div v-else class="flex items-center space-x-2">
                <span v-if="counts.ec_poi > 0"
                    class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-red-600 text-white">
                    POI: {{ counts.ec_poi }}
                </span>
                <span v-if="counts.ec_tracks > 0"
                    class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-600 text-white">
                    Tracks: {{ counts.ec_tracks }}
                </span>
                <span v-if="counts.hiking_routes > 0"
                    class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-orange-600 text-white">
                    Routes: {{ counts.hiking_routes }}
                </span>
                <span v-if="total === 0" class="text-gray-500">
                    Nessuna feature
                </span>
            </div>
        </div>

        <!-- Modalità Detail/Form: Mostra la griglia -->
        <div v-else>
            <ConfirmModal v-if="showConfirmModal" @confirm="confirmModeChange" @close="closeConfirmModal" />

            <div class="mb-6">
                <h3 class="text-90 font-normal text-xl">
                    Features del modello: {{ modelName }}
                </h3>
            </div>

            <div v-if="edit" class="flex items-center mb-4">
                <ToggleSwitch :is-manual="isManual" @toggle="handleToggleClick" />
            </div>

            <div>
                <div v-if="edit && isManual" class="flex justify-end mb-2">
                    <button type="button" class="btn btn-secondary" @click="selectAllVisible"
                        :disabled="isSaving || isLoading">
                        Seleziona visibili
                    </button>
                    <button type="button" class="btn btn-secondary" @click="deselectAllVisible"
                        :disabled="isSaving || isLoading">
                        Deseleziona visibili
                    </button>
                    <button type="button" class="btn btn-primary" @click="handleSave" :disabled="isSaving">
                        {{ isSaving ? "Salvataggio..." : "Salva" }}
                    </button>
                </div>
                <div>
                    <ag-grid-vue ref="agGridRef" class="ag-theme-alpine layer-feature-grid" :columnDefs="columnDefs"
                        :defaultColDef="defaultColDef" :rowData="gridData" :rowHeight="25" :getRowId="getRowId"
                        :suppressLoadingOverlay="false" :suppressNoRowsOverlay="false"
                        :overlayLoadingTemplate="loadingTemplate" :overlayNoRowsTemplate="noRowsTemplate"
                        :suppressRowClickSelection="true" :suppressCellSelection="true" :context="{
                            addToPersistentSelection,
                            removeFromPersistentSelection,
                            edit,
                        }" @grid-ready="handleGridReady" @first-data-rendered="onFirstDataRendered"
                        @filter-changed="onFilterChanged" />
                </div>

                <!-- Informazioni di paginazione e pulsante Carica di più -->
                <div v-if="totalFeatures > 0" class="mt-4 flex justify-between items-center">
                    <div class="text-sm text-gray-600">
                        Mostrando {{ gridData.length }} di {{ totalFeatures }} features
                    </div>
                    <button v-if="hasMorePages" type="button" class="btn btn-primary" @click="loadMoreFeatures"
                        :disabled="isLoading">
                        {{ isLoading ? "Caricamento..." : "Carica di più" }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script lang="ts">
import { defineComponent, ref, watch, onMounted } from "vue";
import { FormField, HandlesValidationErrors } from "laravel-nova";
import { AgGridVue } from "ag-grid-vue3";
import type { GridApi, IRowNode } from "ag-grid-community";
import type { LayerFeatureProps } from "../types/interfaces";
import { useFeatures } from "../composables/useFeatures";
import { useGrid } from "../composables/useGrid";
import ConfirmModal from "./layer-feature/ConfirmModal.vue";
import ToggleSwitch from "./layer-feature/ToggleSwitch.vue";
import CustomHeader from "./layer-feature/CustomHeader.vue";
import NameFilter from "./layer-feature/NameFilter.vue";
import "../styles/shared.css";

export default defineComponent({
    name: "LayerFeature",
    components: {
        AgGridVue,
        ConfirmModal,
        ToggleSwitch,
        CustomHeader,
        NameFilter,
    },
    mixins: [FormField, HandlesValidationErrors],
    props: {
        resourceName: { type: String, required: true },
        resourceId: { type: [Number, String], required: true },
        field: { type: Object, required: true },
        edit: { type: Boolean, default: true },
        value: { type: [Array, Object], default: () => [] },
    },
    setup(props: LayerFeatureProps) {
        // Rileva se siamo in modalità index
        const isIndexMode = ref(false);
        const counts = ref({
            ec_poi: 0,
            ec_tracks: 0,
            hiking_routes: 0
        });
        const total = ref(0);
        const error = ref(null);

        // Rileva la modalità index
        onMounted(() => {
            // Siamo in modalità index solo se siamo nella lista dei layers (resourceName === 'layers' e non abbiamo resourceId)
            isIndexMode.value = props.resourceName === 'layers' && !props.resourceId;

            if (isIndexMode.value) {
                fetchCounts();
            } else {
                console.log('LayerFeature: In detail/edit mode, loading features...');
            }
        });

        const isManual = ref<boolean>(
            props.edit ? props.field?.trackMode === 'manual' : true
        );

        const {
            isLoading,
            gridData,
            persistentSelectedIds,
            isSaving,
            currentPage,
            perPage,
            totalFeatures,
            hasMorePages,
            fetchFeatures,
            loadMoreFeatures,
            handleSave,
            updateSelectedNodes,
            setGridApi,
            addToPersistentSelection,
            removeFromPersistentSelection,
        } = useFeatures(props, isManual);

        const fetchCounts = async () => {
            try {
                isLoading.value = true;
                error.value = null;

                const layerId = props.resourceId || props.field?.layerId;

                if (!layerId) {
                    throw new Error('Layer ID non disponibile');
                }

                // Prova a ottenere il modello da diverse proprietà
                const modelClass = props.field?.model || props.field?.modelClass || props.field?.model_class || props.field?.value?.model || props.field?.meta?.model_class;

                if (!modelClass) {
                    console.error('LayerFeature: Model class not found in field meta');
                    console.error('LayerFeature: Available field properties:', Object.keys(props.field || {}));
                    console.error('LayerFeature: Field object:', props.field);
                    throw new Error('Modello non configurato nel field');
                }

                const url = `/nova-vendor/layer-features/${layerId}?model=${encodeURIComponent(modelClass)}`;

                const response = await fetch(url);

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const data = await response.json();
                console.log('LayerFeature: Response data:', data);

                // Aggiorna i conteggi basandosi sul modello
                if (modelClass) {
                    if (modelClass.includes('EcPoi')) {
                        counts.value = { ec_poi: data.count, ec_tracks: 0, hiking_routes: 0 };
                    } else if (modelClass.includes('EcTrack')) {
                        counts.value = { ec_poi: 0, ec_tracks: data.count, hiking_routes: 0 };
                    } else if (modelClass.includes('HikingRoute')) {
                        counts.value = { ec_poi: 0, ec_tracks: 0, hiking_routes: data.count };
                    }
                }
                total.value = data.count || 0;

                console.log('LayerFeature: Final counts:', counts.value, 'total:', total.value);

            } catch (err) {
                console.error('Errore nel caricamento dei conteggi:', err);
                error.value = err.message;
            } finally {
                isLoading.value = false;
            }
        };

        const {
            gridApi,
            columnDefs,
            defaultColDef,
            onGridReady: initGrid,
            sortBySelection,
        } = useGrid({
            resourceName: props.resourceName,
            modelName: props.field?.modelName,
        });

        const showConfirmModal = ref<boolean>(false);
        const modelName = ref<string | undefined>(props.field?.modelName);

        onMounted(() => {
            const savedIds = props.field?.selectedEcFeaturesIds;
            if (
                props.field?.trackMode === 'manual' &&
                Array.isArray(savedIds) &&
                savedIds.length > 0
            ) {
                persistentSelectedIds.value = savedIds;
            }
        });

        const handleGridReady = async (params: {
            api: GridApi;
        }): Promise<void> => {
            initGrid(params);
            setGridApi(params.api);

            try {
                await fetchFeatures();
            } catch (error) {
                Nova.error(
                    "Errore durante l'inizializzazione della griglia"
                );
            }
        };

        const loadingTemplate =
            '<span class="ag-overlay-no-rows-center">Caricamento dati...</span>';
        const noRowsTemplate =
            '<span class="ag-overlay-no-rows-center">Nessun dato disponibile</span>';

        const getRowId = (params: { data: { id: number } }) => params.data.id;

        const onFirstDataRendered = () => {
            if (gridApi.value) {
                updateSelectedNodes();
            } else {
                console.warn("onFirstDataRendered - GridApi not available");
            }
        };

        const onFilterChanged = async () => {
            if (!gridApi.value) return;
            try {
                await fetchFeatures(gridApi.value.getFilterModel());
            } catch (error) {
                Nova.error("Errore durante il filtraggio");
            }
        };

        const applySelectionToVisibleRows = (isSelected: boolean) => {
            if (!gridApi.value) return;

            const visibleIds: number[] = [];
            const changedNodes: IRowNode[] = [];

            gridApi.value.forEachNodeAfterFilterAndSort((node: IRowNode) => {
                if (!node.data || node.data.checkboxReadOnly) {
                    return;
                }

                visibleIds.push(node.data.id);
                changedNodes.push(node);
                node.setData({
                    ...node.data,
                    isSelected,
                });
            });

            if (isSelected) {
                visibleIds.forEach((id) => addToPersistentSelection(id));
            } else {
                visibleIds.forEach((id) => removeFromPersistentSelection(id));
            }

            // Riallinea lo stato delle righe alla selezione persistente e forza il redraw
            updateSelectedNodes();
            gridApi.value.redrawRows({ rowNodes: changedNodes });
            gridApi.value.refreshCells({
                columns: ["boolean"],
                force: true,
            });
            sortBySelection(gridApi.value);
        };

        const selectAllVisible = () => {
            applySelectionToVisibleRows(true);
        };

        const deselectAllVisible = () => {
            applySelectionToVisibleRows(false);
        };

        const handleToggleClick = async () => {
            if (isManual.value && persistentSelectedIds.value.length > 0) {
                showConfirmModal.value = true;
            } else {
                isManual.value = !isManual.value;
                if (isManual.value) {
                    try {
                        await fetchFeatures();
                    } catch (error) {
                        console.error("Error during toggle mode:", error);
                    }
                } else {
                    await handleModeChange();
                }
            }
        };

        const closeConfirmModal = () => {
            showConfirmModal.value = false;
            isManual.value = true;
        };

        const confirmModeChange = async () => {
            showConfirmModal.value = false;
            isManual.value = false;
            await handleModeChange();
        };

        const handleModeChange = async () => {
            console.log("[Handle Mode Change] Cambio di modalità");
            try {
                if (!isManual.value) {
                    isSaving.value = true;
                    const layerId = props.field.layerId;
                    await Nova.request().post(
                        `/nova-vendor/layer-features/sync/${layerId}`,
                        {
                            features: [],
                            model: props.field.model,
                            auto: true,
                        }
                    );
                    persistentSelectedIds.value = [];
                    props.field.value = [];
                    props.field.selectedEcFeaturesIds = [];
                    Nova.success("Modalità automatica attivata");
                    Nova.visit(window.location.href);
                }
            } catch (error) {
                console.error("Errore durante il cambio di modalità:", error);
                Nova.error("Errore durante il cambio di modalità");
                isManual.value = !isManual.value;
            } finally {
                isSaving.value = false;
            }
        };

        watch(
            () => props.resourceId,
            async (newId, oldId) => {
                if (isIndexMode.value) {
                    return;
                }
                try {
                    await fetchFeatures();
                } catch (error) {
                    console.error("Error during resourceId change:", error);
                }
            }
        );

        return {
            // Variabili per modalità index
            isIndexMode,
            counts,
            total,
            error,
            fetchCounts,
            // Variabili esistenti
            isLoading,
            gridData,
            isSaving,
            isManual,
            showConfirmModal,
            modelName,
            currentPage,
            perPage,
            totalFeatures,
            hasMorePages,
            columnDefs,
            defaultColDef,
            loadingTemplate,
            noRowsTemplate,
            getRowId,
            handleSave,
            loadMoreFeatures,
            handleGridReady,
            onFirstDataRendered,
            onFilterChanged,
            selectAllVisible,
            deselectAllVisible,
            handleToggleClick,
            closeConfirmModal,
            confirmModeChange,
            addToPersistentSelection,
            removeFromPersistentSelection,
        };
    },
});
</script>

<style scoped>
.layer-feature-wrapper {
    position: relative;
    width: 100%;
}

.layer-feature-grid {
    width: 100%;
    height: 500px;
}

.ag-theme-alpine {
    width: 100%;
    height: 500px;
    --ag-header-height: 30px;
    --ag-header-foreground-color: #000;
    --ag-header-background-color: #f8f9fa;
    --ag-row-hover-color: #f5f5f5;
    --ag-selected-row-background-color: #e7f4ff;
}

/* Stili per gli overlay */
.ag-overlay-loading-center,
.ag-overlay-no-rows-center {
    padding: 10px;
    color: #666;
    font-size: 14px;
}

.btn {
    padding: 0.5rem 1rem;
    border-radius: 0.375rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
}

.btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-primary {
    background-color: #4099de;
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    border: none;
    cursor: pointer;
    margin-right: 10px;
}

.btn-primary:hover {
    background-color: #357abd;
}

.flex {
    display: flex;
}

.justify-end {
    justify-content: flex-end;
}

.items-center {
    align-items: center;
}

.mt-4 {
    margin-top: 1rem;
}

.ag-header-container {
    display: flex;
    flex-direction: column;
}

.toolbar {
    display: flex;
    justify-content: flex-end;
    padding: 5px;
    border-bottom: 1px solid #ddd;
}

.mb-2 {
    margin-bottom: 0.5rem;
}

.btn-primary:hover:not(:disabled) {
    background-color: #357abd;
}

.btn-secondary {
    background-color: #5f6c7b;
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    border: none;
    cursor: pointer;
    margin-right: 8px;
}

.btn-secondary:hover:not(:disabled) {
    background-color: #4d5968;
}

.toggle-switch {
    display: inline-flex;
    align-items: center;
    cursor: pointer;
}

.toggle-button {
    position: relative;
    width: 50px;
    height: 24px;
    background-color: #ccc;
    border-radius: 24px;
    border: none;
    padding: 0;
    cursor: pointer;
    transition: background-color 0.3s;
}

.toggle-button--active {
    background-color: #4099de;
}

.toggle-slider {
    position: absolute;
    top: 4px;
    left: 4px;
    width: 16px;
    height: 16px;
    background-color: white;
    border-radius: 50%;
    transition: transform 0.3s;
}

.toggle-button--active .toggle-slider {
    transform: translateX(26px);
}

.label-text {
    margin-left: 10px;
}

.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.3s;
}

.fade-enter,
.fade-leave-to {
    opacity: 0;
}

.btn-danger {
    background-color: #e74444;
    color: white;
}

.btn-danger:hover {
    background-color: #e01e1e;
}

.btn-link {
    background: transparent;
    border: 0;
    color: #666;
    text-decoration: underline;
    padding: 0.5rem;
}

.btn-link:hover {
    color: #333;
}

.mb-6 {
    margin-bottom: 1.5rem;
}

.text-90 {
    color: var(--90);
}

.text-xl {
    font-size: 1.25rem;
    line-height: 1.75rem;
}

.font-normal {
    font-weight: 400;
}

.search-wrapper {
    position: relative;
}

.search-input {
    padding: 0.5rem;
    border: 1px solid #e2e8f0;
    border-radius: 0.375rem;
    width: 250px;
    font-size: 0.875rem;
    outline: none;
    transition: border-color 0.2s;
}

.search-input:focus {
    border-color: #4099de;
}

.justify-between {
    justify-content: space-between;
}
</style>
