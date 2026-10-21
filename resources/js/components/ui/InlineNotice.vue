<script setup lang="ts">
import { PhInfo, PhWarning, PhWarningOctagon } from '@phosphor-icons/vue';

withDefaults(defineProps<{ tone?: 'info' | 'warning' | 'danger'; title: string }>(), {
  tone: 'info',
});

const toneClass = {
  info: 'border-divider bg-hover text-text',
  warning: 'border-warning-border bg-warning-subtle text-warning-text',
  danger: 'border-danger-border bg-danger-subtle text-danger-text',
} as const;
</script>

<template>
  <div
    :class="['flex gap-2 rounded-md border p-3 text-sm', toneClass[tone]]"
    :role="tone === 'danger' ? 'alert' : 'status'"
    data-test="inline-notice"
  >
    <PhWarningOctagon v-if="tone === 'danger'" :size="16" aria-hidden="true" />
    <PhWarning v-else-if="tone === 'warning'" :size="16" aria-hidden="true" />
    <PhInfo v-else :size="16" aria-hidden="true" />
    <div>
      <p class="font-medium" data-test="notice-title">{{ title }}</p>
      <div class="text-xs"><slot /></div>
    </div>
  </div>
</template>
