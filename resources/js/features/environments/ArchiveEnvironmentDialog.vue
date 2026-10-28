<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Button from '../../components/ui/Button.vue';
import Dialog from '../../components/ui/Dialog.vue';
import TypedConfirmField from '../settings/TypedConfirmField.vue';
import type { EnvironmentRow } from './types';

const props = defineProps<{
  environment: EnvironmentRow;
  action: string;
  errors?: Record<string, string | undefined>;
}>();

const open = defineModel<boolean>('open', { default: false });
const typed = ref('');
const busy = ref(false);
const matches = computed(() => typed.value === props.environment.key);

watch(open, () => (typed.value = ''));

function archive(): void {
  busy.value = true;
  router.post(props.action, { confirm: typed.value }, { onFinish: () => (busy.value = false) });
}
</script>

<template>
  <Dialog
    v-model:open="open"
    title="Archive environment"
    :description="`This applies to ${environment.name}.`"
  >
    <div class="flex flex-col gap-3 text-sm" data-test="archive-environment">
      <ul class="text-text/70 list-disc pl-5">
        <li>Every key of this environment is revoked, and SDKs using them get 401.</li>
        <li>Nothing more can be published to it.</li>
        <li>It disappears from pickers and flag pages, and its history stays readable.</li>
        <li>This cannot be undone.</li>
      </ul>
      <p v-if="errors?.environment" class="text-danger-text" data-test="archive-error">
        {{ errors.environment }}
      </p>
      <TypedConfirmField v-model="typed" :expected="environment.key" :error="errors?.confirm" />
    </div>
    <template #footer>
      <Button variant="ghost" type="button" @click="open = false">Cancel</Button>
      <Button
        variant="destructive"
        type="button"
        :disabled="!matches"
        :loading="busy"
        data-test="archive-confirm"
        @click="archive"
        >Archive environment</Button
      >
    </template>
  </Dialog>
</template>
