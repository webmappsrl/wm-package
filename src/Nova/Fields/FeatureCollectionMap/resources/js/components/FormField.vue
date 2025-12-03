<template>
    <DefaultField :field="field" :errors="errors" :show-help-text="showHelpText">
        <template #field>
            <div class="text-gray-500">
                La mappa è disponibile solo in visualizzazione dettaglio.
            </div>
        </template>
    </DefaultField>
</template>

<script>
export default {
    name: 'FormFeatureCollectionMap',
    props: ['field', 'errors', 'showHelpText', 'resourceName', 'resourceId']
};
</script>


