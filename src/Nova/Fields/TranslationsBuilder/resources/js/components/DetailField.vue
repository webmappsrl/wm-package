<template>
  <div class="flex flex-col -mx-6 px-6 py-2 space-y-2 md:flex-row md:py-0 md:space-y-0" :dusk="field.attribute">
    <div class="md:w-1/4 md:py-3">
      <h4 class="font-normal">
        <span>{{ field.name }}</span>
      </h4>
    </div>

    <div class="md:w-3/4 md:py-3">
      <table v-if="allKeys.length > 0" class="w-full table-auto border-collapse">
        <thead>
          <tr>
            <th class="text-left text-xs uppercase text-gray-500 pb-1">Chiave</th>
            <th v-for="lang in langs" :key="lang" class="text-left text-xs uppercase text-gray-500 pb-1">{{ lang }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="key in allKeys" :key="key" class="border-t border-gray-100">
            <td class="font-mono text-sm py-1">{{ key }}</td>
            <td v-for="lang in langs" :key="lang" class="text-sm py-1">{{ values[lang]?.[key] ?? '' }}</td>
          </tr>
        </tbody>
      </table>
      <span v-else class="text-gray-400">—</span>
    </div>
  </div>
</template>

<script>
import { collectAllKeys } from '../../../../_shared/resources/js/utils/collectAllKeys.js'

export default {
  props: ['index', 'resource', 'resourceName', 'resourceId', 'field'],

  computed: {
    langs() {
      return (this.field.value && this.field.value.langs) || ['it']
    },

    values() {
      return (this.field.value && this.field.value.values) || {}
    },

    allKeys() {
      return collectAllKeys(this.langs, this.values)
    },
  },
}
</script>
