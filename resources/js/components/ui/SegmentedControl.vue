<script setup lang="ts">
import { ToggleGroupItem, ToggleGroupRoot } from 'reka-ui';

export interface SegmentOption {
  value: string;
  label: string;
}

withDefaults(
  defineProps<{ options: SegmentOption[]; label: string; variant?: 'filled' | 'ring' }>(),
  { variant: 'filled' },
);

const model = defineModel<string>({ default: '' });

function select(value: string | undefined): void {
  // A single-choice control never ends up with nothing selected.
  if (value) model.value = value;
}
</script>

<template>
  <ToggleGroupRoot
    type="single"
    :model-value="model"
    :aria-label="label"
    :class="
      variant === 'ring'
        ? 'border-divider bg-text/3 inline-flex overflow-hidden rounded-md border'
        : 'border-control-border bg-hover inline-flex rounded-md border p-0.5'
    "
    data-test="segmented-control"
    @update:model-value="(v) => select(typeof v === 'string' ? v : undefined)"
  >
    <ToggleGroupItem
      v-for="option in options"
      :key="option.value"
      :value="option.value"
      :data-test="`segment-${option.value}`"
      :class="
        variant === 'ring'
          ? 'text-text data-[state=on]:text-primary-ink data-[state=on]:ring-primary border-divider px-3 py-[5px] text-[13px] font-medium first:border-l-0 not-first:border-l data-[state=on]:ring-1 data-[state=on]:ring-inset'
          : 'data-[state=on]:bg-surface data-[state=on]:text-text text-muted h-7 rounded-sm px-3 text-xs font-medium'
      "
    >
      {{ option.label }}
    </ToggleGroupItem>
  </ToggleGroupRoot>
</template>
