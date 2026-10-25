<script setup lang="ts">
import { PhArrowLeft } from '@phosphor-icons/vue';
import { Head, useForm } from '@inertiajs/vue3';
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
    <template #before>
      <Button
        variant="ghost"
        href="/signin"
        class="self-start !text-[13px]"
        data-test="challenge-back"
      >
        <template #icon><PhArrowLeft :size="16" aria-hidden="true" /></template>
        Back
      </Button>
    </template>
    <template #subtitle>
      {{
        useRecovery
          ? 'Enter one of your recovery codes.'
          : 'Open your authenticator app and enter the 6-digit code for BitFlag.'
      }}
      <span class="sr-only" data-test="challenge-account">Signing in as {{ maskedEmail }}</span>
    </template>
    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <OtpInput
        v-if="!useRecovery"
        v-model="form.code"
        :invalid="Boolean(form.errors.code)"
        @complete="submit"
      />
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
        class="text-danger-text -mt-2 text-xs"
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
        size="lg"
        block
        :loading="form.processing"
        data-test="challenge-submit"
      >
        Verify
      </Button>

      <button
        v-if="canUseRecovery"
        type="button"
        class="text-primary-ink self-start text-sm hover:underline"
        data-test="challenge-toggle"
        @click="toggle"
      >
        {{ useRecovery ? 'Use an authenticator code instead' : 'Use a recovery code instead' }}
      </button>
    </form>
  </AuthLayout>
</template>
