<script setup lang="ts">
import { ToggleGroupItem, ToggleGroupRoot } from 'reka-ui';

export interface SegmentOption {
  value: string;
  label: string;
}

defineProps<{ options: SegmentOption[]; label: string }>();

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
    class="border-control-border bg-hover inline-flex rounded-md border p-0.5"
    data-test="segmented-control"
    @update:model-value="(v) => select(typeof v === 'string' ? v : undefined)"
  >
    <ToggleGroupItem
      v-for="option in options"
      :key="option.value"
      :value="option.value"
      class="data-[state=on]:bg-surface data-[state=on]:text-text text-muted h-7 rounded-sm px-3 text-xs font-medium"
    >
      {{ option.label }}
    </ToggleGroupItem>
  </ToggleGroupRoot>
</template>
