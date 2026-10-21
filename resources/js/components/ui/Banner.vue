<script setup lang="ts">
withDefaults(
  defineProps<{ tone?: 'neutral' | 'attention' | 'success' | 'danger'; label?: string }>(),
  { tone: 'neutral' },
);

const toneClass = {
  neutral: 'border-divider bg-transparent text-text',
  attention: 'border-warning-border bg-warning-subtle text-warning-text',
  success: 'border-success-border bg-success-subtle text-success-text',
  danger: 'border-danger-border bg-danger-subtle text-danger-text',
} as const;
</script>

<template>
  <div
    :class="['rounded-md border px-4 py-2.5 text-sm', toneClass[tone]]"
    role="status"
    :data-tone="tone"
    data-test="banner"
  >
    <strong v-if="label" class="mr-1 font-semibold">{{ label }}</strong>
    <slot />
  </div>
</template>
