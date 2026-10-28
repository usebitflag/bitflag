<script setup lang="ts">
import { PhCopy, PhDownloadSimple } from '@phosphor-icons/vue';
import { ref } from 'vue';
import Button from '../../components/ui/Button.vue';
import Checkbox from '../../components/ui/Checkbox.vue';

const props = defineProps<{ codes: string[] }>();
const emit = defineEmits<{ acknowledged: [value: boolean] }>();

const copied = ref(false);
const stored = ref(false);

async function copy(): Promise<void> {
  try {
    await navigator.clipboard.writeText(props.codes.join('\n'));
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
  } catch {
    copied.value = false;
  }
}

function download(): void {
  const url = URL.createObjectURL(
    new Blob([`BitFlag recovery codes\n\n${props.codes.join('\n')}\n`], { type: 'text/plain' }),
  );
  const link = document.createElement('a');
  link.href = url;
  link.download = 'bitflag-recovery-codes.txt';
  link.click();
  URL.revokeObjectURL(url);
}

function onAcknowledge(value: boolean): void {
  stored.value = value;
  emit('acknowledged', value);
}
</script>

<template>
  <div class="flex flex-col gap-3" data-test="recovery-codes-panel">
    <p class="text-sm">
      Keep these codes somewhere safe. Each works once, and this is the only time we show them.
    </p>
    <ul
      class="bg-hover border-divider grid grid-cols-2 gap-2 rounded-md border p-3 font-mono text-sm"
      aria-label="Recovery codes"
    >
      <li v-for="code in codes" :key="code" data-test="recovery-code">{{ code }}</li>
    </ul>
    <div class="flex gap-2">
      <Button size="sm" data-test="recovery-copy" @click="copy">
        <PhCopy :size="14" aria-hidden="true" />
        {{ copied ? 'Copied' : 'Copy' }}
      </Button>
      <Button size="sm" data-test="recovery-download" @click="download">
        <PhDownloadSimple :size="14" aria-hidden="true" />
        Download .txt
      </Button>
    </div>
    <Checkbox
      :model-value="stored"
      label="I've stored these codes"
      data-test="recovery-ack"
      @update:model-value="onAcknowledge"
    />
  </div>
</template>
