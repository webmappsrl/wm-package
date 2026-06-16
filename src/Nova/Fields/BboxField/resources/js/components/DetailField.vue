<template>
    <PanelItem :index="index" :field="field">
        <template #value>
            <div v-if="field.bboxValue" class="flex items-center gap-2 mb-3">
                <span class="font-mono text-sm">{{ field.bboxValue }}</span>
                <button
                    @click="copyBbox"
                    class="px-2 py-1 text-xs border border-gray-300 dark:border-gray-600 rounded bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-600 dark:text-gray-400 cursor-pointer"
                >{{ copied ? 'Copied!' : 'Copy' }}</button>
            </div>
            <FeatureCollectionMap
                v-if="field.geojson"
                :height="400"
                :show-zoom-controls="true"
                :mouse-wheel-zoom="false"
                :drag-pan="true"
                :enable-screenshot="false"
                :enable-slope-chart="false"
                :inline-geojson="field.geojson"
            />
        </template>
    </PanelItem>
</template>

<script>
import FeatureCollectionMap from '../../../../FeatureCollectionMap/resources/js/components/FeatureCollectionMap.vue';

export default {
    name: 'DetailBboxField',

    components: { FeatureCollectionMap },

    props: ['index', 'resource', 'resourceName', 'resourceId', 'field'],

    data() {
        return { copied: false };
    },

    methods: {
        copyBbox() {
            navigator.clipboard.writeText(this.field.bboxValue).then(() => {
                this.copied = true;
                setTimeout(() => { this.copied = false; }, 2000);
            });
        },
    },
};
</script>
