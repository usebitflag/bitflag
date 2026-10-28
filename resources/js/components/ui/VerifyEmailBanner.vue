<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import Banner from './Banner.vue';
import Button from './Button.vue';

function resend(): void {
  router.post('/email/verification-notification', {}, { preserveScroll: true });
}
</script>

<template>
  <Banner tone="attention" label="Confirm your email." data-test="verify-email-banner">
    Confirm your email to invite people, create keys and publish.
    <Button size="sm" variant="ghost" data-test="verify-email-resend" @click="resend"
      >Resend</Button
    >
  </Banner>
</template>
