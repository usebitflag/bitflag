<script setup lang="ts">
import { ref } from 'vue';
import { PhCheck, PhCopy } from '@phosphor-icons/vue';

const props = defineProps<{ value: string }>();
const copied = ref(false);

async function copy(): Promise<void> {
  try {
    await navigator.clipboard.writeText(props.value);
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
  } catch {
    // Clipboard access can be blocked; the key stays visible to copy by hand.
  }
}
</script>

<template>
  <span class="bg-hover inline-flex items-center gap-2 rounded-sm px-2 py-0.5" data-test="key-tag">
    <code class="font-mono text-xs" data-test="key-tag-value">{{ value }}</code>
    <button
      type="button"
      class="text-muted hover:text-text inline-grid h-6 w-6 place-items-center rounded-sm"
      :aria-label="copied ? 'Copied' : 'Copy key'"
      data-test="key-tag-copy"
      @click="copy"
    >
      <PhCheck v-if="copied" :size="14" />
      <PhCopy v-else :size="14" />
    </button>
  </span>
</template>
