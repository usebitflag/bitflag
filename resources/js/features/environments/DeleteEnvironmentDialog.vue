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
  /** What keeps the environment from being deleted; empty when it can go. */
  blockers: string[];
  errors?: Record<string, string | undefined>;
}>();

const open = defineModel<boolean>('open', { default: false });
const typed = ref('');
const busy = ref(false);
const blocked = computed(() => props.blockers.length > 0);
const matches = computed(() => typed.value === props.environment.key);

watch(open, () => (typed.value = ''));

function remove(): void {
  busy.value = true;
  router.delete(props.action, {
    data: { confirm: typed.value },
    onFinish: () => (busy.value = false),
  });
}
</script>

<template>
  <Dialog
    v-model:open="open"
    title="Delete environment"
    :description="`This applies to ${environment.name}.`"
  >
    <div v-if="blocked" class="flex flex-col gap-3 text-sm" data-test="delete-blockers">
      <p>This environment can't be deleted because it has:</p>
      <ul class="list-disc pl-5">
        <li v-for="blocker in blockers" :key="blocker" data-test="delete-blocker">{{ blocker }}</li>
      </ul>
      <p class="text-text/70">Archive it instead. Archived environments keep their history.</p>
    </div>
    <div v-else class="flex flex-col gap-3 text-sm" data-test="delete-environment">
      <p class="text-text/70">
        The environment, its protection settings and its empty drafts are removed. It was never
        published and has no keys.
      </p>
      <p v-if="errors?.environment" class="text-danger-text">{{ errors.environment }}</p>
      <TypedConfirmField v-model="typed" :expected="environment.key" :error="errors?.confirm" />
    </div>
    <template #footer>
      <Button variant="ghost" type="button" @click="open = false">{{
        blocked ? 'Close' : 'Cancel'
      }}</Button>
      <Button
        v-if="!blocked"
        variant="destructive"
        type="button"
        :disabled="!matches"
        :loading="busy"
        data-test="delete-confirm"
        @click="remove"
        >Delete environment</Button
      >
    </template>
  </Dialog>
</template>
