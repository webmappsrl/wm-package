<template>
  <card class="flex flex-col p-4">
    <h4 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-3">
      API Links
    </h4>
    <div class="flex flex-col gap-2">
      <a
        v-for="link in links"
        :key="link.label"
        :href="link.url"
        target="_blank"
        rel="noopener noreferrer"
        class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg
               bg-primary-500 text-white hover:bg-primary-600 transition-colors break-all"
      >
        {{ link.label }}
      </a>
    </div>
  </card>
</template>

<script>
export default {
  props: {
    card: { type: Object, required: true },
  },
  computed: {
    links() {
      return this.card.links ?? []
    },
  },
}
</script>
