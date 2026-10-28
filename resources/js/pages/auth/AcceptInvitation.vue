<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Button from '../../components/ui/Button.vue';
import Field from '../../components/ui/Field.vue';
import Input from '../../components/ui/Input.vue';
import PasswordChecklist from '../../features/auth/PasswordChecklist.vue';
import { sendJson } from '../../features/auth/sendJson';
import AuthLayout from '../../layouts/AuthLayout.vue';

interface InvitedProject {
  name: string;
  role: string;
}

const props = defineProps<{
  token: string;
  mode: 'create' | 'confirm';
  email: string;
  organizationName: string | null;
  inviterName: string | null;
  orgRole: 'owner' | 'member' | null;
  projects: InvitedProject[];
  expiresAt: number | null;
}>();

const form = useForm({ name: '', password: '' });
const common = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

// The server answers about the invited address under `email`, which is not a field of this form.
const accountExists = computed(() => (form.errors as Record<string, string | undefined>).email);
const organization = computed(() => props.organizationName ?? 'this organization');
const roleText = computed(() => (props.orgRole === 'owner' ? 'an Org Owner' : 'a member'));
const expires = computed(() =>
  props.expiresAt === null
    ? ''
    : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(
        new Date(props.expiresAt * 1000),
      ),
);

watch(
  () => form.password,
  (password) => {
    clearTimeout(timer);
    if (props.mode !== 'create' || password.length === 0) {
      common.value = false;
      return;
    }
    timer = setTimeout(async () => {
      try {
        common.value = Boolean(
          (await sendJson<{ common: boolean }>('POST', '/password/check', { password })).common,
        );
      } catch {
        common.value = false;
      }
    }, 300);
  },
);

function submit(): void {
  form.post(`/invitations/${props.token}/accept`, { onFinish: () => form.reset('password') });
}
</script>

<template>
  <Head :title="`Join ${organization}`" />
  <AuthLayout
    :title="`Join ${organization}`"
    :subtitle="`${inviterName ?? 'Someone'} invited you as ${roleText}.`"
  >
    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <section v-if="projects.length" aria-label="Projects" data-test="invitation-projects">
        <p class="text-muted text-xs">Projects</p>
        <ul class="mt-1 flex flex-col gap-1 text-sm">
          <li v-for="project in projects" :key="project.name">
            {{ project.name }} <span class="text-muted">· {{ project.role }}</span>
          </li>
        </ul>
      </section>
      <p v-if="expires" class="text-muted text-xs" data-test="invitation-expires">
        This invitation works until {{ expires }}.
      </p>

      <template v-if="mode === 'create'">
        <Field v-slot="{ id, describedBy }" label="Email">
          <Input
            :id="id"
            :model-value="email"
            type="email"
            disabled
            :described-by="describedBy"
            data-test="invitation-email"
          />
        </Field>
        <Field v-slot="{ id, describedBy, invalid }" label="Your name" :error="form.errors.name">
          <Input
            :id="id"
            v-model="form.name"
            autocomplete="name"
            :invalid="invalid"
            :described-by="describedBy"
            data-test="invitation-name"
          />
        </Field>
        <Field v-slot="{ id, describedBy, invalid }" label="Password" :error="form.errors.password">
          <Input
            :id="id"
            v-model="form.password"
            type="password"
            autocomplete="new-password"
            :invalid="invalid"
            :described-by="describedBy"
            data-test="invitation-password"
          />
        </Field>
        <PasswordChecklist :password="form.password" :email="email" :common="common" />
        <p
          v-if="accountExists"
          class="text-danger-text text-sm"
          role="alert"
          data-test="invitation-account-exists"
        >
          {{ accountExists }}
          <Link :href="`/signin`" class="underline">Sign in</Link>
        </p>
        <Button
          type="submit"
          variant="primary"
          block
          :loading="form.processing"
          data-test="invitation-accept"
        >
          Create account and join
        </Button>
        <p class="text-muted text-center text-sm">
          Already have an account?
          <Link href="/signin" class="text-text underline" data-test="invitation-signin"
            >Sign in to accept</Link
          >
        </p>
      </template>

      <template v-else>
        <p class="text-sm">You are signed in as {{ email }}.</p>
        <Button
          type="submit"
          variant="primary"
          block
          :loading="form.processing"
          data-test="invitation-accept"
        >
          Join {{ organization }}
        </Button>
      </template>
    </form>
  </AuthLayout>
</template>
