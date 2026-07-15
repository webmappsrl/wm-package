<template>
  <DefaultField :field="field" :errors="errors" :show-help-text="showHelpText" :full-width-content="fullWidthContent">
    <template #field>
      <div class="relative">
        <div class="flex space-x-2 mb-3">
          <button
            v-for="option in modelTypeOptions"
            :key="option.value"
            type="button"
            @click="selectModelType(option.value)"
            class="btn"
            :class="modelType === option.value ? 'btn-default btn-primary' : 'btn-link'"
          >
            {{ option.label }}
          </button>
        </div>

        <input
          :id="field.attribute"
          ref="searchInput"
          type="text"
          class="w-full form-control form-input form-control-bordered"
          :class="errorClasses"
          :placeholder="placeholder"
          v-model="searchQuery"
          @focus="openDropdown"
          @input="handleInput"
          @keydown="handleKeydown"
          autocomplete="off"
        />

        <div
          v-show="showDropdown && filteredOptions.length > 0"
          class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-md shadow-lg"
          style="max-height: 240px; overflow: hidden;"
        >
          <div class="overflow-y-auto" style="max-height: 240px; -webkit-overflow-scrolling: touch;">
            <div
              v-for="(option, index) in filteredOptions"
              :key="option.value"
              @mousedown.prevent="selectOption(option)"
              :class="[
                'px-3 py-2 cursor-pointer',
                index === highlightedIndex ? 'bg-primary-100' : 'hover:bg-gray-100',
                option.value === selectedId ? 'font-semibold' : '',
              ]"
            >
              {{ option.label }}
            </div>
          </div>
        </div>

        <div v-show="showDropdown && searchQuery && filteredOptions.length === 0" class="text-sm text-gray-400 mt-1">
          {{ noResultsLabel }}
        </div>
      </div>
    </template>
  </DefaultField>
</template>

<script>
import { FormField, HandlesValidationErrors } from 'laravel-nova'

export default {
  mixins: [FormField, HandlesValidationErrors],

  props: ['resourceName', 'resourceId', 'field'],

  data() {
    return {
      modelType: 'poi',
      selectedId: null,
      searchQuery: '',
      showDropdown: false,
      highlightedIndex: -1,
    }
  },

  computed: {
    modelTypeOptions() {
      return [
        { value: 'poi', label: 'Poi' },
        { value: 'track', label: 'Track' },
      ]
    },

    poiOptions() {
      return this.optionsToArray(this.field.poiOptions || {})
    },

    trackOptions() {
      return this.optionsToArray(this.field.trackOptions || {})
    },

    allOptionsForType() {
      return this.modelType === 'track' ? this.trackOptions : this.poiOptions
    },

    filteredOptions() {
      if (!this.searchQuery) {
        return this.allOptionsForType
      }

      const query = this.searchQuery.toLowerCase()

      return this.allOptionsForType.filter(option => option.label.toLowerCase().includes(query))
    },

    placeholder() {
      return this.modelType === 'track' ? 'Search a track…' : 'Search a poi…'
    },

    noResultsLabel() {
      return this.modelType === 'track' ? 'No matching track found.' : 'No matching poi found.'
    },
  },

  mounted() {
    document.addEventListener('click', this.handleClickOutside)
  },

  beforeUnmount() {
    document.removeEventListener('click', this.handleClickOutside)
  },

  methods: {
    optionsToArray(options) {
      return Object.entries(options).map(([value, label]) => ({
        value: Number(value),
        label,
      }))
    },

    /*
     * Set the initial, internal value for the field.
     */
    setInitialValue() {
      const value = this.field.value || {}
      this.modelType = value.type || 'poi'
      this.selectedId = value.id || null
      this.value = value
      this.searchQuery = this.labelFor(this.modelType, this.selectedId)
    },

    labelFor(type, id) {
      if (!id) {
        return ''
      }

      const options = type === 'track' ? this.trackOptions : this.poiOptions
      const match = options.find(option => option.value === id)

      return match ? match.label : ''
    },

    selectModelType(type) {
      if (this.modelType === type) {
        return
      }

      this.modelType = type
      this.selectedId = null
      this.searchQuery = ''
      this.highlightedIndex = -1
    },

    openDropdown() {
      this.showDropdown = true
      this.highlightedIndex = -1
    },

    handleInput() {
      this.showDropdown = true
      this.highlightedIndex = -1

      if (this.searchQuery === '') {
        this.selectedId = null
      }
    },

    selectOption(option) {
      this.selectedId = option.value
      this.searchQuery = option.label
      this.showDropdown = false
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
          break
      }
    },

    handleClickOutside(event) {
      if (this.$el && !this.$el.contains(event.target)) {
        this.showDropdown = false
      }
    },

    /**
     * Fill the given FormData object with the field's internal value. Omitted entirely when no
     * option is selected (e.g. the Model toggle was switched without picking a new value), so the
     * `required` rule on the PHP side rejects the save instead of silently clearing the item.
     */
    fill(formData) {
      if (!this.selectedId) {
        return
      }

      formData.append(this.fieldAttribute, JSON.stringify({ type: this.modelType, id: this.selectedId }))
    },
  },
}
</script>
