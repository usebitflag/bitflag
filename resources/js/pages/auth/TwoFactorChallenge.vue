<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '../../components/ui/Button.vue';
import Checkbox from '../../components/ui/Checkbox.vue';
import Field from '../../components/ui/Field.vue';
import Input from '../../components/ui/Input.vue';
import OtpInput from '../../features/auth/OtpInput.vue';
import AuthLayout from '../../layouts/AuthLayout.vue';

defineProps<{ maskedEmail: string; canUseRecovery: boolean }>();

const form = useForm({ code: '', trust: false });
const useRecovery = ref(false);

function submit(): void {
  form.post('/two-factor-challenge', { onFinish: () => form.reset('code') });
}

function toggle(): void {
  useRecovery.value = !useRecovery.value;
  form.reset('code');
  form.clearErrors();
}
</script>

<template>
  <Head title="Two-factor code" />
  <AuthLayout title="Enter your code">
    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <p class="text-muted text-sm" data-test="challenge-account">
        Signing in as {{ maskedEmail }}
      </p>

      <template v-if="!useRecovery">
        <p class="text-sm">Enter the 6-digit code from your authenticator app.</p>
        <OtpInput v-model="form.code" :invalid="Boolean(form.errors.code)" @complete="submit" />
      </template>
      <Field v-else v-slot="{ id, describedBy, invalid }" label="Recovery code">
        <Input
          :id="id"
          v-model="form.code"
          mono
          placeholder="xxxxx-xxxxx"
          autocomplete="off"
          :invalid="invalid || Boolean(form.errors.code)"
          :described-by="describedBy"
          data-test="recovery-input"
        />
      </Field>

      <p
        v-if="form.errors.code"
        class="text-danger-text text-xs"
        role="alert"
        data-test="challenge-error"
      >
        {{ form.errors.code }}
      </p>

      <Checkbox
        v-model="form.trust"
        label="Trust this browser for 30 days"
        data-test="challenge-trust"
      />

      <Button
        type="submit"
        variant="primary"
        block
        :loading="form.processing"
        data-test="challenge-submit"
      >
        Continue
      </Button>

      <div class="flex justify-between text-sm">
        <button
          v-if="canUseRecovery"
          type="button"
          class="text-muted underline"
          data-test="challenge-toggle"
          @click="toggle"
        >
          {{ useRecovery ? 'Use an authenticator code instead' : 'Use a recovery code instead' }}
        </button>
        <Link href="/signin" class="text-muted underline" data-test="challenge-back"
          >Start again</Link
        >
      </div>
    </form>
  </AuthLayout>
</template>
