<script setup lang="ts">
import { computed, useId } from 'vue';

const props = defineProps<{ label: string; help?: string; error?: string; required?: boolean }>();

const id = useId();
const helpId = computed(() => (props.help ? `${id}-help` : undefined));
const errorId = computed(() => (props.error ? `${id}-error` : undefined));
const describedBy = computed(
  () => [errorId.value, helpId.value].filter(Boolean).join(' ') || undefined,
);
</script>

<template>
  <div class="flex flex-col gap-[5px]" data-test="field">
    <div v-if="$slots.aside" class="flex items-baseline justify-between">
      <label :for="id" class="text-text/70 text-sm font-medium">{{ label }}</label>
      <slot name="aside" />
    </div>
    <label v-else :for="id" class="text-text/70 text-sm font-medium">
      {{ label }}<span v-if="required" class="text-muted" aria-hidden="true"> *</span>
    </label>
    <slot :id="id" :described-by="describedBy" :invalid="Boolean(error)" />
    <p v-if="help" :id="helpId" class="text-text/58 mt-0.5 text-xs" data-test="field-help">
      {{ help }}
    </p>
    <p v-if="error" :id="errorId" class="text-danger-text text-xs" data-test="field-error">
      {{ error }}
    </p>
  </div>
</template>
