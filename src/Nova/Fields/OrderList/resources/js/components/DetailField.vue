<template>
    <PanelItem :index="index" :field="field">
        <template #value>
            <div class="wm-order-list">
                <p v-if="items.length === 0" class="help-text">Nessun elemento.</p>

                <div v-if="error" class="wm-order-list__error">{{ error }}</div>

                <ul v-if="items.length > 0" class="wm-order-list__list">
                    <li
                        v-for="(item, i) in items"
                        :key="item.id"
                        class="wm-order-list__item"
                        :class="{
                            'wm-order-list__item--dragging': draggingIndex === i,
                            'wm-order-list__item--over': dragOverIndex === i && draggingIndex !== null,
                        }"
                        draggable="true"
                        @dragstart="onDragStart(i)"
                        @dragover="onDragOver($event, i)"
                        @drop="onDrop(i)"
                        @dragend="onDragEnd"
                    >
                        <span class="wm-order-list__handle" aria-hidden="true">⋮⋮</span>
                        <span
                            v-if="item.color"
                            class="wm-order-list__swatch"
                            aria-hidden="true"
                            :style="{ backgroundColor: item.color }"
                        />
                        <span class="wm-order-list__name">{{ item.label || ('#' + item.id) }}</span>
                        <span v-if="saving" class="wm-order-list__status">Salvataggio…</span>
                    </li>
                </ul>
            </div>
        </template>
    </PanelItem>
</template>

<script>
function arrayMove(arr, from, to) {
    const next = arr.slice();
    const item = next.splice(from, 1)[0];
    next.splice(to, 0, item);
    return next;
}

function resolveMeta(field) {
    const f = field || {};
    const m = f.meta || null;
    const hasKeys = (obj) => !!(obj && (Array.isArray(obj.items) || obj.reorderUrl));
    if (hasKeys(m)) return m;
    if (hasKeys(f)) return f;
    return m || f || {};
}

export default {
    name: 'DetailOrderList',
    props: ['index', 'resource', 'resourceName', 'resourceId', 'field'],
    data() {
        const meta = resolveMeta(this.field);
        const items = Array.isArray(meta.items) ? meta.items.slice() : [];

        return {
            items,
            reorderUrl: meta.reorderUrl || null,
            draggingIndex: null,
            dragOverIndex: null,
            saving: false,
            error: null,
        };
    },
    methods: {
        onDragStart(i) {
            this.draggingIndex = i;
            this.error = null;
        },
        onDragOver(event, i) {
            event.preventDefault();
            this.dragOverIndex = i;
        },
        onDragEnd() {
            this.draggingIndex = null;
            this.dragOverIndex = null;
        },
        onDrop(i) {
            if (this.draggingIndex === null || this.draggingIndex === i) {
                this.onDragEnd();
                return;
            }
            this.items = arrayMove(this.items, this.draggingIndex, i);
            this.onDragEnd();
            this.persist();
        },
        async persist() {
            if (!this.reorderUrl) return;
            this.saving = true;
            this.error = null;
            try {
                const ids = this.items.map((it) => it.id);
                await Nova.request().post(this.reorderUrl, { ids });
                if (typeof Nova?.success === 'function') {
                    Nova.success('Ordine salvato');
                }
            } catch (e) {
                this.error = 'Errore nel salvataggio dell’ordine';
                if (typeof Nova?.error === 'function') {
                    Nova.error(this.error);
                }
            } finally {
                this.saving = false;
            }
        },
    },
};
</script>
