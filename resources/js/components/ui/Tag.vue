<script setup lang="ts">
export type TagVariant = 'accent' | 'neutral' | 'outline' | 'success' | 'warning' | 'danger';

withDefaults(defineProps<{ variant?: TagVariant; dot?: boolean; mono?: boolean }>(), {
  variant: 'neutral',
  dot: false,
  mono: false,
});

const variantClass: Record<TagVariant, string> = {
  accent: 'bg-primary-soft text-primary-ink border-transparent',
  neutral: 'bg-hover text-text border-transparent',
  outline: 'bg-transparent text-text border-divider',
  success: 'bg-success-subtle text-success-text border-success-border',
  warning: 'bg-warning-subtle text-warning-text border-warning-border',
  danger: 'bg-danger-subtle text-danger-text border-danger-border',
};
</script>

<template>
  <span
    :class="[
      'inline-flex h-[22px] items-center gap-1.5 rounded-sm border px-2 text-xs font-medium whitespace-nowrap',
      variantClass[variant],
      mono ? 'font-mono' : '',
    ]"
    :data-variant="variant"
    data-test="tag"
  >
    <span
      v-if="dot"
      class="h-1.5 w-1.5 rounded-full bg-current"
      aria-hidden="true"
      data-test="tag-dot"
    />
    <slot />
  </span>
</template>
