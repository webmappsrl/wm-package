<template>
    <DefaultField :field="field" :errors="errors" :show-help-text="showHelpText">
        <template #field>
            <div class="wm-track-color">
                <div class="wm-track-color__row">
                    <input
                        :id="field.attribute"
                        type="color"
                        class="wm-track-color__input"
                        :value="currentHex"
                        @input="onColorChange"
                    />
                    <span class="wm-track-color__hex wm-track-color__hex--muted">{{ currentHex }}</span>
                    <button
                        type="button"
                        class="wm-track-color__reset"
                        @click.prevent="resetToInherited"
                    >
                        {{ resetLabel }}
                    </button>
                </div>
                <div class="wm-track-color__info">
                    <span class="wm-track-color__swatch" :style="{ backgroundColor: currentHex }" />
                    <span class="wm-track-color__hex">{{ currentHex }}</span>
                    <span class="wm-track-color__source">Origine: {{ sourceLabel }}</span>
                </div>
            </div>
        </template>
    </DefaultField>
</template>

<script>
import { FormField, HandlesValidationErrors } from 'laravel-nova';

function resolveMeta(field) {
    const f = field || {};
    const m = f.meta || null;
    const hasKeys = (obj) => !!(obj && (obj.effective_hex || obj.inherited_hex || obj.source || obj.layer || obj.stored_hex));
    if (hasKeys(m)) return m;
    if (hasKeys(f)) return f;
    return m || f || {};
}

export default {
    name: 'FormTrackColor',
    mixins: [FormField, HandlesValidationErrors],
    props: ['resourceName', 'resourceId', 'field'],
    data() {
        const meta = resolveMeta(this.field);
        const stored = meta.stored_hex || null;
        const effective = meta.effective_hex || '#FF0000';
        return {
            currentHex: stored || effective,
            inheritedHex: meta.inherited_hex || '#FF0000',
            source: meta.source || 'default',
            layer: meta.layer || null,
            isReset: false,
        };
    },
    computed: {
        sourceLabel() {
            if (this.source === 'layer') {
                const layerName = this.layer && this.layer.name ? this.layer.name : 'Layer';
                return `Layer: ${layerName}`;
            }
            if (this.source === 'custom') {
                return 'Custom';
            }
            return 'Default';
        },
        resetLabel() {
            const layerName = this.layer && this.layer.name ? this.layer.name : null;
            if (layerName) return `Usa colore layer ${layerName}`;
            return 'Usa colore default';
        },
    },
    methods: {
        setInitialValue() {
            this.value = this.currentHex || '';
        },
        fill(formData) {
            if (this.isReset) {
                formData.append(this.field.attribute, '__RESET__');
            } else {
                formData.append(this.field.attribute, this.currentHex || '');
            }
        },
        handleChange(value) {
            this.currentHex = value;
            this.isReset = false;
            this.source = 'custom';
        },
        onColorChange(event) {
            const value = event.target.value || '';
            this.handleChange(value);
        },
        resetToInherited() {
            this.currentHex = this.inheritedHex;
            this.isReset = true;
            this.source = this.layer ? 'layer' : 'default';
        },
    },
};
</script>
