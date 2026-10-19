<script setup lang="ts">
defineProps<{ kicker?: string; title?: string; meta?: string }>();
</script>

<template>
  <section class="bg-surface border-divider rounded-lg border p-5" data-test="card">
    <p v-if="kicker" class="text-muted text-xs font-medium">{{ kicker }}</p>
    <h3 v-if="title" class="font-heading text-base">{{ title }}</h3>
    <div class="mt-2"><slot /></div>
    <p v-if="meta" class="text-muted mt-3 text-xs">{{ meta }}</p>
  </section>
</template>
