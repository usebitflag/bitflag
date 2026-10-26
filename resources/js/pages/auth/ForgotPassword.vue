<script setup lang="ts">
import { PhArrowLeft } from '@phosphor-icons/vue';
import { Head, useForm } from '@inertiajs/vue3';
import Button from '../../components/ui/Button.vue';
import Field from '../../components/ui/Field.vue';
import Input from '../../components/ui/Input.vue';
import AuthLayout from '../../layouts/AuthLayout.vue';

const form = useForm({ email: '' });

function submit(): void {
  form.post('/forgot-password');
}
</script>

<template>
  <Head title="Reset your password" />
  <AuthLayout
    title="Reset your password"
    subtitle="Enter your email and we'll send a link to set a new password."
  >
    <template #before>
      <Button
        variant="ghost"
        href="/signin"
        class="!text-primary-ink self-start !text-[13px]"
        data-test="forgot-back"
      >
        <template #icon><PhArrowLeft :size="16" aria-hidden="true" /></template>
        Back to sign in
      </Button>
    </template>
    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <Field v-slot="{ id, describedBy, invalid }" label="Email" :error="form.errors.email">
        <Input
          :id="id"
          v-model="form.email"
          type="email"
          autocomplete="username"
          placeholder="you@company.com"
          :invalid="invalid"
          :described-by="describedBy"
          data-test="forgot-email"
        />
      </Field>
      <Button
        type="submit"
        variant="primary"
        size="lg"
        block
        :loading="form.processing"
        data-test="forgot-submit"
      >
        Send reset link
      </Button>
    </form>
  </AuthLayout>
</template>
