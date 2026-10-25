<script setup lang="ts">
defineProps<{ kicker?: string; title?: string; meta?: string }>();
</script>

<template>
  <section
    class="bg-surface border-divider flex flex-col gap-4 rounded-lg border p-[18px]"
    data-test="card"
  >
    <div v-if="kicker || title">
      <p v-if="kicker" class="text-muted text-xs font-medium">{{ kicker }}</p>
      <h3 v-if="title" class="font-heading text-[15px] leading-snug font-medium">{{ title }}</h3>
    </div>
    <div><slot /></div>
    <p v-if="meta" class="text-text/55 text-xs">{{ meta }}</p>
  </section>
</template>
