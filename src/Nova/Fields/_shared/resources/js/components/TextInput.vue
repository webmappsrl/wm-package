<template>
  <input
    type="text"
    class="w-full form-control form-input form-control-bordered"
    :value="modelValue"
    :placeholder="placeholder"
    :readonly="readonly"
    @input="$emit('update:modelValue', $event.target.value)"
  />
</template>

<script>
/**
 * Input di testo condiviso tra i Nova Field custom del package — stesse
 * classi native Nova (form-control/form-input/form-control-bordered) usate
 * ovunque nel package, centralizzate qui invece che ripetute in ogni campo
 * custom. Vedi Button.vue/SelectInput.vue per la stessa motivazione.
 */
export default {
  props: {
    modelValue: {
      type: String,
      default: '',
    },
    placeholder: {
      type: String,
      default: '',
    },
    readonly: {
      type: Boolean,
      default: false,
    },
  },

  emits: ['update:modelValue'],
}
</script>
