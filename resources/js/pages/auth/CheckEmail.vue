<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '../../components/ui/Button.vue';
import Field from '../../components/ui/Field.vue';
import Input from '../../components/ui/Input.vue';
import AuthLayout from '../../layouts/AuthLayout.vue';

defineProps<{ maskedEmail: string; canContinue: boolean }>();

const changing = ref(false);
const form = useForm({ email: '' });

function resend(): void {
  router.post('/email/verification-notification', {}, { preserveScroll: true });
}

function change(): void {
  form.post('/signup/change-email', {
    onSuccess: () => {
      changing.value = false;
      form.reset();
    },
  });
}
</script>

<template>
  <Head title="Check your email" />
  <AuthLayout title="Check your email">
    <div class="flex flex-col gap-4">
      <p class="text-sm" data-test="check-email-message">
        If {{ maskedEmail }} can be used for a new account, we sent a confirmation link. It works
        for 24 hours.
      </p>
      <p class="text-muted text-sm">
        You can keep setting up now. Inviting people, creating keys and publishing unlock after you
        confirm your email.
      </p>
      <Button
        v-if="canContinue"
        variant="primary"
        block
        data-test="continue-setup"
        @click="router.visit('/')"
      >
        Continue setup
      </Button>
      <div v-if="canContinue" class="flex flex-wrap gap-2">
        <Button size="sm" data-test="resend-email" @click="resend">Send the email again</Button>
        <Button
          size="sm"
          variant="ghost"
          data-test="use-different-email"
          @click="changing = !changing"
        >
          Use a different email
        </Button>
      </div>
      <form v-if="changing" class="flex flex-col gap-3" novalidate @submit.prevent="change">
        <Field v-slot="{ id, describedBy, invalid }" label="New email" :error="form.errors.email">
          <Input
            :id="id"
            v-model="form.email"
            type="email"
            :invalid="invalid"
            :described-by="describedBy"
            data-test="change-email-input"
          />
        </Field>
        <Button type="submit" :loading="form.processing" data-test="change-email-submit">
          Send to this address
        </Button>
      </form>
      <p v-if="!canContinue" class="text-muted text-sm">
        <Link href="/signin" class="text-text underline">Sign in</Link> once you have confirmed your
        email.
      </p>
    </div>
  </AuthLayout>
</template>
