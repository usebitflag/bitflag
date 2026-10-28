<script setup lang="ts">
import { useId } from 'vue';

defineProps<{ label: string; help?: string; disabled?: boolean }>();

const model = defineModel<boolean>({ default: false });
const id = useId();
</script>

<template>
  <div class="flex items-start gap-2.5" data-test="checkbox">
    <input
      :id="id"
      v-model="model"
      type="checkbox"
      class="accent-primary mt-[3px] h-[15px] w-[15px]"
      :disabled="disabled"
      :aria-describedby="help ? `${id}-help` : undefined"
    />
    <div class="flex flex-col">
      <label :for="id" class="text-sm">{{ label }}</label>
      <span v-if="help" :id="`${id}-help`" class="text-muted text-xs">{{ help }}</span>
    </div>
  </div>
</template>
