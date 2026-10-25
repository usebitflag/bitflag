<script setup lang="ts">
import { PhCaretDown } from '@phosphor-icons/vue';

defineOptions({ inheritAttrs: false });

export interface SelectOption {
  value: string;
  label: string;
}

withDefaults(
  defineProps<{
    options: SelectOption[];
    id?: string;
    invalid?: boolean;
    describedBy?: string;
    mono?: boolean;
  }>(),
  { invalid: false, mono: false },
);

const model = defineModel<string>({ default: '' });
</script>

<template>
  <div class="relative">
    <select
      v-bind="$attrs"
      :id="id"
      v-model="model"
      :aria-invalid="invalid ? 'true' : undefined"
      :aria-describedby="describedBy"
      :class="[
        'bg-surface focus:border-primary h-9 w-full appearance-none rounded-md border pr-8 pl-3 text-[14px] shadow-xs',
        invalid ? 'border-danger' : 'border-control-border',
        mono ? 'font-mono text-xs' : '',
      ]"
      data-test="select"
    >
      <option v-for="option in options" :key="option.value" :value="option.value">
        {{ option.label }}
      </option>
    </select>
    <PhCaretDown
      :size="12"
      class="text-text/55 pointer-events-none absolute top-1/2 right-3 -translate-y-1/2"
      aria-hidden="true"
    />
  </div>
</template>
