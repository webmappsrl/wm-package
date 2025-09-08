<template>
  <DefaultField :field="field" :errors="errors" :show-help-text="showHelpText" :full-width-content="fullWidthContent">
    <template #field>
      <div class="relative">
        <!-- Search Input -->
        <input :id="field.attribute + '_search'" ref="searchInput" type="text"
          class="w-full form-control form-input form-control-bordered" :class="errorClasses"
          :placeholder="searchPlaceholder" v-model="searchQuery" @focus="showDropdown = true" @input="filterOptions"
          @keydown="handleKeydown" autocomplete="off" />

        <!-- Selected Icons Display -->
        <div v-if="selectedItems.length > 0" class="mt-2 flex flex-wrap gap-2">
          <div v-for="item in selectedItems" :key="item.value"
            class="inline-flex items-center px-2 py-1 bg-primary-100 text-primary-800 text-sm rounded-md">
            <!-- SVG Icon -->
            <svg v-if="item.svg" class="w-4 h-4 mr-1" viewBox="0 0 1024 1024" fill="currentColor">
              <g v-html="item.svg"></g>
            </svg>
            <!-- Font Awesome Icon (fallback) -->
            <i v-else :class="[iconClass, item.icon]" class="mr-1"></i>
            <span>{{ item.label }}</span>
            <button type="button" @click="removeItem(item)" class="ml-1 text-primary-600 hover:text-primary-800">
              ×
            </button>
          </div>
        </div>

        <!-- Dropdown -->
        <div v-show="showDropdown && filteredOptions.length > 0"
          class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-md shadow-lg"
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
              <i v-else :class="[iconClass, option.icon]" class="mr-2"></i>
              <span>{{ option.label }}</span>
            </div>
          </div>
        </div>

        <!-- Hidden input for form submission -->
        <input :id="field.attribute" type="hidden" :name="field.attribute" :value="formValue" />
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
      searchQuery: '',
      showDropdown: false,
      highlightedIndex: -1,
      selectedItems: [],
      filteredOptions: []
    }
  },

  computed: {
    options() {
      return this.field.options || []
    },

    searchPlaceholder() {
      return this.field.searchPlaceholder || 'Cerca un\'icona...'
    },

    isMultiple() {
      return this.field.multiple || false
    },

    maxItems() {
      return this.field.maxItems || null
    },

    iconClass() {
      return this.field.iconClass || 'fas'
    },

    formValue() {
      if (this.isMultiple) {
        return this.selectedItems.map(item => item.value).join(',')
      }
      return this.selectedItems.length > 0 ? this.selectedItems[0].value : ''
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
    /*
     * Set the initial, internal value for the field.
     */
    setInitialValue() {
      const value = this.field.value || ''

      if (value) {
        if (this.isMultiple) {
          const values = value.split(',')
          this.selectedItems = this.options.filter(option => values.includes(option.value))
        } else {
          const selectedOption = this.options.find(option => option.value === value)
          if (selectedOption) {
            this.selectedItems = [selectedOption]
          }
        }
      }

      this.value = value
    },

    /**
     * Fill the given FormData object with the field's internal value.
     */
    fill(formData) {
      formData.append(this.fieldAttribute, this.formValue)
    },

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
      if (this.isMultiple) {
        // Check if already selected
        if (!this.selectedItems.find(item => item.value === option.value)) {
          // Check max items limit
          if (!this.maxItems || this.selectedItems.length < this.maxItems) {
            this.selectedItems.push(option)
          }
        }
      } else {
        this.selectedItems = [option]
        this.showDropdown = false
      }

      this.searchQuery = ''
      this.filteredOptions = this.options
      this.value = this.formValue
    },

    removeItem(item) {
      this.selectedItems = this.selectedItems.filter(selected => selected.value !== item.value)
      this.value = this.formValue
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
