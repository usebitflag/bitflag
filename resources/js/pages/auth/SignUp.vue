<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Button from '../../components/ui/Button.vue';
import Field from '../../components/ui/Field.vue';
import Input from '../../components/ui/Input.vue';
import PasswordChecklist from '../../features/auth/PasswordChecklist.vue';
import SocialButtons, { type SocialProviderOption } from '../../features/auth/SocialButtons.vue';
import AuthLayout from '../../layouts/AuthLayout.vue';

defineProps<{ providers: SocialProviderOption[] }>();

const form = useForm({ name: '', email: '', password: '' });
const common = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

// Ask the server whether the password is on the common list, once typing pauses. A failed check leaves the rule unmet only
// on the server's own answer, so the checklist never blocks sign-up by itself.
watch(
  () => form.password,
  (password) => {
    clearTimeout(timer);
    if (password.length === 0) {
      common.value = false;
      return;
    }
    timer = setTimeout(async () => {
      try {
        const response = await fetch('/password/check', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(
              document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
            ),
          },
          body: JSON.stringify({ password }),
        });
        if (response.ok) common.value = Boolean((await response.json()).common);
      } catch {
        common.value = false;
      }
    }, 300);
  },
);

function submit(): void {
  form.post('/signup', { onFinish: () => form.reset('password') });
}
</script>

<template>
  <Head title="Create your account" />
  <AuthLayout :width="400" title="Create your account" subtitle="Free plan. No card needed.">
    <template #aside>
      <span class="text-text/65 text-sm">
        Have an account?
        <Link href="/signin" class="text-primary-ink hover:underline" data-test="signup-signin-link"
          >Sign in</Link
        >
      </span>
    </template>
    <SocialButtons :providers="providers" />
    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <Field v-slot="{ id, describedBy, invalid }" label="Work email" :error="form.errors.email">
        <Input
          :id="id"
          v-model="form.email"
          type="email"
          autocomplete="username"
          :invalid="invalid"
          :described-by="describedBy"
          data-test="signup-email"
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
          data-test="signup-password"
        />
      </Field>
      <PasswordChecklist compact :password="form.password" :email="form.email" :common="common" />
      <Button
        type="submit"
        variant="primary"
        size="lg"
        block
        :loading="form.processing"
        data-test="signup-submit"
      >
        Create account
      </Button>
      <p class="text-text/55 text-xs">By continuing you agree to the Terms and Privacy Policy.</p>
    </form>
  </AuthLayout>
</template>
