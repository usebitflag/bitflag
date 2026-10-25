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
      'bg-surface focus:border-primary w-full rounded-md border px-3 py-2 text-[14px] shadow-xs outline-none focus:shadow-[0_0_0_3px_color-mix(in_srgb,var(--bf-primary)_18%,transparent)]',
      invalid ? 'border-danger' : 'border-control-border',
    ]"
    data-test="textarea"
  />
</template>
