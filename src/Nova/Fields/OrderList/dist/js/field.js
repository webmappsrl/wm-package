/* global Nova */

(function () {
  function arrayMove(arr, from, to) {
    const next = arr.slice();
    const item = next.splice(from, 1)[0];
    next.splice(to, 0, item);
    return next;
  }

  Nova.booting(function (app) {
    const Detail = {
      props: ['index', 'resource', 'resourceName', 'resourceId', 'field'],
      data() {
        const meta = this.field?.meta || {};
        const items = Array.isArray(meta.items)
          ? meta.items.slice()
          : (Array.isArray(this.field?.items) ? this.field.items.slice() : []);

        return {
          items,
          reorderUrl: meta.reorderUrl || this.field?.reorderUrl || null,
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
        onDragOver(e, i) {
          e.preventDefault();
          this.dragOverIndex = i;
        },
        onDrop(i) {
          if (this.draggingIndex === null || this.draggingIndex === i) return;
          this.items = arrayMove(this.items, this.draggingIndex, i);
          this.draggingIndex = null;
          this.dragOverIndex = null;
          this.persist();
        },
        async persist() {
          const reorderUrl = this.reorderUrl;
          if (!reorderUrl) return;

          this.saving = true;
          this.error = null;

          try {
            const ids = this.items.map(it => it.id);
            await Nova.request().post(reorderUrl, { ids });
            if (typeof Nova?.success === 'function') Nova.success('Ordine salvato');
          } catch (e) {
            this.error = 'Errore nel salvataggio dell’ordine';
            if (typeof Nova?.error === 'function') Nova.error(this.error);
          } finally {
            this.saving = false;
          }
        },
      },
      template: `
        <div class="wm-order-list">
          <p class="help-text" v-if="items.length === 0">Nessun elemento.</p>

          <div v-if="error" class="wm-order-list__error">{{ error }}</div>

          <ul class="wm-order-list__list" v-if="items.length > 0">
            <li
              v-for="(item, i) in items"
              :key="item.id"
              class="wm-order-list__item"
              :class="{
                'wm-order-list__item--dragging': draggingIndex === i,
                'wm-order-list__item--over': dragOverIndex === i && draggingIndex !== null
              }"
              draggable="true"
              @dragstart="onDragStart(i)"
              @dragover="(e) => onDragOver(e, i)"
              @drop="onDrop(i)"
            >
              <span class="wm-order-list__handle" aria-hidden="true">⋮⋮</span>
              <span class="wm-order-list__name">{{ item.label || ('#' + item.id) }}</span>
              <span class="wm-order-list__status" v-if="saving">Salvataggio…</span>
            </li>
          </ul>
        </div>
      `,
    };

    const Dummy = { props: ['field'], template: '<span />' };

    app.component('detail-order-list', Detail);
    app.component('index-order-list', Dummy);
    app.component('form-order-list', Dummy);
  });
})();

