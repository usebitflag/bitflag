<script setup lang="ts">
import { RadioGroupIndicator, RadioGroupItem, RadioGroupRoot } from 'reka-ui';

export interface RadioOption {
  value: string;
  label: string;
  help?: string;
}

defineProps<{ options: RadioOption[]; label: string }>();

const model = defineModel<string>({ default: '' });
</script>

<template>
  <RadioGroupRoot
    v-model="model"
    :aria-label="label"
    class="flex flex-col gap-2"
    data-test="radio-group"
  >
    <div v-for="option in options" :key="option.value" class="flex items-start gap-2">
      <RadioGroupItem
        :id="`radio-${option.value}`"
        :value="option.value"
        class="border-control-border mt-0.5 grid h-4 w-4 place-items-center rounded-full border"
      >
        <RadioGroupIndicator class="bg-primary block h-2 w-2 rounded-full" />
      </RadioGroupItem>
      <div class="flex flex-col">
        <label :for="`radio-${option.value}`" class="text-sm">{{ option.label }}</label>
        <span v-if="option.help" class="text-muted text-xs">{{ option.help }}</span>
      </div>
    </div>
  </RadioGroupRoot>
</template>
