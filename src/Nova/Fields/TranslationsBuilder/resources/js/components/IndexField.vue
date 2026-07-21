<template>
  <span class="text-sm">{{ summary }}</span>
</template>

<script>
import { collectAllKeys } from '../../../../_shared/resources/js/utils/collectAllKeys.js'

export default {
  props: ['resourceName', 'field'],

  computed: {
    langs() {
      return (this.field.value && this.field.value.langs) || ['it']
    },

    values() {
      return (this.field.value && this.field.value.values) || {}
    },

    keyCount() {
      return collectAllKeys(this.langs, this.values).length
    },

    summary() {
      return `${this.keyCount} traduzion${this.keyCount === 1 ? 'e' : 'i'}`
    },
  },
}
</script>
