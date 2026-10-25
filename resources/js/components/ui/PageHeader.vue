<script setup lang="ts">
defineProps<{ title: string; description?: string }>();
</script>

<template>
  <header class="flex flex-wrap items-start justify-between gap-4" data-test="page-header">
    <div class="min-w-0 flex-1">
      <div class="flex items-center gap-2">
        <h1 class="font-heading text-title" data-test="page-title">{{ title }}</h1>
        <slot name="tags" />
      </div>
      <p v-if="description" class="text-text/65 mt-1.5 max-w-[72ch] text-[14px]">
        {{ description }}
      </p>
      <div v-if="$slots.key" class="mt-1"><slot name="key" /></div>
    </div>
    <div v-if="$slots.actions" class="flex flex-none items-center gap-2">
      <slot name="actions" />
    </div>
  </header>
</template>
