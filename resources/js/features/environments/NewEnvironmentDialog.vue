<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, toRef, watch } from 'vue';
import Button from '../../components/ui/Button.vue';
import Dialog from '../../components/ui/Dialog.vue';
import Field from '../../components/ui/Field.vue';
import Input from '../../components/ui/Input.vue';
import Select from '../../components/ui/Select.vue';
import Switch from '../../components/ui/Switch.vue';
import { useKeySlug } from '../../composables/useKeySlug';
import ColorPicker from './ColorPicker.vue';

export interface EnvironmentOption {
  id: string;
  name: string;
  key: string;
}

const props = defineProps<{
  action: string;
  /** Active environments in order, for the position choice and to find taken keys. */
  environments: EnvironmentOption[];
  takenKeys: readonly string[];
  colors: string[];
  /** False when the plan does not include required approvals, so protection promises no second reviewer. */
  approvalsAvailable: boolean;
  errors?: Record<string, string | undefined>;
}>();

const open = defineModel<boolean>('open', { default: false });

const name = ref('');
const key = ref('');
const color = ref('slate');
const after = ref('');
const isProtected = ref(false);
const busy = ref(false);

const slug = useKeySlug(name, key, toRef(props, 'takenKeys'));
const keyProblem = computed(() => slug.problem());
const keyState = computed(() => {
  if (key.value === '') return undefined;
  return keyProblem.value ?? 'Available';
});

const positions = computed(() => [
  ...props.environments.map((environment) => ({
    value: environment.id,
    label: `After ${environment.name}`,
  })),
  { value: '', label: 'At the end' },
]);

watch(
  () => open.value,
  (value) => {
    if (!value) return;
    name.value = '';
    key.value = '';
    color.value = 'slate';
    after.value = '';
    isProtected.value = false;
    slug.reset();
  },
);

// The last environment is the default position, which is the end of the list.
function submit(): void {
  busy.value = true;
  router.post(
    props.action,
    {
      name: name.value,
      key: key.value,
      color: color.value,
      after: after.value === '' ? null : after.value,
      protected: isProtected.value,
    },
    {
      preserveScroll: true,
      onFinish: () => (busy.value = false),
      onSuccess: () => (open.value = false),
    },
  );
}
</script>

<template>
  <Dialog v-model:open="open" title="New environment">
    <form
      class="flex flex-col gap-4"
      novalidate
      data-test="new-environment-form"
      @submit.prevent="submit"
    >
      <Field v-slot="{ id, describedBy, invalid }" label="Display name" :error="errors?.name">
        <Input
          :id="id"
          v-model="name"
          :invalid="invalid"
          :described-by="describedBy"
          data-test="environment-name"
        />
      </Field>
      <Field
        v-slot="{ id, describedBy, invalid }"
        label="Key"
        :help="keyProblem === null ? keyState : undefined"
        :error="keyProblem ?? errors?.key"
      >
        <Input
          :id="id"
          :model-value="key"
          mono
          :invalid="invalid"
          :described-by="describedBy"
          data-test="environment-key"
          @update:model-value="slug.setKey"
        />
      </Field>
      <Field v-slot="{ id }" label="Position">
        <Select :id="id" v-model="after" :options="positions" data-test="environment-position" />
      </Field>
      <div class="flex flex-col gap-1.5">
        <span class="text-text/70 text-sm font-medium">Label color</span>
        <ColorPicker v-model="color" :colors="colors" />
      </div>
      <Switch
        v-model="isProtected"
        label="Protected environment"
        :help="
          approvalsAvailable
            ? 'Publishing needs a reason and a second reviewer, and only publishers can publish.'
            : 'Publishing needs a reason, and only Owners and Publishers can publish.'
        "
        data-test="environment-protected"
      />
      <p class="text-text/62 text-xs" data-test="environment-note">
        Every flag starts disabled here. The key can be changed until the first publish.
      </p>
    </form>
    <template #footer>
      <Button variant="ghost" type="button" @click="open = false">Cancel</Button>
      <Button
        variant="primary"
        :loading="busy"
        :disabled="name.trim() === '' || key === '' || keyProblem !== null"
        data-test="environment-create"
        @click="submit"
        >Create environment</Button
      >
    </template>
  </Dialog>
</template>
