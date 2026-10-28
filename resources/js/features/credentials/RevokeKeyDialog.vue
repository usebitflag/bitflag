<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Button from '../../components/ui/Button.vue';
import Checkbox from '../../components/ui/Checkbox.vue';
import Dialog from '../../components/ui/Dialog.vue';
import type { KeyRow } from '../environments/types';

const props = defineProps<{
  credential: KeyRow | null;
  action: string;
  /** The environment's default maximum snapshot age in seconds, or null for no limit. */
  maxSnapshotAgeSeconds: number | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const understood = ref(false);
const busy = ref(false);

watch(open, () => (understood.value = false));

const cached = computed(() => {
  const seconds = props.maxSnapshotAgeSeconds;
  if (seconds === null) {
    return 'SDKs that already downloaded a snapshot keep serving it until they next reach BitFlag, including after a restart if they use a persistent cache.';
  }
  const label = seconds === 300 ? '5 minutes' : seconds === 3600 ? '1 hour' : '24 hours';
  return `SDKs that already downloaded a snapshot keep serving it for up to ${label}, then return fallback values.`;
});

const lastUsed = computed(() =>
  props.credential?.lastUsedAt
    ? `Last used ${new Date(props.credential.lastUsedAt * 1000).toLocaleString()}.`
    : 'It has not been used yet.',
);

function revoke(): void {
  if (props.credential === null) return;
  busy.value = true;
  router.post(
    `${props.action}/${props.credential.id}/revoke`,
    {},
    {
      preserveScroll: true,
      onFinish: () => (busy.value = false),
      onSuccess: () => (open.value = false),
    },
  );
}
</script>

<template>
  <Dialog
    v-model:open="open"
    title="Revoke key"
    :description="credential ? `${credential.label} · ${credential.masked}` : undefined"
  >
    <div class="flex flex-col gap-3 text-sm" data-test="revoke-key">
      <p>Requests with this key fail with 401 within a minute, usually right away.</p>
      <p class="text-text/70" data-test="revoke-last-used">{{ lastUsed }}</p>
      <p class="text-text/70" data-test="revoke-cache">{{ cached }}</p>
      <Checkbox
        v-model="understood"
        label="I understand this can't be undone"
        data-test="revoke-ack"
      />
    </div>
    <template #footer>
      <Button variant="ghost" type="button" @click="open = false">Cancel</Button>
      <Button
        variant="destructive"
        type="button"
        :disabled="!understood"
        :loading="busy"
        data-test="revoke-confirm"
        @click="revoke"
        >Revoke key</Button
      >
    </template>
  </Dialog>
</template>
