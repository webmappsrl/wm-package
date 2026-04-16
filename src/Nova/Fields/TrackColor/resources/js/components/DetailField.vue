<template>
    <PanelItem :index="index" :field="field">
        <template #value>
            <div class="wm-track-color">
                <div class="wm-track-color__preview">
                    <span class="wm-track-color__swatch" :style="{ backgroundColor: effectiveHex }" />
                    <span class="wm-track-color__hex">{{ effectiveHex }}</span>
                </div>
                <div class="wm-track-color__source">
                    {{ sourceLabel }}
                </div>
            </div>
        </template>
    </PanelItem>
</template>

<script>
export default {
    name: 'DetailTrackColor',
    props: ['index', 'resource', 'resourceName', 'resourceId', 'field'],
    computed: {
        meta() {
            const f = this.field || {};
            const m = f.meta || null;
            const hasKeys = (obj) => !!(obj && (obj.effective_hex || obj.inherited_hex || obj.source || obj.layer || obj.stored_hex));
            if (hasKeys(m)) return m;
            if (hasKeys(f)) return f;
            return m || f;
        },
        effectiveHex() {
            return this.meta.effective_hex || '#FF0000';
        },
        sourceLabel() {
            const source = this.meta.source || 'default';
            if (source === 'layer') {
                const layerName = this.meta.layer && this.meta.layer.name ? this.meta.layer.name : 'Layer';
                return `Layer: ${layerName}`;
            }
            if (source === 'custom') {
                return 'Custom';
            }
            return 'Default';
        },
    },
};
</script>
