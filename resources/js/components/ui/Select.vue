<script setup lang="ts">
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
  <select
    :id="id"
    v-model="model"
    :aria-invalid="invalid ? 'true' : undefined"
    :aria-describedby="describedBy"
    :class="[
      'bg-surface h-9 w-full rounded-md border px-3 text-sm',
      invalid ? 'border-danger' : 'border-control-border',
      mono ? 'font-mono text-xs' : '',
    ]"
    data-test="select"
  >
    <option v-for="option in options" :key="option.value" :value="option.value">
      {{ option.label }}
    </option>
  </select>
</template>
