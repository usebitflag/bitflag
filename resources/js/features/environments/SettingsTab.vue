<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Button from '../../components/ui/Button.vue';
import Card from '../../components/ui/Card.vue';
import Field from '../../components/ui/Field.vue';
import InlineNotice from '../../components/ui/InlineNotice.vue';
import Input from '../../components/ui/Input.vue';
import Select from '../../components/ui/Select.vue';
import { useDirtyForm } from '../../composables/useDirtyForm';
import type { SharedProps } from '../../types/shared';
import ArchiveEnvironmentDialog from './ArchiveEnvironmentDialog.vue';
import ColorPicker from './ColorPicker.vue';
import DeleteEnvironmentDialog from './DeleteEnvironmentDialog.vue';
import OriginListField from './OriginListField.vue';
import ProtectionToggles from './ProtectionToggles.vue';
import type { EnvironmentRow } from './types';

const props = defineProps<{
  environment: EnvironmentRow;
  action: string;
  canManage: boolean;
  approvalsAvailable: boolean;
  colors: string[];
  snapshotAges: number[];
  deleteBlockers: string[];
  activeEnvironmentCount: number;
}>();

const archiving = ref(false);
const deleting = ref(false);

function values() {
  return {
    name: props.environment.name,
    key: props.environment.key,
    color: props.environment.color,
    allowed_origins: [...props.environment.allowedOrigins],
    max_snapshot_age:
      props.environment.defaultMaxSnapshotAgeSeconds === null
        ? ''
        : String(props.environment.defaultMaxSnapshotAgeSeconds),
    protected: props.environment.isProtected,
    required_approvals: props.environment.requiredApprovals,
    version: props.environment.version,
  };
}

const form = useForm(values());
const { dirty, reset } = useDirtyForm(form);
const page = usePage<SharedProps>();
const errors = computed<Record<string, string | undefined>>(() => ({
  ...(page.props.errors ?? {}),
  ...form.errors,
}));
const keyLocked = computed(() => props.environment.firstPublishedAt !== null);
const archived = computed(() => props.environment.status !== 'active');
const disabled = computed(() => !props.canManage || archived.value);

const requireApproval = computed({
  get: () => form.required_approvals > 0,
  set: (value: boolean) => (form.required_approvals = value ? 1 : 0),
});

// Turning protection off clears the reviewer requirement, as the server does.
watch(
  () => form.protected,
  (value) => {
    if (!value) form.required_approvals = 0;
  },
);

watch(
  () => props.environment,
  () => form.defaults(values()).reset(),
);

const ageOptions = computed(() => [
  { value: '', label: 'No limit' },
  ...props.snapshotAges.map((seconds) => ({
    value: String(seconds),
    label: seconds === 300 ? '5 minutes' : seconds === 3600 ? '1 hour' : '24 hours',
  })),
]);

function save(): void {
  form
    .transform((data) => ({
      ...data,
      max_snapshot_age: data.max_snapshot_age === '' ? null : Number(data.max_snapshot_age),
    }))
    .put(props.action);
}
</script>

<template>
  <form
    class="flex max-w-[820px] flex-col gap-4"
    novalidate
    data-test="environment-settings"
    @submit.prevent="save"
  >
    <InlineNotice
      v-if="errors.version"
      tone="warning"
      :title="errors.version"
      data-test="settings-conflict"
    />
    <InlineNotice v-if="errors.environment" tone="danger" :title="errors.environment" />

    <Card title="Environment">
      <div class="flex flex-col gap-4">
        <div class="grid grid-cols-2 items-start gap-3.5">
          <Field v-slot="{ id, describedBy, invalid }" label="Display name" :error="errors.name">
            <Input
              :id="id"
              v-model="form.name"
              :disabled="disabled"
              :invalid="invalid"
              :described-by="describedBy"
              data-test="environment-name"
            />
          </Field>
          <Field
            v-slot="{ id, describedBy, invalid }"
            label="Key"
            :help="
              keyLocked
                ? 'Locked after the first publish.'
                : 'Can be changed until the first publish.'
            "
            :error="errors.key"
          >
            <Input
              :id="id"
              v-model="form.key"
              mono
              :disabled="disabled || keyLocked"
              :invalid="invalid"
              :described-by="describedBy"
              data-test="environment-key"
            />
          </Field>
        </div>
        <div class="flex flex-col gap-1.5">
          <span class="text-text/70 text-sm font-medium">Label color</span>
          <ColorPicker v-model="form.color" :colors="colors" :disabled="disabled" />
        </div>
      </div>
    </Card>

    <Card title="Protection">
      <ProtectionToggles
        v-model:protected="form.protected"
        v-model:require-approval="requireApproval"
        :disabled="disabled"
        :approvals-available="approvalsAvailable"
      />
      <p v-if="errors.required_approvals" class="text-danger-text mt-2 text-xs">
        {{ errors.required_approvals }}
      </p>
    </Card>

    <Card title="Browser origins">
      <OriginListField
        v-model="form.allowed_origins"
        :disabled="disabled"
        :server-error="errors.allowed_origins"
      />
    </Card>

    <Card title="Snapshot age">
      <Field
        v-slot="{ id, describedBy, invalid }"
        label="Default maximum snapshot age"
        help="How long an SDK may serve a snapshot it already downloaded. SDKs can ask for a stricter value."
        :error="errors.max_snapshot_age"
      >
        <Select
          :id="id"
          v-model="form.max_snapshot_age"
          :options="ageOptions"
          :disabled="disabled"
          :invalid="invalid"
          :described-by="describedBy"
          data-test="max-snapshot-age"
        />
      </Field>
    </Card>

    <Card v-if="canManage && !archived" title="Archive or delete">
      <div class="flex flex-col gap-3 text-sm">
        <p class="text-text/70">
          Environments with published revisions or keys can't be deleted. Archive them instead.
        </p>
        <p v-if="activeEnvironmentCount < 2" class="text-text/62" data-test="last-environment">
          This is the project's last active environment, so it can't be archived or deleted.
        </p>
        <div class="flex gap-2">
          <Button
            type="button"
            variant="destructive"
            :disabled="activeEnvironmentCount < 2"
            data-test="archive-open"
            @click="archiving = true"
            >Archive…</Button
          >
          <Button
            type="button"
            variant="destructive"
            :disabled="activeEnvironmentCount < 2"
            data-test="delete-open"
            @click="deleting = true"
            >Delete…</Button
          >
        </div>
      </div>
    </Card>

    <div v-if="!disabled" class="flex items-center gap-3">
      <Button
        type="submit"
        variant="primary"
        :loading="form.processing"
        :disabled="!dirty"
        data-test="settings-save"
        >Save</Button
      >
      <Button
        type="button"
        variant="ghost"
        :disabled="!dirty"
        data-test="settings-reset"
        @click="reset"
        >Reset</Button
      >
      <span v-if="dirty" class="text-text/62 text-sm" data-test="settings-unsaved"
        >Unsaved changes</span
      >
    </div>
    <ArchiveEnvironmentDialog
      v-model:open="archiving"
      :environment="environment"
      :action="`${action.replace(/\/settings$/, '')}/archive`"
      :errors="errors"
    />
    <DeleteEnvironmentDialog
      v-model:open="deleting"
      :environment="environment"
      :action="action.replace(/\/settings$/, '')"
      :blockers="deleteBlockers"
      :errors="errors"
    />
  </form>
</template>
