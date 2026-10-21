<script setup lang="ts">
defineProps<{ label: string; disabled?: boolean; type?: 'button' | 'submit' }>();
</script>

<template>
  <button
    :type="type ?? 'button'"
    :aria-label="label"
    :title="label"
    :disabled="disabled"
    class="text-muted hover:bg-hover hover:text-text inline-grid h-[34px] w-[34px] place-items-center rounded-md"
    data-test="icon-button"
  >
    <slot />
  </button>
</template>
