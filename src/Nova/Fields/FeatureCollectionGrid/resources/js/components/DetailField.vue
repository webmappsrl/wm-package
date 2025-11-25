<template>
  <div>
    <h3 class="text-90 font-normal text-xl mb-4">{{ field.name }}</h3>
    <div class="bg-white rounded-lg shadow" style="min-height: 400px; position: relative;">
      <iframe :src="widgetUrl" style="width: 100%; height: 500px; border: none; border-radius: 4px;" frameborder="0"
        allowfullscreen>
      </iframe>
    </div>
  </div>
</template>

<script>
export default {
  props: ['resourceName', 'resourceId', 'field'],
  computed: {
    widgetUrl() {
      const baseUrl = `/nova-vendor/feature-collection-grid/widget/${this.resourceName}/${this.resourceId}`;
      const geojsonSource = this.field?.geojsonSource;
      if (geojsonSource) {
        return `${baseUrl}?geojson_source=${encodeURIComponent(geojsonSource)}`;
      }
      return baseUrl;
    }
  }
}
</script>
