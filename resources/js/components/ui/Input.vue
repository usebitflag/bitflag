<script setup lang="ts">
withDefaults(
  defineProps<{
    id?: string;
    type?: string;
    invalid?: boolean;
    mono?: boolean;
    describedBy?: string;
    placeholder?: string;
    disabled?: boolean;
  }>(),
  { type: 'text', invalid: false, mono: false, disabled: false },
);

const model = defineModel<string>({ default: '' });
</script>

<template>
  <div
    :class="[
      'bg-surface flex h-9 items-center gap-2 rounded-md border px-3 text-sm',
      invalid ? 'border-danger' : 'border-control-border',
    ]"
  >
    <span v-if="$slots.prefix" class="text-muted"><slot name="prefix" /></span>
    <input
      :id="id"
      v-model="model"
      :type="type"
      :placeholder="placeholder"
      :disabled="disabled"
      :aria-invalid="invalid ? 'true' : undefined"
      :aria-describedby="describedBy"
      :class="[
        'placeholder:text-muted w-full bg-transparent outline-none',
        mono ? 'font-mono text-xs' : '',
      ]"
      data-test="input"
    />
    <span v-if="$slots.suffix" class="text-muted"><slot name="suffix" /></span>
  </div>
</template>
