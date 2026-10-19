<script setup lang="ts">
withDefaults(defineProps<{ label?: string }>(), { label: 'Loading' });
</script>

<template>
  <span
    class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"
    role="status"
    :aria-label="label"
    data-test="spinner"
  />
</template>
