<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
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
    <div class="flex flex-col gap-4">
      <p class="text-sm" data-test="wrong-account-message">
        This invitation was sent to {{ invitedEmail }}. You're signed in as {{ signedInAs }}.
      </p>
      <div class="flex gap-2">
        <Button variant="primary" data-test="wrong-account-switch" @click="switchAccount">
          Sign out and continue
        </Button>
        <Link
          href="/"
          class="border-control-border inline-flex h-9 items-center rounded-md border px-3 text-sm"
          data-test="wrong-account-back"
        >
          Back to BitFlag
        </Link>
      </div>
    </div>
  </AuthLayout>
</template>
