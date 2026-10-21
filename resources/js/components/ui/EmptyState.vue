<script setup lang="ts">
defineProps<{ title: string; message: string }>();
</script>

<template>
  <div class="flex flex-col items-center gap-2 px-6 py-10 text-center" data-test="empty-state">
    <h3 class="font-heading text-base">{{ title }}</h3>
    <p class="text-muted max-w-sm text-sm">{{ message }}</p>
    <div v-if="$slots.actions" class="mt-2 flex gap-2"><slot name="actions" /></div>
  </div>
</template>
