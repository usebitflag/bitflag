<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Banner from '../../components/ui/Banner.vue';
import Button from '../../components/ui/Button.vue';
import Card from '../../components/ui/Card.vue';
import Field from '../../components/ui/Field.vue';
import InlineNotice from '../../components/ui/InlineNotice.vue';
import Input from '../../components/ui/Input.vue';
import OrgSettingsLayout from '../../layouts/OrgSettingsLayout.vue';
import DeleteOrganizationSection from '../../features/settings/DeleteOrganizationSection.vue';

const props = defineProps<{
  settings: {
    name: string;
    slug: string;
    version: number;
    status: string;
    deletionDueAt: number | null;
    paidSubscription: boolean;
  };
  canManage: boolean;
}>();

const form = useForm({
  name: props.settings.name,
  slug: props.settings.slug,
  version: props.settings.version,
});

function save(): void {
  form.put(`/${props.settings.slug}/settings/general`);
}
</script>

<template>
  <Head title="Organization settings" />
  <OrgSettingsLayout
    title="General"
    :description="`Organization details. These apply to every project in ${settings.name}.`"
  >
    <Banner v-if="!canManage" tone="neutral" label="Read-only." data-test="general-readonly">
      Only Org Owners can change these.
    </Banner>
    <form class="flex max-w-[820px] flex-col gap-4" novalidate @submit.prevent="save">
      <InlineNotice
        v-if="form.errors.version"
        tone="warning"
        :title="form.errors.version"
        data-test="general-conflict"
      />
      <Card title="Organization">
        <div class="grid grid-cols-2 items-start gap-3.5">
          <Field v-slot="{ id, describedBy, invalid }" label="Name" :error="form.errors.name">
            <Input
              :id="id"
              v-model="form.name"
              :disabled="!canManage"
              :invalid="invalid"
              :described-by="describedBy"
              data-test="general-name"
            />
          </Field>
          <Field
            v-slot="{ id, describedBy, invalid }"
            label="URL slug"
            :help="`${settings.slug} · changing it breaks old links`"
            :error="form.errors.slug"
          >
            <Input
              :id="id"
              v-model="form.slug"
              mono
              :disabled="!canManage"
              :invalid="invalid"
              :described-by="describedBy"
              data-test="general-slug"
            />
          </Field>
        </div>
        <div v-if="canManage" class="mt-4">
          <Button
            type="submit"
            variant="primary"
            :loading="form.processing"
            data-test="general-save"
            >Save</Button
          >
        </div>
      </Card>
    </form>
    <DeleteOrganizationSection
      v-if="canManage"
      :slug="settings.slug"
      :name="settings.name"
      :status="settings.status"
      :deletion-due-at="settings.deletionDueAt"
      :paid-subscription="settings.paidSubscription"
    />
  </OrgSettingsLayout>
</template>
