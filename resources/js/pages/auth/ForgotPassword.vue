<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
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
  <AuthLayout title="Reset your password" subtitle="Enter your email and we will send a link.">
    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <Field v-slot="{ id, describedBy, invalid }" label="Email" :error="form.errors.email">
        <Input
          :id="id"
          v-model="form.email"
          type="email"
          autocomplete="username"
          :invalid="invalid"
          :described-by="describedBy"
          data-test="forgot-email"
        />
      </Field>
      <Button
        type="submit"
        variant="primary"
        block
        :loading="form.processing"
        data-test="forgot-submit"
      >
        Send reset link
      </Button>
      <p class="text-muted text-center text-sm">
        <Link href="/signin" class="text-text underline" data-test="forgot-back"
          >Back to sign in</Link
        >
      </p>
    </form>
  </AuthLayout>
</template>
