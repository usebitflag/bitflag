<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AuthLayout from '../../layouts/AuthLayout.vue';

const form = useForm({ email: '', password: '' });

function submit(): void {
  form.post('/signin', { onFinish: () => form.reset('password') });
}
</script>

<template>
  <Head title="Sign in" />
  <AuthLayout title="Sign in to BitFlag">
    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <div class="flex flex-col gap-1">
        <label for="email" class="text-sm font-medium">Email</label>
        <input
          id="email"
          v-model="form.email"
          type="email"
          autocomplete="username"
          required
          class="border-control-border bg-surface h-9 rounded-md border px-3 text-sm"
          :aria-invalid="form.errors.email ? 'true' : undefined"
          :aria-describedby="form.errors.email ? 'email-error' : undefined"
          data-test="login-email"
        />
        <p
          v-if="form.errors.email"
          id="email-error"
          class="text-danger-text text-xs"
          data-test="login-error"
        >
          {{ form.errors.email }}
        </p>
      </div>

      <div class="flex flex-col gap-1">
        <label for="password" class="text-sm font-medium">Password</label>
        <input
          id="password"
          v-model="form.password"
          type="password"
          autocomplete="current-password"
          required
          class="border-control-border bg-surface h-9 rounded-md border px-3 text-sm"
          data-test="login-password"
        />
      </div>

      <button
        type="submit"
        class="bg-primary text-primary-foreground h-[34px] rounded-md text-sm font-medium"
        :disabled="form.processing"
        data-test="login-submit"
      >
        Sign in
      </button>
    </form>
  </AuthLayout>
</template>
