<script setup lang="ts">
defineProps<{ environmentKey: string }>();
</script>

<template>
  <span class="inline-flex items-center gap-2 text-sm" data-test="env-label">
    <span class="bg-primary h-2 w-2 rounded-full" aria-hidden="true" />
    <code class="font-mono text-xs">{{ environmentKey }}</code>
  </span>
</template>
