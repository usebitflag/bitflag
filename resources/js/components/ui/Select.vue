<script setup lang="ts">
import { PhCaretDown } from '@phosphor-icons/vue';
import { computed, useAttrs } from 'vue';

defineOptions({ inheritAttrs: false });

export interface SelectOption {
  value: string;
  label: string;
  disabled?: boolean;
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
const attrs = useAttrs();
// A class or style belongs to the whole control, the rest to the select element.
const outer = computed(() => ({ class: attrs.class, style: attrs.style }));
const inner = computed(() =>
  Object.fromEntries(
    Object.entries(attrs).filter(([name]) => name !== 'class' && name !== 'style'),
  ),
);
</script>

<template>
  <div class="relative" v-bind="outer">
    <select
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
      v-bind="inner"
    >
      <option
        v-for="option in options"
        :key="option.value"
        :value="option.value"
        :disabled="option.disabled"
      >
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
