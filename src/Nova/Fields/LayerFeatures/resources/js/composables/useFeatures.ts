import { ref, Ref } from 'vue';
import { IRowNode } from 'ag-grid-community';
import type { GridData, LayerFeatureProps } from '../types/interfaces';

interface FilterModel {
    name?: {
        filter: string;
    };
}

interface Resource {
    id: {
        value: number;
    };
    title : string,
    fields: Array<{
        attribute: string;
        value: string;
    }>;
}

interface PaginatedResponse {
    features: Array<{
        id: number;
        name: string | { [key: string]: string };
    }>;
    pagination: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
}

export function useFeatures(props: LayerFeatureProps) {
    const isLoading = ref<boolean>(true);
    const gridData = ref<GridData[]>([]);
    const persistentSelectedIds = ref<number[]>([]);
    const isSaving = ref<boolean>(false);
    const gridApi = ref<any | null>(null);
    
    // Variabili per la paginazione
    const currentPage = ref<number>(1);
    const perPage = ref<number>(50);
    const totalFeatures = ref<number>(0);
    const hasMorePages = ref<boolean>(false);

    const updateSelectedNodes = () => {
        setTimeout(() => {
            if (!gridApi.value) return;
            
            gridApi.value.forEachNode((node: IRowNode) => {
                const isSelected = persistentSelectedIds.value.includes(node.data.id);
                if (node.data.isSelected !== isSelected) {
                    const updatedData = {
                        ...node.data,
                        isSelected: isSelected
                    };
                    node.setData(updatedData);
                }
            });

            gridApi.value.refreshCells({
                columns: ['boolean'],
                force: true
            });
        }, 100);
    };

    const setGridApi = (api: any) => {
        gridApi.value = api;
    };

    const sortBySelection = (api: any | null): void => {
        if (!api) return;
        
        const allData: GridData[] = [];
        api.forEachNode((node: IRowNode) => {
            allData.push(node.data);
        });

        allData.sort((a, b) => {
            if (a.isSelected === b.isSelected) {
                return a.id - b.id;
            }
            return a.isSelected ? -1 : 1;
        });

        api.setRowData(allData);
    };

    const fetchFeatures = async (filterModel: FilterModel | null = null, page: number = 1, append: boolean = false): Promise<void> => {
        try {
            isLoading.value = true;
            
            // Mostra l'overlay di caricamento
            if (gridApi.value) {
                gridApi.value.showLoadingOverlay();
            }
            
            if (!props.field?.modelName || !props.field?.layerId) {
                throw new Error('Required field properties are missing');
            }

            const layerId = props.field.layerId;
            const model = props.field.model;
            const searchValue = filterModel?.name?.filter || '';

            // Determina la modalità di visualizzazione in base al contesto
            // Se non siamo in modalità edit, siamo in modalità details
            const viewMode = props.edit ? 'edit' : 'details';

            // Costruisci l'URL per il nuovo endpoint paginato
            const params = new URLSearchParams({
                model: model,
                page: page.toString(),
                per_page: perPage.value.toString(),
                view_mode: viewMode,
            });

            if (searchValue) {
                params.append('search', searchValue);
            }

            const url = `/nova-vendor/layer-features/features/${layerId}?${params.toString()}`;
            
            const response = await fetch(url);
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data: PaginatedResponse = await response.json();
            
            // Verifica che data.features sia un array
            if (!Array.isArray(data.features)) {
                console.error('data.features is not an array:', data.features);
                throw new Error('Invalid response format: features is not an array');
            }
            
            // Mappa le features alla struttura GridData
            // Le features sono selezionate se sono presenti in persistentSelectedIds
            const newFeatures: GridData[] = data.features.map(feature => ({
                id: feature.id,
                name: typeof feature.name === 'object' ? feature.name.it || feature.name.en || Object.values(feature.name)[0] : feature.name,
                isSelected: persistentSelectedIds.value.includes(feature.id)
            }));

            // Aggiorna le variabili di paginazione
            currentPage.value = data.pagination.current_page;
            totalFeatures.value = data.pagination.total;
            hasMorePages.value = data.pagination.current_page < data.pagination.last_page;

            // Se append è true, aggiungi alle features esistenti, altrimenti sostituisci
            if (append) {
                gridData.value = [...gridData.value, ...newFeatures];
            } else {
                gridData.value = newFeatures;
            }
            
        } catch (error) {
            console.error('Error fetching features:', error);
            if (!append) {
                gridData.value = [];
            }
            Nova.error('Errore durante il caricamento delle features');
            throw error;
        } finally {
            isLoading.value = false;
            
            // Nasconde l'overlay di caricamento e mostra quello appropriato
            if (gridApi.value) {
                gridApi.value.hideOverlay();
                if (gridData.value.length === 0) {
                    gridApi.value.showNoRowsOverlay();
                }
            }
            
            updateSelectedNodes();
            setTimeout(() => {
                sortBySelection(gridApi.value);
            }, 100);
        }
    };

    const handleSave = async (): Promise<void> => {
        try {
            isSaving.value = true;
            const layerId = props.field.layerId;

            if (!layerId) {
                throw new Error('LayerId is required for saving');
            }

            await Nova.request().post(`/nova-vendor/layer-features/sync/${layerId}`, {
                features: persistentSelectedIds.value,
                model: props.field.model,
            });

            Nova.success('Features salvate con successo');
            props.field.value = persistentSelectedIds.value;
            props.field.selectedEcFeaturesIds = persistentSelectedIds.value;

            await fetchFeatures();
        } catch (error) {
            Nova.error('Errore durante il salvataggio delle features');
            throw error;
        } finally {
            isSaving.value = false;
        }
    };

    const addToPersistentSelection = (id: number) => {
        if (!persistentSelectedIds.value.includes(id)) {
            persistentSelectedIds.value = [...persistentSelectedIds.value, id];
        }
    };

    const removeFromPersistentSelection = (id: number) => {
        persistentSelectedIds.value = persistentSelectedIds.value.filter(selectedId => selectedId !== id);
    };

    const loadMoreFeatures = async (): Promise<void> => {
        
        if (hasMorePages.value && !isLoading.value && currentPage.value < 10) { // Limita a 10 pagine per evitare loop infiniti
            try {
                await fetchFeatures(null, currentPage.value + 1, true);
                console.log('loadMoreFeatures completed successfully');
            } catch (error) {
                console.error('loadMoreFeatures error:', error);
                Nova.error('Errore durante il caricamento di altre features');
            }
        } else {
            console.log('loadMoreFeatures skipped - conditions not met');
        }
    };

    return {
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
    };
} 