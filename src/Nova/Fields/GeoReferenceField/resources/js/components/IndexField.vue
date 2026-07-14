<template>
  <span v-if="displayLabel" class="whitespace-nowrap">{{ displayLabel }}</span>
  <span v-else class="text-gray-400">—</span>
</template>

<script>
export default {
  props: ['resourceName', 'field'],

  computed: {
    displayLabel() {
      const value = this.field.value || {}

      if (!value.type || !value.id) {
        return null
      }

      const options = value.type === 'track' ? this.field.trackOptions : this.field.poiOptions
      const label = options && options[value.id]

      if (!label) {
        return null
      }

      return `${value.type === 'track' ? 'Track' : 'Poi'}: ${label}`
    },
  },
}
</script>
