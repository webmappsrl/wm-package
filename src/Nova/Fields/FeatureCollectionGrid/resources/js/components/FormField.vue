<template>
  <div>
    <h3 class="text-90 font-normal text-xl mb-4">{{ field.name }}</h3>
    <div class="bg-white rounded-lg shadow p-4">
      <p class="text-60 text-sm mb-2">{{ __('Feature Collection Grid - Edit Mode') }}</p>
      <p class="text-60 text-xs">
        {{ __('Resource') }}: {{ resourceName }} (ID: {{ resourceId }})
      </p>
      <p class="text-60 text-xs mt-2">
        {{ __('GeoJSON Source') }}: {{ field.geojsonSource }}
      </p>
      <p class="text-60 text-xs mt-2">
        {{ __('Relations to Sync') }}: {{ field.relationsToSync?.join(', ') || 'None' }}
      </p>
    </div>
  </div>
</template>

<script>
export default {
  props: ['resourceName', 'resourceId', 'field'],
}
</script>


