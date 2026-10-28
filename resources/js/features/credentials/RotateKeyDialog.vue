<script setup lang="ts">
import { ref, watch } from 'vue';
import Button from '../../components/ui/Button.vue';
import Dialog from '../../components/ui/Dialog.vue';
import Select from '../../components/ui/Select.vue';
import { useSecretReveal } from '../../composables/useSecretReveal';
import { ApiError, sendJson } from '../auth/sendJson';
import type { KeyRow } from '../environments/types';
import { KEY_KINDS, OVERLAP_OPTIONS } from './keyKinds';
import SecretRevealPanel from './SecretRevealPanel.vue';

const props = defineProps<{ credential: KeyRow | null; action: string; environmentName: string }>();

const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ rotated: [] }>();

const overlap = ref('86400');
const busy = ref(false);
const error = ref<string | null>(null);
const stopsAt = ref<number | null>(null);
const reveal = useSecretReveal();

watch(open, (isOpen) => {
  if (isOpen) {
    overlap.value = '86400';
    error.value = null;
    stopsAt.value = null;
  } else {
    reveal.clear();
  }
});

async function rotate(): Promise<void> {
  if (props.credential === null) return;
  busy.value = true;
  error.value = null;
  try {
    const result = await sendJson<{ secret: string; oldStopsAt: number }>(
      'POST',
      `${props.action}/${props.credential.id}/rotate`,
      { overlap: Number(overlap.value) },
    );
    reveal.show(result.secret);
    stopsAt.value = result.oldStopsAt;
  } catch (failure) {
    error.value =
      failure instanceof ApiError ? failure.message : 'Something went wrong. Try again.';
  } finally {
    busy.value = false;
  }
}

function done(): void {
  reveal.clear();
  open.value = false;
  emit('rotated');
}

function variable(): string {
  return KEY_KINDS.find((kind) => kind.type === props.credential?.type)?.variable ?? 'BITFLAG_KEY';
}
</script>

<template>
  <Dialog
    v-model:open="open"
    :title="reveal.secret.value === null ? 'Rotate key' : 'Copy your new key'"
    :description="credential ? `${credential.label} · ${credential.masked}` : undefined"
    :dismissable="reveal.secret.value === null"
  >
    <div v-if="reveal.secret.value === null" class="flex flex-col gap-4" data-test="rotate-key">
      <p class="text-text/70 text-sm">
        A new key is made with the same name and permissions. The old key keeps working during the
        overlap so you can deploy the new one without downtime, then it is revoked.
      </p>
      <label class="flex flex-col gap-1 text-sm">
        <span class="text-text/70 font-medium">Keep the old key working for</span>
        <Select v-model="overlap" :options="OVERLAP_OPTIONS" data-test="rotate-overlap" />
      </label>
      <p v-if="error" class="text-danger-text text-sm" data-test="rotate-error">{{ error }}</p>
    </div>
    <SecretRevealPanel
      v-else
      v-model:acknowledged="reveal.acknowledged.value"
      :secret="reveal.secret.value"
      :variable="variable()"
      :environment-name="environmentName"
      :note="`Deploy it before the old key stops working (${stopsAt ? new Date(stopsAt * 1000).toLocaleString() : ''}).`"
      @done="done"
    />
    <template v-if="reveal.secret.value === null" #footer>
      <Button variant="ghost" type="button" @click="open = false">Cancel</Button>
      <Button
        variant="primary"
        type="button"
        :loading="busy"
        data-test="rotate-confirm"
        @click="rotate"
        >Rotate key</Button
      >
    </template>
  </Dialog>
</template>
