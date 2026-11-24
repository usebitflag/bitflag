<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import Button from '../../../components/ui/Button.vue';
import Card from '../../../components/ui/Card.vue';
import Field from '../../../components/ui/Field.vue';
import InlineNotice from '../../../components/ui/InlineNotice.vue';
import Input from '../../../components/ui/Input.vue';
import Select from '../../../components/ui/Select.vue';
import { useDirtyForm } from '../../../composables/useDirtyForm';
import NewFlagDefaults from '../../../features/settings/NewFlagDefaults.vue';
import ProjectKeyField from '../../../features/settings/ProjectKeyField.vue';
import ProjectSettingsLayout from '../../../layouts/ProjectSettingsLayout.vue';
import { usePage } from '@inertiajs/vue3';
import type { SharedProps } from '../../../types/shared';

const props = defineProps<{
  settings: {
    name: string;
    key: string;
    keyLocked: boolean;
    description: string | null;
    timeZone: string;
    flagKeyFormat: string;
    staleAfterDays: number;
    requireFlagDescription: boolean;
    requireFlagOwner: boolean;
    clientVisibleByDefault: boolean;
    version: number;
  };
  canManage: boolean;
  timeZones: string[];
  staleAfterOptions: number[];
  flagKeyFormats: string[];
}>();

function values() {
  return {
    name: props.settings.name,
    key: props.settings.key,
    description: props.settings.description ?? '',
    time_zone: props.settings.timeZone,
    flag_key_format: props.settings.flagKeyFormat,
    stale_after_days: String(props.settings.staleAfterDays),
    require_flag_description: props.settings.requireFlagDescription,
    require_flag_owner: props.settings.requireFlagOwner,
    client_visible_by_default: props.settings.clientVisibleByDefault,
    version: props.settings.version,
  };
}

const form = useForm(values());
const { dirty, reset } = useDirtyForm(form);
const page = usePage<SharedProps>();
const errors = computed<Record<string, string | undefined>>(() => ({
  ...(page.props.errors ?? {}),
  ...form.errors,
}));

// A save redirects back here with the new values and version; make them the form's baseline.
watch(
  () => props.settings,
  () => form.defaults(values()).reset(),
);

// "Asia/Manila (UTC+8)": the offset the zone has today, so the list reads like the rest of the product.
function zoneLabel(zone: string): string {
  if (zone === 'UTC') return 'UTC';
  try {
    const part = new Intl.DateTimeFormat('en', { timeZone: zone, timeZoneName: 'shortOffset' })
      .formatToParts(new Date())
      .find((item) => item.type === 'timeZoneName')?.value;
    return part ? `${zone} (${part.replace('GMT', 'UTC')})` : zone;
  } catch {
    return zone;
  }
}

const zones = computed(() =>
  props.timeZones.map((zone) => ({ value: zone, label: zoneLabel(zone) })),
);

function save(): void {
  form
    .transform((data) => ({ ...data, stale_after_days: Number(data.stale_after_days) }))
    .put(window.location.pathname);
}
</script>

<template>
  <Head title="Project settings" />
  <ProjectSettingsLayout
    tab="general"
    title="General"
    description="Project details, and the defaults every new flag starts with."
    :can-manage="canManage"
  >
    <form class="flex max-w-[820px] flex-col gap-4" novalidate @submit.prevent="save">
      <InlineNotice
        v-if="errors.version"
        tone="warning"
        :title="errors.version"
        data-test="general-conflict"
      />
      <Card title="Project">
        <div class="flex flex-col gap-4">
          <div class="grid grid-cols-2 items-start gap-3.5">
            <Field v-slot="{ id, describedBy, invalid }" label="Name" :error="errors.name">
              <Input
                :id="id"
                v-model="form.name"
                :disabled="!canManage"
                :invalid="invalid"
                :described-by="describedBy"
                data-test="project-name"
              />
            </Field>
            <Field
              v-slot="{ id, describedBy, invalid }"
              label="Project key · used by SDKs and the CLI"
              :error="errors.key"
            >
              <ProjectKeyField
                :id="id"
                v-model="form.key"
                :described-by="describedBy"
                :invalid="invalid"
                :locked="settings.keyLocked"
                :disabled="!canManage"
              />
            </Field>
          </div>
          <Field
            v-slot="{ id, describedBy, invalid }"
            label="Description"
            :error="errors.description"
          >
            <Input
              :id="id"
              v-model="form.description"
              :disabled="!canManage"
              :invalid="invalid"
              :described-by="describedBy"
              data-test="project-description"
            />
          </Field>
          <Field
            v-slot="{ id, describedBy, invalid }"
            label="Time zone"
            help="Used for scheduled rollouts and times in the audit log."
            :error="errors.time_zone"
          >
            <Select
              :id="id"
              v-model="form.time_zone"
              :options="zones"
              :disabled="!canManage"
              :invalid="invalid"
              :described-by="describedBy"
              data-test="project-time-zone"
            />
          </Field>
        </div>
      </Card>

      <Card title="New flags">
        <NewFlagDefaults
          v-model:flag-key-format="form.flag_key_format"
          v-model:stale-after-days="form.stale_after_days"
          v-model:require-description="form.require_flag_description"
          v-model:require-owner="form.require_flag_owner"
          v-model:client-visible="form.client_visible_by_default"
          :disabled="!canManage"
          :errors="errors"
        />
      </Card>

      <div v-if="canManage" class="flex items-center gap-3" data-test="general-actions">
        <Button
          type="submit"
          variant="primary"
          :loading="form.processing"
          :disabled="!dirty"
          data-test="general-save"
          >Save changes</Button
        >
        <Button
          type="button"
          variant="ghost"
          :disabled="!dirty"
          data-test="general-reset"
          @click="reset"
          >Reset</Button
        >
        <span v-if="dirty" class="text-text/62 text-sm" data-test="general-unsaved"
          >Unsaved changes</span
        >
      </div>
    </form>
  </ProjectSettingsLayout>
</template>
