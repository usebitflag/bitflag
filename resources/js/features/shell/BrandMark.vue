<script setup lang="ts">
// The BitFlag mark: a flagpole with six bits for a flag, the last one off. Colors come from the theme.
withDefaults(defineProps<{ size?: number }>(), { size: 18 });
</script>

<template>
  <svg viewBox="0 0 24 24" :width="size" :height="size" aria-hidden="true" data-test="brand-mark">
    <rect x="3" y="2" width="2" height="20" rx="1" fill="var(--bf-text)" />
    <rect x="7" y="3" width="4" height="4" rx="1" fill="var(--bf-primary)" />
    <rect x="12" y="3" width="4" height="4" rx="1" fill="var(--bf-primary)" />
    <rect x="17" y="3" width="4" height="4" rx="1" fill="var(--bf-primary)" />
    <rect x="7" y="8" width="4" height="4" rx="1" fill="var(--bf-primary)" />
    <rect x="12" y="8" width="4" height="4" rx="1" fill="var(--bf-primary)" />
    <rect
      x="17.5"
      y="8.5"
      width="3"
      height="3"
      rx="0.75"
      fill="none"
      stroke="var(--bf-primary)"
      stroke-width="1"
    />
  </svg>
</template>
