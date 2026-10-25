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
    autocomplete?: string;
    name?: string;
  }>(),
  { type: 'text', invalid: false, mono: false, disabled: false },
);

const model = defineModel<string>({ default: '' });
</script>

<template>
  <div
    :class="[
      'bg-surface focus-within:border-primary flex h-9 items-center rounded-md border px-2.5 text-[14px] shadow-xs focus-within:shadow-[0_0_0_3px_color-mix(in_srgb,var(--bf-primary)_18%,transparent)]',
      invalid ? 'border-danger' : 'border-control-border',
    ]"
  >
    <span v-if="$slots.prefix" class="text-text/55"><slot name="prefix" /></span>
    <input
      :id="id"
      v-model="model"
      :type="type"
      :placeholder="placeholder"
      :disabled="disabled"
      :autocomplete="autocomplete"
      :name="name"
      :aria-invalid="invalid ? 'true' : undefined"
      :aria-describedby="describedBy"
      :class="[
        'placeholder:text-muted w-full bg-transparent outline-none',
        mono ? 'font-mono text-[13px]' : '',
      ]"
      data-test="input"
    />
    <span v-if="$slots.suffix" class="text-muted ml-2"><slot name="suffix" /></span>
  </div>
</template>
