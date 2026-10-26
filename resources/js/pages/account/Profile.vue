<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '../../components/ui/Button.vue';
import Field from '../../components/ui/Field.vue';
import InlineNotice from '../../components/ui/InlineNotice.vue';
import Input from '../../components/ui/Input.vue';
import SetPasswordNotice from '../../features/auth/SetPasswordNotice.vue';
import AccountLayout from '../../layouts/AccountLayout.vue';

const props = defineProps<{
  profile: {
    name: string;
    email: string;
    timezone: string;
    pendingEmail: string | null;
    emailVerified: boolean;
  };
  timezones: string[];
}>();

const details = useForm({ name: props.profile.name, timezone: props.profile.timezone });
const email = useForm({ email: '', password: '' });
const page = usePage<{ auth: { user: { hasPassword?: boolean } | null } }>();
const hasPassword = computed(() => page.props.auth.user?.hasPassword !== false);

function saveDetails(): void {
  details.put('/account/profile', { preserveScroll: true });
}

function changeEmail(): void {
  email.post('/account/email', {
    preserveScroll: true,
    onSuccess: () => email.reset(),
    onFinish: () => email.reset('password'),
  });
}
</script>

<template>
  <Head title="Profile" />
  <AccountLayout title="Profile">
    <SetPasswordNotice class="mb-4 max-w-md" />
    <form class="flex max-w-md flex-col gap-4" novalidate @submit.prevent="saveDetails">
      <h2 class="font-heading text-lg">Profile</h2>
      <Field v-slot="{ id, describedBy, invalid }" label="Name" :error="details.errors.name">
        <Input
          :id="id"
          v-model="details.name"
          autocomplete="name"
          :invalid="invalid"
          :described-by="describedBy"
          data-test="profile-name"
        />
      </Field>
      <label class="flex flex-col gap-1 text-sm">
        <span class="font-medium">Time zone</span>
        <select
          v-model="details.timezone"
          class="border-control-border bg-surface h-9 rounded-md border px-2"
          data-test="profile-timezone"
        >
          <option v-for="zone in timezones" :key="zone" :value="zone">{{ zone }}</option>
        </select>
        <span v-if="details.errors.timezone" class="text-danger-text text-xs" role="alert">{{
          details.errors.timezone
        }}</span>
      </label>
      <div>
        <Button
          type="submit"
          variant="primary"
          :loading="details.processing"
          data-test="profile-save"
          >Save</Button
        >
      </div>
    </form>

    <form class="flex max-w-md flex-col gap-4" novalidate @submit.prevent="changeEmail">
      <h2 class="font-heading text-lg">Email address</h2>
      <p class="text-sm" data-test="profile-email">
        {{ profile.email }}
        <span class="text-muted"
          >· {{ profile.emailVerified ? 'Confirmed' : 'Not confirmed' }}</span
        >
      </p>
      <InlineNotice
        v-if="profile.pendingEmail"
        tone="info"
        :title="`Waiting for ${profile.pendingEmail} to confirm`"
        data-test="profile-pending-email"
      >
        Your email stays {{ profile.email }} until the link we sent is opened.
      </InlineNotice>
      <Field
        v-slot="{ id, describedBy, invalid }"
        label="New email address"
        :error="email.errors.email"
      >
        <Input
          :id="id"
          v-model="email.email"
          type="email"
          :invalid="invalid"
          :described-by="describedBy"
          data-test="profile-new-email"
        />
      </Field>
      <Field
        v-slot="{ id, describedBy, invalid }"
        label="Your password"
        :error="email.errors.password"
      >
        <Input
          :id="id"
          v-model="email.password"
          type="password"
          autocomplete="current-password"
          :invalid="invalid"
          :described-by="describedBy"
          :disabled="!hasPassword"
          data-test="profile-email-password"
        />
      </Field>
      <div>
        <Button
          type="submit"
          :loading="email.processing"
          :disabled="!hasPassword"
          data-test="profile-email-submit"
          >Change email</Button
        >
      </div>
    </form>
  </AccountLayout>
</template>
