<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AuthLayout from '../../layouts/AuthLayout.vue';

const props = defineProps<{
  reason: 'expired' | 'revoked' | 'used' | 'unknown';
  organizationName: string | null;
  inviterName: string | null;
}>();

const message = computed(() => {
  if (props.reason === 'used') return 'This invitation was already used.';

  if (props.reason === 'expired' || props.reason === 'revoked') {
    const who = props.inviterName ?? 'the person who invited you';
    const where = props.organizationName ?? 'the organization';
    return props.reason === 'expired'
      ? `Invitations to ${where} last 7 days. Ask ${who} or another owner to send a new one.`
      : `This invitation was withdrawn. Ask ${who} or another owner to send a new one.`;
  }

  return 'This invitation link is not valid. Check the link in your email, or ask the person who invited you to send a new one.';
});
</script>

<template>
  <Head title="Invitation unavailable" />
  <AuthLayout title="This invitation can't be used">
    <div class="flex flex-col gap-4">
      <p class="text-sm" data-test="invitation-unavailable-message">{{ message }}</p>
      <Link
        href="/signin"
        class="text-text text-sm underline"
        data-test="invitation-unavailable-signin"
      >
        Sign in
      </Link>
    </div>
  </AuthLayout>
</template>
