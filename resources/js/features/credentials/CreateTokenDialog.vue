<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import Button from '../../components/ui/Button.vue';
import Dialog from '../../components/ui/Dialog.vue';
import Field from '../../components/ui/Field.vue';
import Input from '../../components/ui/Input.vue';
import Select from '../../components/ui/Select.vue';
import { useSecretReveal } from '../../composables/useSecretReveal';
import { ApiError, sendJson } from '../auth/sendJson';
import SecretRevealPanel from './SecretRevealPanel.vue';

const props = defineProps<{
  action: string;
  projectName: string;
  canDraft: boolean;
  canPublish: boolean;
  /** False for a person who signed up with a provider and has not set a password. */
  hasPassword: boolean;
}>();

const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ created: [] }>();

const label = ref('');
const access = ref('draft');
const ttl = ref('90');
const password = ref('');
const errors = ref<Record<string, string>>({});
const busy = ref(false);
const reveal = useSecretReveal();

const accessOptions = computed(() => [
  { value: 'read', label: 'Read only' },
  { value: 'draft', label: 'Read and edit drafts', disabled: !props.canDraft },
  { value: 'publish', label: 'Read, edit and publish', disabled: !props.canPublish },
]);
const expiryOptions = [
  { value: '30', label: '30 days' },
  { value: '90', label: '90 days' },
  { value: '365', label: '1 year' },
  { value: '', label: 'Never' },
];

watch(open, (isOpen) => {
  if (isOpen) {
    label.value = '';
    access.value = props.canDraft ? 'draft' : 'read';
    ttl.value = '90';
    password.value = '';
    errors.value = {};
  } else {
    reveal.clear();
  }
});

async function create(): Promise<void> {
  busy.value = true;
  errors.value = {};
  try {
    const result = await sendJson<{ secret: string }>('POST', props.action, {
      label: label.value,
      access: access.value,
      ttl_days: ttl.value === '' ? null : Number(ttl.value),
      password: password.value,
    });
    reveal.show(result.secret);
    password.value = '';
    emit('created');
  } catch (error) {
    errors.value =
      error instanceof ApiError
        ? {
            ...Object.fromEntries(
              Object.entries(error.fields).map(([field, messages]) => [field, messages[0] ?? '']),
            ),
            form: error.message,
          }
        : { form: 'Something went wrong. Try again.' };
  } finally {
    busy.value = false;
  }
}

function done(): void {
  reveal.clear();
  open.value = false;
}
</script>

<template>
  <Dialog
    v-model:open="open"
    :title="reveal.secret.value === null ? 'New personal token' : 'Copy your token'"
    description="A token can never do more than your role allows. Protected environments still need a review."
    :dismissable="reveal.secret.value === null"
  >
    <div v-if="reveal.secret.value === null" class="flex flex-col gap-4" data-test="create-token">
      <p v-if="!hasPassword" class="text-sm" data-test="token-needs-password">
        Set a password first. Making a token asks for it again.
      </p>
      <p
        v-else-if="
          errors.form && !errors.label && !errors.access && !errors.password && !errors.ttl_days
        "
        class="text-danger-text text-sm"
      >
        {{ errors.form }}
      </p>
      <Field v-slot="{ id, describedBy, invalid }" label="Name" :error="errors.label">
        <Input
          :id="id"
          v-model="label"
          :invalid="invalid"
          :described-by="describedBy"
          placeholder="Laptop CLI"
          data-test="token-label"
        />
      </Field>
      <Field
        v-slot="{ id, describedBy }"
        label="Access"
        :error="errors.access"
        :help="`For ${projectName} only.`"
      >
        <Select
          :id="id"
          v-model="access"
          :options="accessOptions"
          :described-by="describedBy"
          data-test="token-access"
        />
      </Field>
      <Field v-slot="{ id, describedBy }" label="Expires" :error="errors.ttl_days">
        <Select
          :id="id"
          v-model="ttl"
          :options="expiryOptions"
          :described-by="describedBy"
          data-test="token-expiry"
        />
      </Field>
      <Field
        v-slot="{ id, describedBy, invalid }"
        label="Confirm your password"
        :error="errors.password"
      >
        <Input
          :id="id"
          v-model="password"
          type="password"
          autocomplete="current-password"
          :invalid="invalid"
          :described-by="describedBy"
          data-test="token-password"
        />
      </Field>
    </div>
    <SecretRevealPanel
      v-else
      v-model:acknowledged="reveal.acknowledged.value"
      :secret="reveal.secret.value"
      variable="BITFLAG_API_KEY"
      :environment-name="projectName"
      note="Use it with the CLI: bitflag login --token."
      @done="done"
    />
    <template v-if="reveal.secret.value === null" #footer>
      <Button variant="ghost" type="button" @click="open = false">Cancel</Button>
      <Button
        variant="primary"
        type="button"
        :loading="busy"
        :disabled="!hasPassword || label.trim() === '' || password === ''"
        data-test="token-create"
        @click="create"
        >Create token</Button
      >
    </template>
  </Dialog>
</template>
