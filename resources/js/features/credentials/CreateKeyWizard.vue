<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import Button from '../../components/ui/Button.vue';
import Checkbox from '../../components/ui/Checkbox.vue';
import Dialog from '../../components/ui/Dialog.vue';
import Field from '../../components/ui/Field.vue';
import Input from '../../components/ui/Input.vue';
import Select from '../../components/ui/Select.vue';
import { useSecretReveal } from '../../composables/useSecretReveal';
import { ApiError, sendJson } from '../auth/sendJson';
import type { KeyRow } from '../environments/types';
import { EXPIRY_OPTIONS, KEY_KINDS, type EnvironmentKeyType } from './keyKinds';
import SecretRevealPanel from './SecretRevealPanel.vue';

const props = defineProps<{
  action: string;
  environmentName: string;
  /** Origins browser keys will accept, listed in the note. */
  allowedOrigins: string[];
}>();

const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ created: [KeyRow] }>();

const step = ref<1 | 2 | 3>(1);
const type = ref<EnvironmentKeyType>('server');
const label = ref('');
const scopes = ref<string[]>([]);
const ttl = ref('');
const errors = ref<Record<string, string>>({});
const busy = ref(false);
const reveal = useSecretReveal();

const kind = computed(() => KEY_KINDS.find((item) => item.type === type.value) ?? KEY_KINDS[0]!);

function choose(next: EnvironmentKeyType): void {
  type.value = next;
  scopes.value = [...(KEY_KINDS.find((item) => item.type === next)?.defaults ?? [])];
}

function toggle(scope: string, on: boolean): void {
  scopes.value = on
    ? [...new Set([...scopes.value, scope])]
    : scopes.value.filter((value) => value !== scope);
}

choose('server');

watch(open, (isOpen) => {
  if (isOpen) {
    step.value = 1;
    label.value = '';
    ttl.value = '';
    errors.value = {};
    choose('server');
  } else {
    reveal.clear();
  }
});

async function create(): Promise<void> {
  busy.value = true;
  errors.value = {};
  try {
    const result = await sendJson<{ credential: KeyRow; secret: string }>('POST', props.action, {
      type: type.value,
      label: label.value,
      scopes: scopes.value,
      ttl_days: ttl.value === '' ? null : Number(ttl.value),
    });
    reveal.show(result.secret);
    emit('created', result.credential);
    step.value = 3;
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
    :title="step === 3 ? 'Copy your new key' : 'What will use this key?'"
    :dismissable="step !== 3"
  >
    <div class="flex flex-col gap-4" data-test="create-key-wizard" :data-step="step">
      <ol class="text-text/62 flex gap-3 text-xs" aria-label="Steps">
        <li :class="step === 1 ? 'text-text font-medium' : ''">1 Type</li>
        <li :class="step === 2 ? 'text-text font-medium' : ''">2 Details</li>
        <li :class="step === 3 ? 'text-text font-medium' : ''">3 Copy key</li>
      </ol>

      <div v-if="step === 1" class="flex flex-col gap-2" role="radiogroup" aria-label="Key type">
        <label
          v-for="item in KEY_KINDS"
          :key="item.type"
          :class="[
            'border-control-border flex cursor-pointer flex-col gap-0.5 rounded-md border p-3',
            type === item.type ? 'border-primary bg-selection' : 'hover:bg-hover',
          ]"
        >
          <span class="flex items-center gap-2 text-sm font-medium">
            <input
              type="radio"
              name="key-type"
              class="accent-primary"
              :checked="type === item.type"
              :data-test="`key-type-${item.type}`"
              @change="choose(item.type)"
            />
            {{ item.title }}
          </span>
          <span class="text-text/65 pl-6 text-xs">{{ item.help }}</span>
        </label>
      </div>

      <div v-else-if="step === 2" class="flex flex-col gap-4">
        <p
          v-if="errors.form && !errors.label && !errors.scopes && !errors.ttl_days"
          class="text-danger-text text-sm"
          data-test="key-form-error"
        >
          {{ errors.form }}
        </p>
        <Field v-slot="{ id, describedBy, invalid }" label="Name" :error="errors.label">
          <Input
            :id="id"
            v-model="label"
            :invalid="invalid"
            :described-by="describedBy"
            placeholder="Checkout API"
            data-test="key-label"
          />
        </Field>
        <fieldset class="flex flex-col gap-2">
          <legend class="text-text/70 mb-1 text-sm font-medium">Permissions</legend>
          <Checkbox
            v-for="scope in kind.scopes"
            :key="scope.value"
            :model-value="scopes.includes(scope.value)"
            :label="scope.label"
            :help="scope.help"
            :data-test="`scope-${scope.value}`"
            @update:model-value="toggle(scope.value, $event)"
          />
          <p v-if="errors.scopes" class="text-danger-text text-xs">{{ errors.scopes }}</p>
        </fieldset>
        <Field v-slot="{ id, describedBy }" label="Expires" :error="errors.ttl_days">
          <Select
            :id="id"
            v-model="ttl"
            :options="EXPIRY_OPTIONS"
            :described-by="describedBy"
            data-test="key-expiry"
          />
        </Field>
        <p v-if="type === 'browser'" class="text-text/70 text-sm" data-test="browser-origins-note">
          <template v-if="allowedOrigins.length">
            This key works from {{ allowedOrigins.join(', ') }}.
          </template>
          <template v-else
            >No allowed origins are set yet, so requests with this key are refused until you add
            some.</template
          >
          Origins limit which pages may use the key; they do not hide it.
        </p>
      </div>

      <SecretRevealPanel
        v-else-if="reveal.secret.value !== null"
        v-model:acknowledged="reveal.acknowledged.value"
        :secret="reveal.secret.value"
        :variable="kind.variable"
        :environment-name="environmentName"
        @done="done"
      />
    </div>

    <template v-if="step !== 3" #footer>
      <Button v-if="step === 2" variant="ghost" type="button" @click="step = 1">Back</Button>
      <Button variant="ghost" type="button" @click="open = false">Cancel</Button>
      <Button
        v-if="step === 1"
        variant="primary"
        type="button"
        data-test="key-next"
        @click="step = 2"
        >Continue</Button
      >
      <Button
        v-else
        variant="primary"
        type="button"
        :loading="busy"
        :disabled="label.trim() === '' || scopes.length === 0"
        data-test="key-create"
        @click="create"
        >Create key</Button
      >
    </template>
  </Dialog>
</template>
