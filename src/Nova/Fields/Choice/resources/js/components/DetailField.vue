<template>
  <div
    class="flex flex-col -mx-6 px-6 py-2 space-y-2 md:flex-row @sm/peekable:flex-row @md/modal:flex-row md:py-0 @sm/peekable:py-0 @md/modal:py-0 md:space-y-0 @sm/peekable:space-y-0 @md/modal:space-y-0"
    :dusk="field.attribute">
    <!-- Label a sinistra -->
    <div class="md:w-1/4 @sm/peekable:w-1/4 @md/modal:w-1/4 md:py-3 @sm/peekable:py-3 @md/modal:py-3">
      <h4 class="font-normal @sm/peekable:break-all">
        <span>{{ field.name }}</span>
      </h4>
    </div>

    <!-- Valore a destra -->
    <div
      class="break-all md:w-3/4 @sm/peekable:w-3/4 @md/modal:w-3/4 md:py-3 @sm/peekable:py-3 md/modal:py-3 lg:break-words @md/peekable:break-words @lg/modal:break-words">
      <p v-if="fieldValue" class="flex items-center">
        <!-- Icona SVG -->
        <svg v-if="iconSvg" class="w-5 h-5 mr-2 flex-shrink-0" viewBox="0 0 1024 1024" fill="currentColor">
          <g v-html="iconSvg"></g>
        </svg>
        <!-- Nome dell'icona -->
        <span>{{ fieldValue }}</span>
      </p>
      <p v-else class="flex items-center">
        <span class="text-gray-400">—</span>
      </p>
    </div>
  </div>
</template>

<script>
export default {
  props: ['index', 'resource', 'resourceName', 'resourceId', 'field'],

  data() {
    return {
      iconSvg: null,
      iconsLoaded: false
    }
  },

  computed: {
    fieldValue() {
      return this.field.displayedAs || this.field.value || ''
    }
  },

  mounted() {
    this.loadIconSvg()
  },

  methods: {
    async loadIconSvg() {
      if (!this.fieldValue || this.iconsLoaded) return

      try {
        // Carica le icone tramite API
        const response = await Nova.request().get('/nova-vendor/wm/icon-select/icons')
        const iconsData = response.data

        if (iconsData && iconsData.icons) {
          const icon = iconsData.icons.find(i =>
            i.properties && i.properties.name === this.fieldValue
          )

          if (icon && icon.icon && icon.icon.paths) {
            // Crea l'SVG dai paths
            let svgPaths = ''
            icon.icon.paths.forEach(path => {
              svgPaths += `<path d="${path}"></path>`
            })
            this.iconSvg = svgPaths
          }
        }

        this.iconsLoaded = true
      } catch (error) {
        console.warn('Errore nel caricamento icone:', error)
        this.iconsLoaded = true
      }
    }
  }
}
</script>
