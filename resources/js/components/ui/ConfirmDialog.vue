<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import Button from './Button.vue';
import Dialog from './Dialog.vue';

const props = withDefaults(
  defineProps<{
    title: string;
    /** The object being acted on; named in the description so the person knows what will change. */
    objectName: string;
    description?: string;
    confirmLabel?: string;
    /** When set, the confirm button stays disabled until this exact text is typed. */
    confirmText?: string;
    destructive?: boolean;
    loading?: boolean;
  }>(),
  { confirmLabel: 'Confirm', destructive: false, loading: false },
);

const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ confirm: []; cancel: [] }>();

const typed = ref('');
const matches = computed(
  () => props.confirmText === undefined || typed.value === props.confirmText,
);

watch(open, (isOpen) => {
  if (!isOpen) typed.value = '';
});

function cancel(): void {
  open.value = false;
  emit('cancel');
}
</script>

<template>
  <Dialog
    v-model:open="open"
    :title="title"
    :description="description ?? `This applies to ${objectName}.`"
  >
    <p class="text-sm" data-test="confirm-object">
      <strong class="font-semibold">{{ objectName }}</strong>
    </p>
    <div v-if="confirmText !== undefined" class="mt-4 flex flex-col gap-1">
      <label for="confirm-text" class="text-sm">
        Type <code class="font-mono text-xs" data-test="confirm-key">{{ confirmText }}</code> to
        confirm
      </label>
      <input
        id="confirm-text"
        v-model="typed"
        autocomplete="off"
        class="border-control-border bg-surface h-9 rounded-md border px-3 font-mono text-xs"
        data-test="confirm-input"
      />
    </div>
    <template #footer>
      <Button data-test="confirm-cancel" @click="cancel">Cancel</Button>
      <Button
        :variant="destructive ? 'destructive' : 'primary'"
        :disabled="!matches"
        :loading="loading"
        data-test="confirm-submit"
        @click="emit('confirm')"
      >
        {{ confirmLabel }}
      </Button>
    </template>
  </Dialog>
</template>
