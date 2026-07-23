<template>
  <div class="flex relative" :style="{ width }">
    <select
      :value="modelValue"
      class="w-full block form-control form-control-bordered form-input"
      style="appearance: none; -webkit-appearance: none; padding-right: 32px"
      @change="$emit('update:modelValue', $event.target.value)"
    >
      <slot />
    </select>
    <span
      style="pointer-events: none; position: absolute; top: 0; bottom: 0; right: 11px; display: flex; align-items: center"
    >
      <svg viewBox="0 0 20 20" fill="currentColor" class="text-gray-700" style="width: 16px; height: 16px">
        <path
          fill-rule="evenodd"
          d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"
          clip-rule="evenodd"
        />
      </svg>
    </span>
  </div>
</template>

<script>
/**
 * Select condiviso tra i Nova Field custom del package. Replica esattamente
 * il markup del componente nativo SelectControl.vue di Nova (stesse classi
 * form-control/form-control-bordered/form-input, freccia custom al posto di
 * quella nativa del browser) — vedi Button.vue per la stessa motivazione.
 */
export default {
  props: {
    modelValue: {
      default: null,
    },
    width: {
      type: String,
      default: '130px',
    },
  },

  emits: ['update:modelValue'],
}
</script>
