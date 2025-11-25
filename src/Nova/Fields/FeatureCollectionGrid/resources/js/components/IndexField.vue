<template>
  <div>
    <p class="text-90">{{ field.name }}</p>
    <p class="text-60 text-sm">{{ __('Feature Collection Grid') }}</p>
  </div>
</template>

<script>
export default {
  props: ['resourceName', 'resourceId', 'field'],
}
</script>


