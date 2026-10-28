<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { toRef } from 'vue';
import Button from '../../components/ui/Button.vue';
import Field from '../../components/ui/Field.vue';
import InlineNotice from '../../components/ui/InlineNotice.vue';
import Select from '../../components/ui/Select.vue';
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

const roleHelp: Record<string, string> = {
  owner: 'Everything, including members and deleting the project',
  publisher: 'Edit and publish anywhere, and approve reviews',
  developer: 'edit drafts, publish to unprotected environments',
  viewer: 'look, but not change anything',
};

const roleOptions = props.roles.map((role) => ({
  value: role.value,
  label: `${role.label} · ${roleHelp[role.value] ?? ''}`,
}));

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
    subtitle="Seats are unlimited on every plan."
    :width="460"
  >
    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <Field
        v-slot="{ id, describedBy, invalid: isInvalid }"
        label="Email addresses"
        :help="
          count === 0
            ? 'Separate them with commas or new lines.'
            : `${count} ${count === 1 ? 'invitation' : 'invitations'} ready`
        "
        :error="form.errors.emails ?? (overLimit ? 'Invite up to 20 people at a time.' : undefined)"
      >
        <Textarea
          :id="id"
          v-model="form.emails"
          :rows="2"
          class="min-h-[62px] font-mono !text-[13px]"
          :invalid="isInvalid"
          :described-by="describedBy"
          data-test="team-emails"
        />
      </Field>
      <p v-if="invalid.length" class="text-danger-text -mt-2 text-xs" data-test="team-invalid">
        Not valid: {{ invalid.join(', ') }}
      </p>
      <span class="sr-only" aria-live="polite" data-test="team-count">{{ count }} people</span>
      <Field v-slot="{ id, describedBy }" :label="`Their role in ${projectName ?? 'the project'}`">
        <Select
          :id="id"
          v-model="form.role"
          :options="roleOptions"
          :described-by="describedBy"
          data-test="team-role"
        />
      </Field>
      <InlineNotice
        v-if="!emailVerified"
        plain
        title="Invitations are sent after you confirm your email."
        data-test="team-unverified"
      />
      <div class="flex flex-wrap items-center gap-2">
        <Button
          type="submit"
          variant="primary"
          :loading="form.processing"
          :disabled="count === 0 || overLimit"
          data-test="team-send"
        >
          Send invites
        </Button>
        <Button variant="ghost" data-test="team-skip" @click="skip">Skip for now</Button>
      </div>
    </form>
  </OnboardingLayout>
</template>
