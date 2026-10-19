<script setup lang="ts">
withDefaults(
  defineProps<{
    id?: string;
    invalid?: boolean;
    describedBy?: string;
    rows?: number;
    placeholder?: string;
  }>(),
  { invalid: false, rows: 4 },
);

const model = defineModel<string>({ default: '' });
</script>

<template>
  <textarea
    :id="id"
    v-model="model"
    :rows="rows"
    :placeholder="placeholder"
    :aria-invalid="invalid ? 'true' : undefined"
    :aria-describedby="describedBy"
    :class="[
      'bg-surface w-full rounded-md border px-3 py-2 text-sm outline-none',
      invalid ? 'border-danger' : 'border-control-border',
    ]"
    data-test="textarea"
  />
</template>
