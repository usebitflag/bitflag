<script setup lang="ts">
import { PhCheck, PhCopy } from '@phosphor-icons/vue';
import { onBeforeUnmount, ref } from 'vue';
import Button from '../../components/ui/Button.vue';
import Checkbox from '../../components/ui/Checkbox.vue';

const props = defineProps<{
  /** The secret, shown once. Held by the caller's local ref and cleared when the panel is done. */
  secret: string;
  /** The environment variable the key is usually set in, for example BITFLAG_SERVER_KEY. */
  variable?: string;
  environmentName?: string;
  /** Extra line under the key, such as "Deploy it before the old key stops working". */
  note?: string;
  /** What the secret is called in the acknowledgement: "key" or "token". */
  noun?: string;
  /** A code block under the secret, such as how to use it. */
  snippet?: string;
}>();

const acknowledged = defineModel<boolean>('acknowledged', { default: false });
const emit = defineEmits<{ done: [] }>();

const copied = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

async function copy(): Promise<void> {
  try {
    await navigator.clipboard.writeText(props.secret);
    copied.value = true;
    clearTimeout(timer);
    timer = setTimeout(() => (copied.value = false), 2000);
  } catch {
    // The key stays selectable; copying needs a secure page.
  }
}

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
  <div class="flex flex-col gap-4" data-test="secret-reveal">
    <p class="text-text/70 text-sm" data-test="secret-warning">
      This is the only time it will be shown. We store a hash, so we can't show it again.
    </p>
    <div class="flex items-center gap-2">
      <code
        class="bg-hover border-divider min-w-0 flex-1 rounded-md border px-3 py-2 font-mono text-xs break-all select-all"
        data-test="secret-value"
        >{{ secret }}</code
      >
      <Button type="button" variant="secondary" data-test="secret-copy" @click="copy">
        <template #icon>
          <PhCheck v-if="copied" :size="16" aria-hidden="true" /><PhCopy
            v-else
            :size="16"
            aria-hidden="true"
          />
        </template>
        {{ copied ? 'Copied' : 'Copy' }}
      </Button>
    </div>
    <dl v-if="variable && environmentName" class="grid grid-cols-2 gap-3 text-sm">
      <div>
        <dt class="text-text/62 text-xs">Environment</dt>
        <dd class="font-medium">{{ environmentName }}</dd>
      </div>
      <div>
        <dt class="text-text/62 text-xs">Env variable</dt>
        <dd>
          <code class="font-mono text-xs">{{ variable }}</code>
        </dd>
      </div>
    </dl>
    <pre
      v-if="snippet"
      class="bg-hover border-divider overflow-x-auto rounded-md border px-3 py-2 font-mono text-xs"
      data-test="secret-snippet"
      >{{ snippet }}</pre>
    <p v-if="note" class="text-text/70 text-sm" data-test="secret-note">{{ note }}</p>
    <Checkbox
      v-model="acknowledged"
      :label="`I\'ve stored this ${noun ?? 'key'} somewhere safe`"
      data-test="secret-ack"
    />
    <div class="flex justify-end">
      <Button
        type="button"
        variant="primary"
        :disabled="!acknowledged"
        data-test="secret-done"
        @click="emit('done')"
        >Done</Button
      >
    </div>
  </div>
</template>
