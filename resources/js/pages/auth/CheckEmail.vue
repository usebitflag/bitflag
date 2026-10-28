<script setup lang="ts">
import { PhEnvelopeSimpleOpen } from '@phosphor-icons/vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '../../components/ui/Button.vue';
import Field from '../../components/ui/Field.vue';
import IconTile from '../../components/ui/IconTile.vue';
import InlineNotice from '../../components/ui/InlineNotice.vue';
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
  <AuthLayout :width="400" title="Check your email">
    <template #before>
      <IconTile><PhEnvelopeSimpleOpen :size="22" aria-hidden="true" /></IconTile>
    </template>
    <template #subtitle>
      <span data-test="check-email-message">
        If <b class="text-text font-medium">{{ maskedEmail }}</b> can be used for a new account, we
        sent a link. It expires in 24 hours.
      </span>
    </template>
    <InlineNotice
      v-if="canContinue"
      plain
      title="You can set up your organization and first project now. Inviting people, creating keys and publishing unlock once the email is confirmed."
    />
    <div v-if="canContinue" class="flex flex-wrap items-center gap-2">
      <Button variant="primary" data-test="continue-setup" @click="router.visit('/onboarding')">
        Continue setup
      </Button>
      <Button variant="ghost" data-test="resend-email" @click="resend">Resend email</Button>
      <Button variant="ghost" data-test="use-different-email" @click="changing = !changing">
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
    <p v-if="!canContinue" class="text-text/65 text-sm">
      <Link href="/signin" class="text-primary-ink hover:underline">Sign in</Link> once you have
      confirmed your email.
    </p>
  </AuthLayout>
</template>
