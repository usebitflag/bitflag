<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Button from '../../components/ui/Button.vue';
import Field from '../../components/ui/Field.vue';
import Input from '../../components/ui/Input.vue';
import InlineNotice from '../../components/ui/InlineNotice.vue';
import PasswordInput from '../../features/auth/PasswordInput.vue';
import AuthLayout from '../../layouts/AuthLayout.vue';

const form = useForm({ email: '', password: '' });

function submit(): void {
  form.post('/signin', { onFinish: () => form.reset('password') });
}
</script>

<template>
  <Head title="Sign in" />
  <AuthLayout title="Sign in" subtitle="to BitFlag">
    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <InlineNotice
        v-if="form.errors.email"
        tone="danger"
        regular
        :title="form.errors.email"
        data-test="login-error"
      />
      <Field v-slot="{ id, describedBy }" label="Email">
        <Input
          :id="id"
          v-model="form.email"
          type="email"
          autocomplete="username"
          :invalid="Boolean(form.errors.email)"
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
          <PasswordInput
            :id="id"
            v-model="form.password"
            autocomplete="current-password"
            :invalid="Boolean(form.errors.email)"
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
      <p v-if="!form.errors.email" class="text-text/60 mt-1 text-sm">
        Invited by a teammate? Open the link in your invitation email.
      </p>
    </form>
  </AuthLayout>
</template>
