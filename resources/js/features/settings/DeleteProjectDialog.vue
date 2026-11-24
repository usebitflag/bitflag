<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Button from '../../components/ui/Button.vue';
import Dialog from '../../components/ui/Dialog.vue';
import TypedConfirmField from './TypedConfirmField.vue';

const props = defineProps<{
  projectName: string;
  projectKey: string;
  action: string;
  graceDays?: number;
  errors?: Record<string, string | undefined>;
}>();

const open = defineModel<boolean>('open', { default: false });
const typed = ref('');
const busy = ref(false);
const matches = computed(() => typed.value === props.projectKey);

watch(open, () => (typed.value = ''));

function schedule(): void {
  busy.value = true;
  router.post(
    props.action,
    { confirm: typed.value },
    { onFinish: () => (busy.value = false), onSuccess: () => (open.value = false) },
  );
}
</script>

<template>
  <Dialog
    v-model:open="open"
    title="Delete project"
    :description="`This applies to ${projectName}.`"
  >
    <div class="flex flex-col gap-3 text-sm" data-test="delete-project">
      <ul class="text-text/70 list-disc pl-5">
        <li>
          It is deleted in {{ graceDays ?? 7 }} days. Until then it is read-only and SDKs keep
          working.
        </li>
        <li>You can restore it any time before then.</li>
        <li>When it is deleted, every key stops working and its data is removed.</li>
      </ul>
      <p v-if="errors?.project" class="text-danger-text">{{ errors.project }}</p>
      <TypedConfirmField v-model="typed" :expected="projectKey" :error="errors?.confirm" />
    </div>
    <template #footer>
      <Button variant="ghost" type="button" @click="open = false">Cancel</Button>
      <Button
        variant="destructive"
        type="button"
        :disabled="!matches"
        :loading="busy"
        data-test="project-delete-confirm"
        @click="schedule"
        >Delete project</Button
      >
    </template>
  </Dialog>
</template>
