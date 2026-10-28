<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Spinner from './Spinner.vue';

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'destructive';

const props = withDefaults(
  defineProps<{
    variant?: ButtonVariant;
    size?: 'sm' | 'md' | 'lg';
    type?: 'button' | 'submit' | 'reset';
    loading?: boolean;
    disabled?: boolean;
    block?: boolean;
    /** Renders a link that looks like the button. */
    href?: string;
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
  primary:
    'bg-primary text-primary-foreground border border-primary shadow-[0_1px_2px_rgb(0_0_0/0.12),inset_0_1px_0_rgb(255_255_255/0.15)]',
  secondary: 'bg-surface text-text border border-control-border shadow-xs hover:bg-hover',
  ghost: 'bg-transparent text-text border border-transparent hover:bg-hover',
  destructive: 'bg-danger-subtle text-danger-text border border-danger-border',
} as const;

const classes = computed(() => [
  'inline-flex items-center justify-center gap-1.5 rounded-md font-medium whitespace-nowrap',
  props.size === 'sm'
    ? 'h-7 px-2.5 text-xs'
    : props.size === 'lg'
      ? 'h-[38px] px-3 text-md'
      : 'h-[34px] px-3 text-md',
  variantClass[props.variant],
  props.block ? 'w-full' : '',
]);
</script>

<template>
  <Link v-if="href" :href="href" :class="classes" :data-variant="variant" data-test="button">
    <slot name="icon" />
    <slot />
  </Link>
  <button
    v-else
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
