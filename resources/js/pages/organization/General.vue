<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Banner from '../../components/ui/Banner.vue';
import Button from '../../components/ui/Button.vue';
import Field from '../../components/ui/Field.vue';
import InlineNotice from '../../components/ui/InlineNotice.vue';
import Input from '../../components/ui/Input.vue';
import OrgSettingsLayout from '../../layouts/OrgSettingsLayout.vue';

const props = defineProps<{
  settings: { name: string; slug: string; version: number };
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
  <OrgSettingsLayout title="Organization settings">
    <Banner v-if="!canManage" tone="neutral" label="Read-only." data-test="general-readonly">
      Only Org Owners can change these.
    </Banner>
    <form class="flex max-w-md flex-col gap-4" novalidate @submit.prevent="save">
      <InlineNotice
        v-if="form.errors.version"
        tone="warning"
        :title="form.errors.version"
        data-test="general-conflict"
      />
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
        label="URL name"
        help="Changing it breaks old links."
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
      <div v-if="canManage">
        <Button type="submit" variant="primary" :loading="form.processing" data-test="general-save"
          >Save</Button
        >
      </div>
    </form>
  </OrgSettingsLayout>
</template>
