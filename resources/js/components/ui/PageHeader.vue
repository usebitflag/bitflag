<script setup lang="ts">
defineProps<{ title: string; description?: string }>();
</script>

<template>
  <header class="flex flex-wrap items-start justify-between gap-4" data-test="page-header">
    <div class="flex flex-col gap-1">
      <div class="flex items-center gap-2">
        <h1 class="font-heading text-title" data-test="page-title">{{ title }}</h1>
        <slot name="tags" />
      </div>
      <p v-if="description" class="text-muted text-sm">{{ description }}</p>
      <div v-if="$slots.key" class="mt-1"><slot name="key" /></div>
    </div>
    <div v-if="$slots.actions" class="flex items-center gap-2"><slot name="actions" /></div>
  </header>
</template>
