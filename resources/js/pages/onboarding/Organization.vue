<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Button from '../../components/ui/Button.vue';
import Field from '../../components/ui/Field.vue';
import Input from '../../components/ui/Input.vue';
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

const slugHelp = computed(() => {
  if (state.value === 'checking') return 'Checking…';
  if (state.value === 'available') return 'Available';
  return 'Changing it later breaks old links.';
});

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
    title="Create your organization"
    subtitle="Projects and people live inside an organization."
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
        label="URL name"
        :help="slugHelp"
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
        />
      </Field>
      <fieldset class="flex flex-col gap-2">
        <legend class="text-sm font-medium">
          Team size <span class="text-muted font-normal">(optional)</span>
        </legend>
        <div class="flex flex-wrap gap-2">
          <label
            v-for="size in teamSizes"
            :key="size.value"
            class="border-control-border flex cursor-pointer items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm"
          >
            <input
              v-model="form.team_size"
              type="radio"
              name="team_size"
              :value="size.value"
              :data-test="`team-size-${size.value}`"
            />
            {{ size.label }}
          </label>
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
