<script setup lang="ts">
import { PhCheck } from '@phosphor-icons/vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Button from '../../components/ui/Button.vue';
import Field from '../../components/ui/Field.vue';
import Input from '../../components/ui/Input.vue';
import SegmentedControl from '../../components/ui/SegmentedControl.vue';
import { useSlugAvailability } from '../../composables/useSlugAvailability';
import OnboardingLayout from '../../layouts/OnboardingLayout.vue';

defineProps<{ teamSizes: Array<{ value: string; label: string }> }>();

const form = useForm({ name: '', slug: '', team_size: '' as string });
// The URL name follows the organization's name until the person edits it.
const slugEdited = ref(false);
const slug = computed(() => form.slug);
const { state, reason } = useSlugAvailability(slug);

watch(
  () => form.name,
  (name) => {
    if (slugEdited.value) return;
    form.slug = name
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^[^a-z]+/, '')
      .replace(/^-+|-+$/g, '')
      .slice(0, 40);
  },
);

const host = window.location.host;

function submit(): void {
  form
    .transform((data) => ({ ...data, team_size: data.team_size === '' ? null : data.team_size }))
    .post('/onboarding/organization');
}
</script>

<template>
  <Head title="Create your organization" />
  <OnboardingLayout
    step="organization"
    title="Name your organization"
    subtitle="Organizations hold billing, members and projects. You can add more later."
  >
    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <Field
        v-slot="{ id, describedBy, invalid }"
        label="Organization name"
        :error="form.errors.name"
      >
        <Input
          :id="id"
          v-model="form.name"
          :invalid="invalid"
          :described-by="describedBy"
          data-test="org-name"
        />
      </Field>
      <Field
        v-slot="{ id, describedBy, invalid }"
        label="URL"
        :error="
          form.errors.slug ??
          (state === 'unavailable' ? (reason ?? 'That name is taken.') : undefined)
        "
      >
        <Input
          :id="id"
          v-model="form.slug"
          mono
          :invalid="invalid"
          :described-by="describedBy"
          data-test="org-slug"
          @input="slugEdited = true"
        >
          <template #prefix
            ><span class="font-mono text-[13px]">{{ host }}/</span></template
          >
          <template #suffix>
            <span v-if="state === 'checking'" class="text-xs" aria-live="polite">Checking…</span>
            <span
              v-else-if="state === 'available'"
              class="text-primary-ink flex items-center gap-1 text-xs"
              aria-live="polite"
              ><PhCheck :size="12" aria-hidden="true" />Available</span
            >
          </template>
        </Input>
      </Field>
      <fieldset class="flex flex-col gap-[5px]">
        <legend class="text-text/70 mb-[5px] text-sm font-medium">Team size · optional</legend>
        <div>
          <SegmentedControl
            v-model="form.team_size"
            variant="ring"
            label="Team size"
            :options="teamSizes"
          />
        </div>
      </fieldset>
      <Button
        type="submit"
        variant="primary"
        block
        :loading="form.processing"
        :disabled="state === 'unavailable'"
        data-test="org-continue"
      >
        Continue
      </Button>
    </form>
  </OnboardingLayout>
</template>
