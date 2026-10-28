<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, toRef } from 'vue';
import Button from '../../components/ui/Button.vue';
import Field from '../../components/ui/Field.vue';
import InlineNotice from '../../components/ui/InlineNotice.vue';
import Textarea from '../../components/ui/Textarea.vue';
import { useEmailList } from '../../composables/useEmailList';
import OnboardingLayout from '../../layouts/OnboardingLayout.vue';

const props = defineProps<{
  organization: { name: string; slug: string };
  projectName: string | null;
  emailVerified: boolean;
  roles: Array<{ value: string; label: string }>;
}>();

const form = useForm({ emails: '', role: 'developer' });
const { count, invalid, overLimit } = useEmailList(toRef(form, 'emails'));

const summary = computed(() => {
  if (count.value === 0) return 'No one yet.';
  return `${count.value} ${count.value === 1 ? 'person' : 'people'} will be invited.`;
});

function submit(): void {
  form.post(`/onboarding/${props.organization.slug}/team`);
}

function skip(): void {
  router.post(`/onboarding/${props.organization.slug}/team/skip`);
}
</script>

<template>
  <Head title="Invite your team" />
  <OnboardingLayout
    step="team"
    title="Invite your team"
    :subtitle="projectName ? `They will join ${projectName}.` : undefined"
  >
    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <InlineNotice
        v-if="!emailVerified"
        tone="info"
        title="Invitations are sent after you confirm your email"
        data-test="team-unverified"
      />
      <Field
        v-slot="{ id, describedBy, invalid: isInvalid }"
        label="Email addresses"
        help="Separate them with commas or new lines."
        :error="form.errors.emails ?? (overLimit ? 'Invite up to 20 people at a time.' : undefined)"
      >
        <Textarea
          :id="id"
          v-model="form.emails"
          :invalid="isInvalid"
          :described-by="describedBy"
          data-test="team-emails"
        />
      </Field>
      <p class="text-muted text-xs" data-test="team-count">
        {{ summary }}
        <span v-if="invalid.length" class="text-danger-text" data-test="team-invalid">
          Not valid: {{ invalid.join(', ') }}
        </span>
      </p>
      <label class="flex flex-col gap-1 text-sm">
        <span class="font-medium">Role in the project</span>
        <select
          v-model="form.role"
          class="border-control-border bg-surface h-9 rounded-md border px-2"
          data-test="team-role"
        >
          <option v-for="role in roles" :key="role.value" :value="role.value">
            {{ role.label }}
          </option>
        </select>
      </label>
      <div class="flex gap-2">
        <Button
          type="submit"
          variant="primary"
          :loading="form.processing"
          :disabled="count === 0 || overLimit"
          data-test="team-send"
        >
          Send invitations
        </Button>
        <Button variant="ghost" data-test="team-skip" @click="skip">Skip for now</Button>
      </div>
    </form>
  </OnboardingLayout>
</template>
