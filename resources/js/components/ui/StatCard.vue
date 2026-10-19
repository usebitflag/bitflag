<script setup lang="ts">
defineProps<{
  label: string;
  value: string;
  delta?: string;
  baseline?: string;
  href?: string;
  unavailable?: boolean;
}>();
</script>

<template>
  <div
    class="bg-surface border-divider flex flex-col gap-1.5 rounded-lg border p-5"
    data-test="stat-card"
  >
    <span class="text-muted text-sm">{{ label }}</span>
    <span v-if="unavailable" class="text-muted text-sm" data-test="stat-unavailable"
      >Not collected</span
    >
    <span v-else class="tnum text-xl font-semibold" data-test="stat-value">{{ value }}</span>
    <span v-if="delta || baseline" class="text-muted tnum text-xs">
      <span v-if="delta" data-test="stat-delta">{{ delta }}</span>
      <span v-if="baseline"> {{ baseline }}</span>
    </span>
    <a v-if="href" :href="href" class="text-primary-ink text-xs underline"
      ><slot name="link">View</slot></a
    >
  </div>
</template>
