<template>
    <div v-if="isLoading" class="text-gray-500">
        Caricamento...
    </div>
    <div v-else-if="error" class="text-red-500">
        Errore: {{ error }}
    </div>
    <div v-else class="flex items-center space-x-2">
        <span v-if="counts.ec_poi > 0"
            class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-primary-800">
            POI: {{ counts.ec_poi }}
        </span>
        <span v-if="counts.ec_tracks > 0"
            class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-primary-800">
            Tracks: {{ counts.ec_tracks }}
        </span>
        <span v-if="total === 0" class="text-primary-500">
            Nessuna feature
        </span>
    </div>
</template>

<script>
import { ref, onMounted } from 'vue';

export default {
    props: ["resourceName", "field", "resourceId"],

    setup(props) {
        const isLoading = ref(true);
        const error = ref(null);
        const counts = ref({
            ec_poi: 0,
            ec_tracks: 0
        });
        const total = ref(0);

        const fetchCounts = async () => {
            try {
                isLoading.value = true;
                error.value = null;

                // Usa il resourceId se disponibile, altrimenti fallback al layerId del field
                const layerId = props.resourceId || props.field?.layerId;

                console.log('IndexField: Fetching counts for layer:', layerId);
                console.log('IndexField: Props:', props);
                console.log('IndexField: Field model:', props.field?.model);
                console.log('IndexField: Field meta:', props.field);
                console.log('IndexField: Field keys:', Object.keys(props.field || {}));
                console.log('IndexField: Field value:', props.field?.value);
                console.log('IndexField: Field meta model_class:', props.field?.meta?.model_class);
                console.log('IndexField: Field modelClass:', props.field?.modelClass);
                console.log('IndexField: Field model_class:', props.field?.model_class);

                if (!layerId) {
                    throw new Error('Layer ID non disponibile');
                }

                // Prova a ottenere il modello da diverse proprietà
                const modelClass = props.field?.model || props.field?.modelClass || props.field?.model_class || props.field?.value?.model || props.field?.meta?.model_class;

                if (!modelClass) {
                    console.error('IndexField: Model class not found in field meta');
                    console.error('IndexField: Available field properties:', Object.keys(props.field || {}));
                    console.error('IndexField: Field object:', props.field);
                    throw new Error('Modello non configurato nel field');
                }

                const url = `/nova-vendor/layer-features/${layerId}?model=${encodeURIComponent(modelClass)}`;
                console.log('IndexField: Calling URL:', url);

                const response = await fetch(url);

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const data = await response.json();
                console.log('IndexField: Response data:', data);

                // Aggiorna i conteggi basandosi sul modello
                if (modelClass) {
                    if (modelClass.includes('EcPoi')) {
                        counts.value = { ec_poi: data.count, ec_tracks: 0 };
                    } else if (modelClass.includes('EcTrack')) {
                        counts.value = { ec_poi: 0, ec_tracks: data.count };
                    } else if (modelClass.includes('HikingRoute')) {
                        counts.value = { ec_poi: 0, ec_tracks: data.count };
                    }
                }
                total.value = data.count || 0;

                console.log('IndexField: Final counts:', counts.value, 'total:', total.value);

            } catch (err) {
                console.error('Errore nel caricamento dei conteggi:', err);
                error.value = err.message;
            } finally {
                isLoading.value = false;
            }
        };

        onMounted(() => {
            console.log('IndexField mounted for layer:', props.resourceId || props.field?.layerId);
            fetchCounts();
        });

        return {
            isLoading,
            error,
            counts,
            total
        };
    }
};
</script>
