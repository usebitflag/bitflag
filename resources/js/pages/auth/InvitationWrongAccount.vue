<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import Button from '../../components/ui/Button.vue';
import AuthLayout from '../../layouts/AuthLayout.vue';

const props = defineProps<{
  token: string;
  invitedEmail: string;
  signedInAs: string;
  organizationName: string | null;
}>();

function switchAccount(): void {
  router.post(`/invitations/${props.token}/switch`);
}
</script>

<template>
  <Head title="Wrong account" />
  <AuthLayout title="This invitation is for someone else">
    <template #subtitle>
      <span data-test="wrong-account-message">
        This invitation was sent to {{ invitedEmail }}. You're signed in as {{ signedInAs }}.
      </span>
    </template>
    <div class="flex gap-2">
      <Button variant="primary" data-test="wrong-account-switch" @click="switchAccount">
        Sign out and continue
      </Button>
      <Button href="/" data-test="wrong-account-back">Back to BitFlag</Button>
    </div>
  </AuthLayout>
</template>
