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
      class="border-control-border data-[state=checked]:bg-selection data-[state=checked]:border-primary bg-hover relative mt-px h-5 w-[34px] shrink-0 rounded-[10px] border"
      data-test="switch-root"
    >
      <SwitchThumb
        class="bg-muted data-[state=checked]:bg-primary block h-3 w-3 translate-x-[3px] rounded-full transition-transform data-[state=checked]:translate-x-[17px]"
      />
    </SwitchRoot>
    <div class="flex flex-col">
      <label :for="id" class="text-[14px]">{{ label }}</label>
      <span v-if="help" :id="`${id}-help`" class="text-text/62 text-[12.5px]">{{ help }}</span>
    </div>
  </div>
</template>
