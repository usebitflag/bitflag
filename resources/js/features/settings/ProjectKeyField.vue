<script setup lang="ts">
import { PhCopy, PhLock } from '@phosphor-icons/vue';
import IconButton from '../../components/ui/IconButton.vue';
import Input from '../../components/ui/Input.vue';

defineProps<{
  id: string;
  describedBy?: string;
  invalid: boolean;
  /** True once SDKs or the CLI use the key; it can no longer change. */
  locked: boolean;
  disabled: boolean;
}>();

const model = defineModel<string>({ required: true });

async function copy(): Promise<void> {
  try {
    await navigator.clipboard.writeText(model.value);
  } catch {
    // Copying needs a secure page; nothing to do when the browser refuses.
  }
}
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <div class="flex items-center gap-2">
      <Input
        :id="id"
        v-model="model"
        mono
        class="flex-1"
        :disabled="disabled || locked"
        :invalid="invalid"
        :described-by="describedBy"
        data-test="project-key"
      />
      <IconButton label="Copy project key" data-test="project-key-copy" @click="copy">
        <PhCopy :size="16" aria-hidden="true" />
      </IconButton>
    </div>
    <p
      v-if="locked"
      class="text-text/62 flex items-center gap-1.5 text-xs"
      data-test="project-key-locked"
    >
      <PhLock :size="12" aria-hidden="true" />The key is fixed once SDKs use it.
    </p>
  </div>
</template>
