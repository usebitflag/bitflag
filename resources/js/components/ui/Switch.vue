<script setup lang="ts">
import { SwitchRoot, SwitchThumb } from 'reka-ui';
import { useId } from 'vue';

defineProps<{ label: string; help?: string; disabled?: boolean }>();

const model = defineModel<boolean>({ default: false });
const id = useId();
</script>

<template>
  <div class="flex items-start gap-3" data-test="switch">
    <SwitchRoot
      :id="id"
      v-model="model"
      :disabled="disabled"
      :aria-describedby="help ? `${id}-help` : undefined"
      class="border-control-border data-[state=checked]:bg-primary bg-hover relative mt-0.5 h-5 w-9 shrink-0 rounded-full border"
      data-test="switch-root"
    >
      <SwitchThumb
        class="bg-surface border-control-border block h-4 w-4 translate-x-0.5 rounded-full border transition-transform data-[state=checked]:translate-x-[18px]"
      />
    </SwitchRoot>
    <div class="flex flex-col">
      <label :for="id" class="text-sm">{{ label }}</label>
      <span v-if="help" :id="`${id}-help`" class="text-muted text-xs">{{ help }}</span>
    </div>
  </div>
</template>
