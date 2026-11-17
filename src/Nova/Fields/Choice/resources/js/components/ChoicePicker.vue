<template>
  <div class="relative">
    <!-- Search Input -->
    <input :id="inputId" ref="searchInput" type="text"
      class="w-full form-control form-input form-control-bordered" :class="inputClass"
      :placeholder="searchPlaceholder" v-model="searchQuery" @focus="showDropdown = true" @input="filterOptions"
      @keydown="handleKeydown" autocomplete="off" />

    <!-- Selected Icons Display -->
    <div v-if="modelValue.length > 0" class="mt-2 flex flex-wrap gap-2">
      <div v-for="item in modelValue" :key="item.value"
        class="inline-flex items-center px-2 py-1 bg-primary-100 text-primary-800 text-sm rounded-md">
        <!-- SVG Icon -->
        <svg v-if="item.svg" class="w-4 h-4 mr-1" viewBox="0 0 1024 1024" fill="currentColor">
          <g v-html="item.svg"></g>
        </svg>
        <!-- Font Awesome Icon (fallback) -->
        <i v-else-if="item.icon" :class="[iconClass, item.icon]" class="mr-1"></i>
        <!-- Senza icona resta lo spazio: le voci restano allineate fra loro -->
        <span v-else class="w-4 h-4 mr-1 flex-shrink-0" aria-hidden="true"></span>
        <span>{{ item.label }}</span>
        <button type="button" @click="removeItem(item)" class="ml-1 text-primary-600 hover:text-primary-800">
          ×
        </button>
      </div>
    </div>

    <!-- Dropdown -->
    <div v-show="showDropdown && filteredOptions.length > 0"
      class="choice-picker-dropdown absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-md shadow-lg"
      style="max-height: 240px; overflow: hidden;">
      <div class="overflow-y-auto" style="max-height: 240px; -webkit-overflow-scrolling: touch;">
        <div v-for="(option, index) in filteredOptions" :key="option.value" @click="selectOption(option)" :class="[
          'px-3 py-2 cursor-pointer flex items-center',
          index === highlightedIndex ? 'bg-primary-100' : 'hover:bg-gray-100'
        ]">
          <!-- SVG Icon -->
          <svg v-if="option.svg" class="w-5 h-5 mr-2 flex-shrink-0" viewBox="0 0 1024 1024" fill="currentColor">
            <g v-html="option.svg"></g>
          </svg>
          <!-- Font Awesome Icon (fallback) -->
          <i v-else-if="option.icon" :class="[iconClass, option.icon]" class="mr-2"></i>
          <span v-else class="w-5 h-5 mr-2 flex-shrink-0" aria-hidden="true"></span>
          <span>{{ option.label }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
/*
 * Il selettore del campo Choice: ricerca, chip dei valori scelti, dropdown con le icone
 * (oc:8988). Lo usano il form (FormField.vue) e il menu dei filtri (FilterField.vue), così le due
 * cose mostrano le stesse opzioni nello stesso modo.
 *
 * Gestisce solo la scelta: riceve con v-model l'array delle opzioni scelte e lo restituisce
 * aggiornato. Come quel valore diventa il dato del form o il valore del filtro lo decide chi lo
 * usa. Le opzioni hanno `value` stringa: la ricerca e il confronto delle chip lavorano su stringhe.
 */
export default {
  emits: ['update:modelValue'],

  props: {
    options: { type: Array, default: () => [] },
    modelValue: { type: Array, default: () => [] },
    multiple: { type: Boolean, default: false },
    maxItems: { type: Number, default: null },
    searchPlaceholder: { type: String, default: 'Cerca un\'icona...' },
    iconClass: { type: String, default: 'fas' },
    inputId: { type: String, default: null },
    inputClass: { type: [String, Array, Object], default: null },
  },

  data() {
    return {
      searchQuery: '',
      showDropdown: false,
      highlightedIndex: -1,
      filteredOptions: []
    }
  },

  mounted() {
    this.filteredOptions = this.options
    document.addEventListener('click', this.handleClickOutside)
  },

  beforeUnmount() {
    document.removeEventListener('click', this.handleClickOutside)
  },

  methods: {
    filterOptions() {
      if (!this.searchQuery) {
        this.filteredOptions = this.options
      } else {
        this.filteredOptions = this.options.filter(option =>
          option.label.toLowerCase().includes(this.searchQuery.toLowerCase()) ||
          option.value.toLowerCase().includes(this.searchQuery.toLowerCase())
        )
      }
      this.highlightedIndex = -1
    },

    selectOption(option) {
      let selected = this.modelValue
      if (this.multiple) {
        // Check if already selected
        if (!selected.find(item => item.value === option.value)) {
          // Check max items limit
          if (!this.maxItems || selected.length < this.maxItems) {
            selected = [...selected, option]
          }
        }
      } else {
        selected = [option]
        this.showDropdown = false
      }

      this.searchQuery = ''
      this.filteredOptions = this.options
      this.$emit('update:modelValue', selected)
    },

    removeItem(item) {
      this.$emit('update:modelValue', this.modelValue.filter(selected => selected.value !== item.value))
    },

    handleKeydown(event) {
      if (!this.showDropdown) return

      switch (event.key) {
        case 'ArrowDown':
          event.preventDefault()
          this.highlightedIndex = Math.min(this.highlightedIndex + 1, this.filteredOptions.length - 1)
          break
        case 'ArrowUp':
          event.preventDefault()
          this.highlightedIndex = Math.max(this.highlightedIndex - 1, -1)
          break
        case 'Enter':
          event.preventDefault()
          if (this.highlightedIndex >= 0 && this.filteredOptions[this.highlightedIndex]) {
            this.selectOption(this.filteredOptions[this.highlightedIndex])
          }
          break
        case 'Escape':
          this.showDropdown = false
          this.searchQuery = ''
          this.filteredOptions = this.options
          break
      }
    },

    handleClickOutside(event) {
      if (!this.$el.contains(event.target)) {
        this.showDropdown = false
      }
    }
  }
}
</script>
