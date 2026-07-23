<template>
  <button
    type="button"
    class="border text-left appearance-none cursor-pointer rounded text-sm font-bold inline-flex items-center justify-center h-9 px-3"
    :class="variantClasses"
    :disabled="disabled"
    @click="$emit('click', $event)"
  >
    <slot />
  </button>
</template>

<script>
/**
 * Bottone condiviso tra i Nova Field custom del package (non specifico di
 * nessun campo). Le classi replicano esattamente quelle usate dal componente
 * <Button> nativo di Nova (vendor/laravel/nova/resources/ui/components/Button.vue,
 * variante "solid"/"ghost", size "large") — sono quindi garantite presenti
 * nel CSS già compilato di Nova, a differenza di classi Tailwind arbitrarie
 * che Nova potrebbe aver eliminato dal proprio purge. Se in futuro cambia lo
 * stile dei bottoni Nova (colore primario, radius, ecc.) va aggiornato solo
 * questo file, non ogni campo custom che usa un bottone.
 */
const VARIANTS = {
  primary:
    'shadow bg-primary-500 border-primary-500 text-white hover:bg-primary-400 hover:border-primary-400',
  secondary: 'bg-transparent border-transparent text-gray-600 hover:bg-gray-100',
  outline: 'bg-white border-gray-200 text-gray-600 hover:text-primary-500',
  // Stesse classi del bottone "danger" nativo di Nova (useButtonStyles.ts),
  // garantite presenti nel CSS compilato perché Nova le usa nei propri modali di eliminazione.
  danger: 'shadow bg-red-500 border-red-500 text-white hover:bg-red-400 hover:border-red-400',
}

export default {
  props: {
    variant: {
      type: String,
      default: 'primary',
      validator: value => Object.keys(VARIANTS).includes(value),
    },
    disabled: {
      type: Boolean,
      default: false,
    },
  },

  emits: ['click'],

  computed: {
    variantClasses() {
      return VARIANTS[this.variant] || VARIANTS.primary
    },
  },
}
</script>
