<script setup lang="ts">
import { PhInfo, PhWarning, PhWarningOctagon } from '@phosphor-icons/vue';

withDefaults(
  defineProps<{
    tone?: 'info' | 'warning' | 'danger';
    title: string;
    plain?: boolean;
    /** Sets the title in regular weight, for a sentence rather than a heading. */
    regular?: boolean;
  }>(),
  { tone: 'info', plain: false, regular: false },
);

const toneClass = {
  info: 'border-divider bg-hover text-text',
  warning: 'border-warning-border bg-warning-subtle text-warning-text',
  danger: 'border-danger-border bg-danger-subtle text-danger-text',
} as const;
</script>

<template>
  <div
    :class="[
      'flex rounded-md border text-sm',
      plain
        ? 'text-text border-control-border gap-2.5 px-3.5 py-[11px]'
        : ['gap-2 p-3', toneClass[tone]],
    ]"
    :role="tone === 'danger' ? 'alert' : 'status'"
    data-test="inline-notice"
  >
    <PhWarningOctagon v-if="tone === 'danger'" :size="16" aria-hidden="true" />
    <PhWarning v-else-if="tone === 'warning'" :size="16" aria-hidden="true" />
    <PhInfo v-else :size="16" aria-hidden="true" :class="plain ? 'mt-px shrink-0' : ''" />
    <div class="min-w-0 flex-1">
      <p :class="plain || regular ? '' : 'font-medium'" data-test="notice-title">{{ title }}</p>
      <div class="text-xs"><slot /></div>
    </div>
  </div>
</template>
