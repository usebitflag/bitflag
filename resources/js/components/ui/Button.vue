<script setup lang="ts">
import { computed } from 'vue';
import Spinner from './Spinner.vue';

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'destructive';

const props = withDefaults(
  defineProps<{
    variant?: ButtonVariant;
    size?: 'sm' | 'md';
    type?: 'button' | 'submit' | 'reset';
    loading?: boolean;
    disabled?: boolean;
    block?: boolean;
  }>(),
  {
    variant: 'secondary',
    size: 'md',
    type: 'button',
    loading: false,
    disabled: false,
    block: false,
  },
);

const variantClass = {
  primary: 'bg-primary text-primary-foreground border border-primary',
  secondary: 'bg-surface text-text border border-control-border hover:bg-hover',
  ghost: 'bg-transparent text-text border border-transparent hover:bg-hover',
  destructive: 'bg-danger-subtle text-danger-text border border-danger-border',
} as const;

const classes = computed(() => [
  'inline-flex items-center justify-center gap-1.5 rounded-md font-medium whitespace-nowrap',
  props.size === 'sm' ? 'h-7 px-2.5 text-xs' : 'h-[34px] px-3 text-md',
  variantClass[props.variant],
  props.block ? 'w-full' : '',
]);
</script>

<template>
  <button
    :type="type"
    :class="classes"
    :disabled="disabled || loading"
    :aria-busy="loading ? 'true' : undefined"
    :data-variant="variant"
    data-test="button"
  >
    <Spinner v-if="loading" label="Working" />
    <slot v-else name="icon" />
    <slot />
  </button>
</template>
