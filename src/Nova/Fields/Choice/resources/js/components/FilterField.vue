<template>
  <FilterContainer>
    <span>{{ filter.name }}</span>

    <template #filter>
      <div class="choice-filter">
        <ChoicePicker :model-value="selectedItems" @update:model-value="onPick" :options="options"
          :multiple="isMultiple" :search-placeholder="searchPlaceholder"
          :input-id="filter.uniqueKey + '-search'" />
      </div>
    </template>
  </FilterContainer>
</template>

<script>
import ChoicePicker from './ChoicePicker'

/*
 * Il componente dei filtri Nova costruiti su ChoiceFilter (oc:8988): lo stesso selettore del form,
 * nel menu dei filtri dell'index.
 *
 * Segue il protocollo di SelectFilter di Nova: il filtro si legge dallo store, il valore scelto si
 * scrive con updateFilterState e si annuncia con `change`. Il valore è un array di `value` in
 * multiple, una stringa (vuota se non c'è scelta) in single, come il `default()` del filtro: Nova
 * conta un filtro attivo confrontando il valore corrente con quello di partenza.
 *
 * I valori letti dallo store si convertono in stringa: un link salvato può portarli come numeri
 * (gli id delle aree catastali del vecchio filtro outline), e le opzioni hanno `value` stringa.
 */
export default {
  components: { ChoicePicker },

  emits: ['change'],

  props: {
    resourceName: { type: String, required: true },
    filterKey: { type: String, required: true },
    lens: String,
  },

  data() {
    return {
      selectedItems: []
    }
  },

  computed: {
    filter() {
      return this.$store.getters[`${this.resourceName}/getFilter`](this.filterKey)
    },

    options() {
      return this.filter.options || []
    },

    isMultiple() {
      return this.filter.multiple || false
    },

    searchPlaceholder() {
      return this.filter.searchPlaceholder || this.__('Search')
    }
  },

  created() {
    this.syncFromStore()
  },

  mounted() {
    Nova.$on('filter-reset', this.syncFromStore)
  },

  beforeUnmount() {
    Nova.$off('filter-reset', this.syncFromStore)
  },

  methods: {
    /*
     * Le chip dal valore nello store: all'apertura (anche con il filtro già nell'URL) e dopo
     * «Reset filters», quando Nova ha già riportato lo store al valore di partenza.
     */
    syncFromStore() {
      const current = this.filter.currentValue
      const values = (Array.isArray(current) ? current : [current])
        .filter(value => value !== null && value !== undefined && value !== '')
        .map(value => String(value))

      this.selectedItems = this.options.filter(option => values.includes(option.value))
    },

    onPick(items) {
      this.selectedItems = items

      const values = items.map(item => item.value)
      this.$store.commit(`${this.resourceName}/updateFilterState`, {
        filterClass: this.filterKey,
        value: this.isMultiple ? values : (values[0] ?? ''),
      })

      this.$emit('change')
    }
  }
}
</script>

<style scoped>
/*
 * Nel menu dei filtri, che scorre ed è alto al massimo 350px, il dropdown sovrapposto del
 * selettore resterebbe tagliato: qui sta nel flusso e il menu lo scorre intero. Niente teleport:
 * il selettore chiude il dropdown sui clic fuori dal proprio elemento, e con le opzioni portate su
 * body si chiuderebbe a ogni scelta.
 */
.choice-filter :deep(.choice-picker-dropdown) {
  position: static;
}
</style>
