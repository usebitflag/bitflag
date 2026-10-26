<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '../../components/ui/Button.vue';
import Field from '../../components/ui/Field.vue';
import Input from '../../components/ui/Input.vue';
import InlineNotice from '../../components/ui/InlineNotice.vue';
import SocialButtons, { type SocialProviderOption } from '../../features/auth/SocialButtons.vue';
import AuthLayout from '../../layouts/AuthLayout.vue';

defineProps<{ providers: SocialProviderOption[] }>();

const form = useForm({ email: '', password: '' });
// A failed sign-in with a provider comes back as a redirect with the message in the page's errors.
const page = usePage<{ errors?: Record<string, string> }>();
const error = computed(() => form.errors.email ?? page.props.errors?.email);

function submit(): void {
  form.post('/signin', { onFinish: () => form.reset('password') });
}
</script>

<template>
  <Head title="Sign in" />
  <AuthLayout title="Sign in" subtitle="to BitFlag">
    <SocialButtons :providers="providers" />
    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <InlineNotice v-if="error" tone="danger" regular :title="error" data-test="login-error" />
      <Field v-slot="{ id, describedBy }" label="Email">
        <Input
          :id="id"
          v-model="form.email"
          type="email"
          autocomplete="username"
          :invalid="Boolean(error)"
          :described-by="describedBy"
          data-test="login-email"
        />
      </Field>
      <Field label="Password">
        <template #aside>
          <Link
            href="/forgot-password"
            class="text-primary-ink text-sm font-medium hover:underline"
            data-test="login-forgot"
          >
            Forgot password?
          </Link>
        </template>
        <template #default="{ id, describedBy }">
          <Input
            :id="id"
            v-model="form.password"
            type="password"
            autocomplete="current-password"
            :invalid="Boolean(error)"
            :described-by="describedBy"
            data-test="login-password"
          />
        </template>
      </Field>
      <Button
        type="submit"
        variant="primary"
        size="lg"
        block
        class="mt-2"
        :loading="form.processing"
        data-test="login-submit"
      >
        Sign in
      </Button>
      <p class="text-text/70 text-[13.5px]">
        New to BitFlag?
        <Link href="/signup" class="text-primary-ink underline" data-test="login-signup"
          >Create an account</Link
        >
      </p>
    </form>
  </AuthLayout>
</template>
