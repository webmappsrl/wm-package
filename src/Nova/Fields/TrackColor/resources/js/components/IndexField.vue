<template>
    <span class="wm-track-color-index">
        <span class="wm-track-color-index__swatch" :style="{ backgroundColor: effectiveHex }" />
        <span class="wm-track-color-index__hex">{{ effectiveHex }}</span>
    </span>
</template>

<script>
export default {
    name: 'IndexTrackColor',
    props: ['resourceName', 'field'],
    computed: {
        meta() {
            const f = this.field || {};
            const m = f.meta || null;
            const hasKeys = (obj) => !!(obj && (obj.effective_hex || obj.inherited_hex || obj.source));
            if (hasKeys(m)) return m;
            if (hasKeys(f)) return f;
            return m || f;
        },
        effectiveHex() {
            return this.meta.effective_hex || '#FF0000';
        },
    },
};
</script>
