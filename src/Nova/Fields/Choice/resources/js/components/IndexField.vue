<template>
  <div class="flex flex-wrap gap-1">
    <div
      v-for="icon in selectedIcons"
      :key="icon.value"
      class="inline-flex items-center px-2 py-1 bg-gray-100 text-gray-800 text-xs rounded"
      :title="icon.label"
    >
      <!-- SVG Icon -->
      <svg v-if="icon.svg" class="w-4 h-4 mr-1 flex-shrink-0" viewBox="0 0 1024 1024" fill="currentColor">
        <g v-html="icon.svg"></g>
      </svg>
      <!-- Font Awesome Icon (fallback) -->
      <i v-else :class="[iconClass, icon.icon]" class="mr-1"></i>
      <span class="truncate max-w-20">{{ icon.label }}</span>
    </div>
    <span v-if="selectedIcons.length === 0" class="text-gray-400 text-sm">
      Nessuna icona selezionata
    </span>
  </div>
</template>

<script>
export default {
  props: ['resourceName', 'field'],

  computed: {
    fieldValue() {
      return this.field.displayedAs || this.field.value || ''
    },

    options() {
      return this.field.options || []
    },

    iconClass() {
      return this.field.iconClass || 'fas'
    },

    selectedIcons() {
      if (!this.fieldValue) return []
      
      const isMultiple = this.field.multiple || false
      
      if (isMultiple) {
        const values = this.fieldValue.split(',').filter(v => v)
        return this.options.filter(option => values.includes(option.value))
      } else {
        const selectedOption = this.options.find(option => option.value === this.fieldValue)
        return selectedOption ? [selectedOption] : []
      }
    }
  }
}
</script>
